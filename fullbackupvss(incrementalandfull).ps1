#Requires -RunAsAdministrator
<#
.SYNOPSIS
    VSS backup: Full first, then Incremental + Archive (relative to the Full).

.DESCRIPTION
    First run (or -ForceFull)  -> Full backup (baseline, never modified afterwards)
    Following runs             -> Incremental + Archive

    Layout on the destination (derived from $DestinationDrive, e.g. F:\):
      F:\Full\C\scripts\                              complete baseline
      F:\Incremental\C\scripts\                       new + changed files (current version) vs. Full
      F:\Archive\<timestamp>\Changed\C\scripts\       Full version of changed files
      F:\Archive\<timestamp>\Deleted\C\scripts\       deleted files
      F:\Archive\<timestamp>\manifest_<source>.csv    New/Changed/Deleted status per file
      F:\Superseded\<timestamp>\                      old Full/Incremental after -ForceFull
      F:\Logs\                                        transcripts and robocopy output

    All data is read from a VSS snapshot (consistent, works with open files).
    Progress bars are shown for scanning, copying and archiving.

.PARAMETER ForceFull
    Force a new Full. The old Full + Incremental are moved to Superseded\<timestamp>\.

.PARAMETER ArchiveRetentionDays
    Delete Archive folders older than N days. 0 = never clean up (default).
#>
[CmdletBinding()]
param(
    [switch]$ForceFull,
    [int]$ArchiveRetentionDays = 0
)

$ErrorActionPreference = 'Stop'

# ----------------------------- Configuration -----------------------------
$SourceDirs = @(
    "C:\xampp3\htdocs"
    #"C:\users\maev"
    "C:\scripts"
    #"C:\windows10\pri\Mijnbestanden"
)

$DestinationDrive = "F"                              # the only destination setting
$DestinationBase  = "${DestinationDrive}:\"          # destination is the root of the drive: F:\

# Everything else is derived from the destination, nothing is hard-coded
$FullRoot   = Join-Path $DestinationBase 'Full'
$IncRoot    = Join-Path $DestinationBase 'Incremental'
$ArchRoot   = Join-Path $DestinationBase 'Archive'
$SuperRoot  = Join-Path $DestinationBase 'Superseded'
$LogRoot    = Join-Path $DestinationBase 'Logs'
$VssLinkFmt = Join-Path $env:TEMP 'vss_link_{0}'     # {0} = source drive letter

# Folders to exclude (original paths, not snapshot paths). Excluded folders are skipped
# in Full, Incremental and Archive, and are ignored in the Full-vs-source comparison.
$ExcludeDirs = @(
    "C:\users\maev\appdata"
)
# -------------------------------------------------------------------------

$Timestamp  = Get-Date -Format 'yyyyMMdd_HHmmss'
$ArchiveRun = Join-Path $ArchRoot $Timestamp

# ------------------------------ Progress ---------------------------------

$script:Sw = [System.Diagnostics.Stopwatch]::StartNew()

function Show-Progress {
    param(
        [int]$Id, [string]$Activity, [string]$Status,
        [int]$Percent = -1, [int]$ParentId = -1, [switch]$Force
    )
    # Throttle updates so the console does not slow the copy down
    if (-not $Force -and $script:Sw.ElapsedMilliseconds -lt 150) { return }
    $script:Sw.Restart()
    $p = @{ Id = $Id; Activity = $Activity; Status = $Status }
    if ($Percent -ge 0) { $p.PercentComplete = [math]::Min(100, $Percent) }
    if ($ParentId -ge 0) { $p.ParentId = $ParentId }
    Write-Progress @p
}

function Stop-Progress([int]$Id) {
    Write-Progress -Id $Id -Activity 'done' -Completed
}

function Format-Size([double]$Bytes) {
    if ($Bytes -ge 1GB) { return ('{0:N2} GB' -f ($Bytes / 1GB)) }
    if ($Bytes -ge 1MB) { return ('{0:N1} MB' -f ($Bytes / 1MB)) }
    return ('{0:N0} KB' -f ($Bytes / 1KB))
}

# ------------------------------ Functions --------------------------------

function Get-BackupName([string]$Path) {
    # C:\users\maev -> C_users_maev
    return ($Path -replace '[:\\\/]+', '_').Trim('_')
}

function Get-HardPathName([string]$Path) {
    # C:\scripts -> C\scripts (a colon is not allowed in a folder name)
    return ($Path -replace '^([A-Za-z]):', '$1').Trim('\')
}

function New-VssSnapshot([string]$VolumeRoot) {
    Write-Host "Creating VSS snapshot of $VolumeRoot ..."
    $res = ([WMICLASS]"root\cimv2:Win32_ShadowCopy").Create($VolumeRoot, "ClientAccessible")
    if ($res.ReturnValue -ne 0) {
        throw "VSS snapshot failed for $VolumeRoot (ReturnValue $($res.ReturnValue))"
    }
    $shadow = Get-CimInstance Win32_ShadowCopy | Where-Object { $_.ID -eq $res.ShadowID }

    $link = $VssLinkFmt -f $VolumeRoot.Substring(0, 1)
    if (Test-Path -LiteralPath $link) {
        # leftover from a crashed run: rmdir only removes the link, not the content
        & cmd.exe /c "rmdir `"$link`"" | Out-Null
    }
    $device = $shadow.DeviceObject + '\'
    $out = & cmd.exe /c "mklink /d `"$link`" `"$device`"" 2>&1
    if (-not (Test-Path -LiteralPath $link)) { throw "Creating symlink to snapshot failed: $out" }
    return [pscustomobject]@{ Shadow = $shadow; Link = $link; Volume = $VolumeRoot }
}

function Remove-VssSnapshot($Snap) {
    try {
        if (Test-Path -LiteralPath $Snap.Link) {
            & cmd.exe /c "rmdir `"$($Snap.Link)`"" | Out-Null
        }
    } catch { Write-Warning "Removing link failed: $_" }
    try {
        $Snap.Shadow | Remove-CimInstance
        Write-Host "VSS snapshot of $($Snap.Volume) removed."
    } catch { Write-Warning "Removing snapshot failed: $_" }
}

function Get-RelativeExcludes([string]$OriginalSrc) {
    # Converts the absolute exclude paths into paths relative to this source,
    # e.g. source C:\users\maev + exclude C:\users\maev\appdata -> "appdata"
    $base = $OriginalSrc.TrimEnd('\') + '\'
    $out = @()
    foreach ($e in $ExcludeDirs) {
        $x = $e.TrimEnd('\')
        if ((($x + '\').StartsWith($base, [StringComparison]::OrdinalIgnoreCase)) -and ($x.Length -ge $base.Length)) {
            $out += $x.Substring($base.Length)
        }
    }
    return $out    # callers wrap the result in @() so 0 or 1 items still become an array
}

function Get-FileMap([string]$Root, [string]$Label, [int]$ParentId, [string[]]$ExcludeRel = @()) {
    # relative path -> FileInfo (hashtable is case-insensitive by default)
    # Excluded folders (and junctions/symlinks) are pruned, never enumerated.
    $map = @{}
    if (-not (Test-Path -LiteralPath $Root)) { return $map }
    $rootTrim = $Root.TrimEnd('\')
    $len = $rootTrim.Length + 1
    $excl = @($ExcludeRel | ForEach-Object { (Join-Path $rootTrim $_).TrimEnd('\') })

    $stack = New-Object System.Collections.Generic.Stack[string]
    $stack.Push($rootTrim)
    $n = 0
    while ($stack.Count -gt 0) {
        $di = New-Object System.IO.DirectoryInfo($stack.Pop())
        try { $subs = $di.GetDirectories() } catch { $subs = @() }
        foreach ($s in $subs) {
            if ($s.Attributes -band [System.IO.FileAttributes]::ReparsePoint) { continue }  # same as robocopy /XJ
            if ($excl -contains $s.FullName.TrimEnd('\')) { continue }                       # excluded folder
            $stack.Push($s.FullName)
        }
        try { $files = $di.GetFiles() } catch { $files = @() }
        foreach ($f in $files) {
            $map[$f.FullName.Substring($len)] = $f
            $n++
            if (($n % 200) -eq 0) {
                Show-Progress -Id 3 -ParentId $ParentId -Activity "Scanning $Label" -Status "$n files found"
            }
        }
    }
    Stop-Progress 3
    return $map
}

function Test-FileChanged($A, $B) {
    if ($A.Length -ne $B.Length) { return $true }
    # 2 second tolerance because of timestamp rounding
    return ([math]::Abs(($A.LastWriteTimeUtc - $B.LastWriteTimeUtc).TotalSeconds) -gt 2)
}

function Copy-FileSafe([string]$From, [string]$To) {
    $dir = Split-Path -Parent $To
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    if (Test-Path -LiteralPath $To) { (Get-Item -LiteralPath $To -Force).Attributes = 'Normal' }
    [System.IO.File]::Copy($From, $To, $true)   # preserves LastWriteTime
}

function Copy-WithProgress {
    # $Items: array of @{ From=...; To=...; Size=... }
    param($Items, [string]$Activity, [int]$ParentId)
    # Items are hashtables, so Measure-Object -Property would not work; sum manually
    $totalBytes = [long]0
    foreach ($it in $Items) { $totalBytes += [long]$it.Size }
    if ($totalBytes -le 0) { $totalBytes = 1 }
    $doneBytes = 0; $doneFiles = 0; $totalFiles = $Items.Count
    foreach ($it in $Items) {
        Copy-FileSafe $it.From $it.To
        $doneBytes += $it.Size
        $doneFiles++
        $pct = [int](($doneBytes / $totalBytes) * 100)
        Show-Progress -Id 2 -ParentId $ParentId -Activity $Activity `
            -Status ("{0}/{1} files - {2} of {3}" -f $doneFiles, $totalFiles, (Format-Size $doneBytes), (Format-Size $totalBytes)) `
            -Percent $pct
    }
    Stop-Progress 2
}

function Remove-EmptyDirs([string]$Root) {
    if (-not (Test-Path -LiteralPath $Root)) { return }
    Get-ChildItem -LiteralPath $Root -Directory -Recurse -Force |
        Sort-Object { $_.FullName.Length } -Descending |
        Where-Object { -not (Get-ChildItem -LiteralPath $_.FullName -Force) } |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force }
}

function Invoke-FullBackup([string]$Src, [string]$Name, [string]$OriginalPath, [int]$ParentId) {
    $dst    = Join-Path $FullRoot $Name
    $marker = Join-Path $dst '.full_complete'
    Write-Host "`n=== FULL backup: $Src -> $dst ===" -ForegroundColor Cyan

    $excludeRel = @(Get-RelativeExcludes $OriginalPath)
    if ($excludeRel.Count -gt 0) { Write-Host ("Excluding: " + ($excludeRel -join ', ')) }

    # Pre-count so the progress bar has a total (excluded folders are not scanned)
    $cmap = Get-FileMap $Src 'source for Full backup' $ParentId $excludeRel
    $total = $cmap.Count
    $totalBytes = [long]0
    foreach ($f in $cmap.Values) { $totalBytes += $f.Length }
    $cmap = $null
    if ($total -eq 0) { $total = 1 }

    New-Item -ItemType Directory -Path $dst -Force | Out-Null
    $log = Join-Path $LogRoot "robocopy_full_$(Get-BackupName $Name)_$Timestamp.log"

    # One output line per copied file -> drives the progress bar
    $count = 0
    $roboArgs = @($Src, $dst, '/MIR', '/COPY:DAT', '/DCOPY:DAT', '/R:1', '/W:1', '/XJ',
                  '/NP', '/NC', '/NS', '/NDL', '/NJH', '/NJS', '/FP', '/XF', '.full_complete')
    if ($excludeRel.Count -gt 0) {
        $roboArgs += '/XD'
        foreach ($x in $excludeRel) { $roboArgs += (Join-Path $Src $x) }
    }
    & robocopy.exe @roboArgs |
        ForEach-Object {
            if ($_ -match '\S') {
                Add-Content -LiteralPath $log -Value $_
                $count++
                Show-Progress -Id 2 -ParentId $ParentId -Activity "Full backup: copying" `
                    -Status ("{0}/{1} files ({2} total)" -f $count, $total, (Format-Size $totalBytes)) `
                    -Percent ([int](($count / $total) * 100))
            }
        }
    $rc = $LASTEXITCODE
    Stop-Progress 2
    if ($rc -ge 8) { throw "Robocopy Full failed for $Src (exit $rc). See $log" }

    @{ Source = $Src; Completed = (Get-Date).ToString('s') } | ConvertTo-Json | Set-Content -LiteralPath $marker -Encoding UTF8
    (Get-Item -LiteralPath $marker -Force).Attributes = 'Hidden'
    Write-Host "Full backup completed." -ForegroundColor Green
}

function Invoke-IncrementalBackup([string]$Src, [string]$Name, [string]$OriginalPath, [int]$ParentId) {
    $fullDir = Join-Path $FullRoot $Name
    $incDir  = Join-Path $IncRoot  $Name
    $hard    = Get-HardPathName $OriginalPath                              # e.g. C\scripts
    $archDeletedDir = Join-Path (Join-Path $ArchiveRun 'Deleted') $hard    # ...\Archive\<ts>\Deleted\C\scripts
    $archChangedDir = Join-Path (Join-Path $ArchiveRun 'Changed') $hard    # ...\Archive\<ts>\Changed\C\scripts
    Write-Host "`n=== INCREMENTAL + ARCHIVE: $Src ===" -ForegroundColor Cyan

    $excludeRel = @(Get-RelativeExcludes $OriginalPath)
    if ($excludeRel.Count -gt 0) { Write-Host ("Excluding: " + ($excludeRel -join ', ')) }

    $srcMap  = Get-FileMap $Src     'source (VSS snapshot)' $ParentId $excludeRel
    $fullMap = Get-FileMap $fullDir 'Full backup'           $ParentId $excludeRel
    $fullMap.Remove('.full_complete')

    $new = @(); $changed = @(); $deleted = @()
    $i = 0; $n = $srcMap.Count
    foreach ($rel in @($srcMap.Keys)) {
        $i++
        if (-not $fullMap.ContainsKey($rel))                   { $new     += $rel }
        elseif (Test-FileChanged $srcMap[$rel] $fullMap[$rel]) { $changed += $rel }
        if (($i % 500) -eq 0) {
            Show-Progress -Id 3 -ParentId $ParentId -Activity "Comparing source with Full" `
                -Status "$i/$n files" -Percent ([int](($i / [math]::Max(1, $n)) * 100))
        }
    }
    foreach ($rel in $fullMap.Keys) {
        if (-not $srcMap.ContainsKey($rel)) { $deleted += $rel }
    }
    Stop-Progress 3

    Write-Host ("New: {0}  Changed: {1}  Deleted: {2}" -f $new.Count, $changed.Count, $deleted.Count)

    # --- Incremental: current version of new + changed files ---
    $wanted = @{}
    foreach ($rel in ($new + $changed)) { $wanted[$rel] = $true }

    $incMap = Get-FileMap $incDir 'Incremental' $ParentId

    $toCopy = New-Object System.Collections.Generic.List[object]
    foreach ($rel in $wanted.Keys) {
        $s = $srcMap[$rel]
        if ($incMap.ContainsKey($rel) -and -not (Test-FileChanged $s $incMap[$rel])) { continue }  # already up to date
        $toCopy.Add(@{ From = $s.FullName; To = (Join-Path $incDir $rel); Size = $s.Length })
    }
    if ($toCopy.Count -gt 0) {
        Copy-WithProgress -Items $toCopy -Activity "Incremental: copying new/changed files" -ParentId $ParentId
    }

    # Remove files from Incremental that no longer differ from Full (or are gone)
    foreach ($rel in @($incMap.Keys)) {
        if (-not $wanted.ContainsKey($rel)) {
            Remove-Item -LiteralPath $incMap[$rel].FullName -Force
        }
    }
    Remove-EmptyDirs $incDir

    # --- Archive: Full version of changed files + deleted files ---
    $archItems = New-Object System.Collections.Generic.List[object]
    foreach ($rel in $changed) {
        $archItems.Add(@{ From = $fullMap[$rel].FullName; To = (Join-Path $archChangedDir $rel); Size = $fullMap[$rel].Length })
    }
    foreach ($rel in $deleted) {
        $archItems.Add(@{ From = $fullMap[$rel].FullName; To = (Join-Path $archDeletedDir $rel); Size = $fullMap[$rel].Length })
    }
    if ($archItems.Count -gt 0) {
        Copy-WithProgress -Items $archItems -Activity "Archive: saving Full versions of changed/deleted files" -ParentId $ParentId
    }

    $manifest = New-Object System.Collections.Generic.List[object]
    foreach ($rel in $changed) {
        $manifest.Add([pscustomobject]@{ Status='Changed'; Path=$rel; FullSize=$fullMap[$rel].Length; FullModified=$fullMap[$rel].LastWriteTime; CurrentSize=$srcMap[$rel].Length; CurrentModified=$srcMap[$rel].LastWriteTime })
    }
    foreach ($rel in $deleted) {
        $manifest.Add([pscustomobject]@{ Status='Deleted'; Path=$rel; FullSize=$fullMap[$rel].Length; FullModified=$fullMap[$rel].LastWriteTime; CurrentSize=$null; CurrentModified=$null })
    }
    foreach ($rel in $new) {
        $manifest.Add([pscustomobject]@{ Status='New'; Path=$rel; FullSize=$null; FullModified=$null; CurrentSize=$srcMap[$rel].Length; CurrentModified=$srcMap[$rel].LastWriteTime })
    }

    if ($manifest.Count -gt 0) {
        New-Item -ItemType Directory -Path $ArchiveRun -Force | Out-Null
        $manifest | Sort-Object Status, Path |
            Export-Csv -LiteralPath (Join-Path $ArchiveRun "manifest_$(Get-BackupName $Name).csv") -NoTypeInformation -Encoding UTF8 -Delimiter ';'
    }
    Write-Host "Incremental + Archive completed." -ForegroundColor Green
}

# --------------------------------- Main ----------------------------------

if (-not (Test-Path "${DestinationDrive}:\")) { throw "Destination drive ${DestinationDrive}: not found." }
foreach ($d in $DestinationBase, $FullRoot, $IncRoot, $ArchRoot, $LogRoot) {
    if (Test-Path -LiteralPath $d) { continue }     # also covers a drive root such as F:\
    try { New-Item -ItemType Directory -Path $d -Force | Out-Null }
    catch { throw "Cannot create destination folder '$d': $_" }
}

Start-Transcript -Path (Join-Path $LogRoot "backup_$Timestamp.log") | Out-Null
$snapshots = @{}
try {
    $validSources = @($SourceDirs | Where-Object {
        if (Test-Path -LiteralPath $_) { $true } else { Write-Warning "Source does not exist, skipped: $_"; $false }
    })
    if ($validSources.Count -eq 0) { throw "No valid source directories." }

    # One snapshot per volume
    Show-Progress -Id 1 -Activity "VSS backup" -Status "Creating VSS snapshot(s)" -Percent 0 -Force
    foreach ($src in $validSources) {
        $root = [System.IO.Path]::GetPathRoot($src)
        if (-not $snapshots.ContainsKey($root)) { $snapshots[$root] = New-VssSnapshot $root }
    }

    $idx = 0
    foreach ($src in $validSources) {
        $root    = [System.IO.Path]::GetPathRoot($src)
        $rel     = $src.Substring($root.Length)
        $snapSrc = if ($rel) { Join-Path $snapshots[$root].Link $rel } else { $snapshots[$root].Link }
        $name    = Get-HardPathName $src          # C:\scripts -> C\scripts
        $marker  = Join-Path (Join-Path $FullRoot $name) '.full_complete'

        # Migrate legacy flat folders (C_scripts) to the C\scripts layout
        $legacy = Get-BackupName $src
        foreach ($base in $FullRoot, $IncRoot) {
            $old = Join-Path $base $legacy
            $new = Join-Path $base $name
            if ((Test-Path -LiteralPath $old) -and -not (Test-Path -LiteralPath $new)) {
                New-Item -ItemType Directory -Path (Split-Path -Parent $new) -Force | Out-Null
                Move-Item -LiteralPath $old -Destination $new
                Write-Host "Migrated $old -> $new"
            }
        }

        Show-Progress -Id 1 -Activity "VSS backup" `
            -Status ("Source {0}/{1}: {2}" -f ($idx + 1), $validSources.Count, $src) `
            -Percent ([int](($idx / $validSources.Count) * 100)) -Force

        if ($ForceFull -and (Test-Path -LiteralPath $marker)) {
            $sup = Join-Path (Join-Path $SuperRoot $Timestamp) $name
            New-Item -ItemType Directory -Path $sup -Force | Out-Null
            Move-Item -LiteralPath (Join-Path $FullRoot $name) -Destination (Join-Path $sup 'Full')
            if (Test-Path -LiteralPath (Join-Path $IncRoot $name)) {
                Move-Item -LiteralPath (Join-Path $IncRoot $name) -Destination (Join-Path $sup 'Incremental')
            }
            Write-Host "Old Full/Incremental of $name moved to $sup"
        }

        if (-not (Test-Path -LiteralPath $marker)) {
            Invoke-FullBackup -Src $snapSrc -Name $name -OriginalPath $src -ParentId 1
        }
        else {
            Invoke-IncrementalBackup -Src $snapSrc -Name $name -OriginalPath $src -ParentId 1
        }
        $idx++
    }
    Show-Progress -Id 1 -Activity "VSS backup" -Status "Finished" -Percent 100 -Force

    # Archive retention
    if ($ArchiveRetentionDays -gt 0) {
        $cutoff = (Get-Date).AddDays(-$ArchiveRetentionDays)
        Get-ChildItem -LiteralPath $ArchRoot -Directory |
            Where-Object { $_.CreationTime -lt $cutoff } |
            ForEach-Object { Write-Host "Removing old archive: $($_.Name)"; Remove-Item -LiteralPath $_.FullName -Recurse -Force }
    }

    Write-Host "`nBackup completed successfully." -ForegroundColor Green
}
catch {
    Write-Error "BACKUP FAILED: $_"
    exit 1
}
finally {
    Stop-Progress 1
    foreach ($s in $snapshots.Values) { Remove-VssSnapshot $s }
    Stop-Transcript | Out-Null
}
