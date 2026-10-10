<#
.SYNOPSIS
    VSS backup: morning FULL, midday INCREMENTAL + ARCHIVE in one run.

.DESCRIPTION
    Modes:
      FULL        - Full mirror of every source to F:\fullbackup\<PC>\<Drive>\<path>.
      INCREMENTAL - Fast pass. Uses a per-disk, per-source change manifest
                    to only touch files whose size or LastWriteTime differ.
      BOTH        - Runs INCREMENTAL first, then ARCHIVE. Intended for the
                    midday run, so the changed/deleted files end up in the
                    _changed archive without a separate evening task.

    Time-window policy:
      FULL         normally allowed only between 00:00 and 13:30.
                   EXCEPTION: if today's FULL has not run yet, FULL is
                   allowed at any time (catch-up run).
      INCREMENTAL  allowed only from 14:00 onwards.
      ARCHIVE      allowed only from 14:00 onwards.
      BOTH         allowed only from 14:00 onwards.
      (13:30 - 14:00 is a no-go window for every mode.)

    Archive layout (created/updated by ARCHIVE / BOTH):
        F:\fullbackup\_changed\<PC>\
            01-aangemaakt\<Drive>\<path>\<file>
            02-gewijzigd\<Drive>\<path>\<file>
            02-gewijzigd\<Drive>\<path>\<file>.vorige-versie
            03-verwijderd\<Drive>\<path>\<file>
            .previous\<Drive>\<path>\<file>       (internal helper)

    - VSS snapshot per source volume, so locked/open files copy fine.
    - One physical disk per day of the month (01..31), rotated by hand.
    - Versioning = true mirror (overwrite + delete) in the SAME destination
      folder structure, PLUS the _changed archive.
    - Pure PowerShell copy engine. No robocopy.

    Manifests live on the backup disk:
        F:\fullbackup\.state\<PC>\<Drive>_<safe-source>.tsv
        F:\fullbackup\.state\<PC>\_changed_<Drive>_<safe-source>.tsv

    FULL-done marker (per day, per PC, on backup disk):
        F:\fullbackup\.state\<PC>\full-done_<yyyy-MM-dd>.marker

.NOTES
    Run as Administrator. F: must be NTFS.

    Typical daily schedule:
        07:00   .\backup.ps1 -Mode FULL
        13:00   .\backup.ps1 -Mode BOTH
#>

[CmdletBinding()]
param(
    [ValidateSet('FULL','INCREMENTAL','ARCHIVE','BOTH')]
    [string]$Mode = 'FULL'
)

# ============================================================
# CONFIG
# ============================================================

$SourceDirs = @(
    "C:\xampp3\htdocs"
    "C:\users\maev"
    "C:\scripts"
    "C:\windows10\pri\Mijnbestanden"
)

$DestinationBase  = "F:\fullbackup"
$DestinationDrive = "F"

$IncludeComputerName = $true

$MirrorDeletes = $true

# Monthly rotation: 31 disks, one per day of the month.
$VerifyDiskLabel = $false
$ExpectedVolumeLabelFormat = "BK-DAY-{0:D2}"

$CopyRetries = 3
$CopyRetryWaitSec = 5

$ExcludeDirs  = @('$RECYCLE.BIN', 'System Volume Information')
$ExcludeFiles = @('pagefile.sys', 'hiberfil.sys', 'swapfile.sys')

$LogDir = "C:\BackupLogs"

$UseUnicodeProgressBar = $true

# --- Names of the three subfolders of the _changed archive ---
$ChangedRootName     = "_changed"
$ChangedCreatedName  = "01-aangemaakt"
$ChangedModifiedName = "02-gewijzigd"
$ChangedDeletedName  = "03-verwijderd"

# --- Time-window policy ---
# FULL is allowed only between 00:00 and 13:30.
# INCREMENTAL / ARCHIVE / BOTH are allowed only from 14:00 onwards.
# (13:30 - 14:00 is a no-go window.)
$FullBackupCutoff    = [TimeSpan]::FromHours(13) + [TimeSpan]::FromMinutes(30)  # 13:30
$IncrementalEarliest = [TimeSpan]::FromHours(14)                                 # 14:00

# Allow FULL outside its normal time window if no FULL has run yet today.
# A per-day marker file is written on the destination after a successful FULL.
$AllowFullIfNotYetRunToday = $true

# ============================================================

$ErrorActionPreference = 'Stop'

# --- Admin check ---
$isAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) { Write-Host "ERROR: Run as Administrator." -ForegroundColor Red; exit 1 }

# --- Logging ---
if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
$RunStamp = "{0:yyyy-MM-dd_HH-mm-ss}" -f (Get-Date)
$LogFile  = Join-Path $LogDir ("VssBackup_{0}_{1}.log" -f $Mode, $RunStamp)

$script:LogWriter = New-Object IO.StreamWriter($LogFile, $true, (New-Object Text.UTF8Encoding($false)))
$script:ProgressActive = $false

function Write-Log {
    param([string]$Message, [string]$Level = "INFO")
    $line = "{0} [{1}] {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Level, $Message
    if ($script:LogWriter) { $script:LogWriter.WriteLine($line); $script:LogWriter.Flush() }
    if (-not $script:ProgressActive) {
        Write-Host $line
    } else {
        [Console]::WriteLine($line)
    }
}
function Close-Log {
    if ($script:LogWriter) { try { $script:LogWriter.Dispose() } catch {}; $script:LogWriter = $null }
}

function Format-Size {
    param([int64]$Bytes)
    if ($Bytes -ge 1TB) { return "{0:N2} TB" -f ($Bytes / 1TB) }
    if ($Bytes -ge 1GB) { return "{0:N2} GB" -f ($Bytes / 1GB) }
    if ($Bytes -ge 1MB) { return "{0:N2} MB" -f ($Bytes / 1MB) }
    if ($Bytes -ge 1KB) { return "{0:N2} KB" -f ($Bytes / 1KB) }
    return "$Bytes B"
}
function Format-Duration {
    param([double]$Seconds)
    if ($Seconds -lt 60) { return ("{0:n0}s" -f $Seconds) }
    $ts = [TimeSpan]::FromSeconds($Seconds)
    if ($ts.TotalHours -ge 1) { return ("{0}h{1:d2}m{2:d2}s" -f [int]$ts.TotalHours, $ts.Minutes, $ts.Seconds) }
    return ("{0}m{1:d2}s" -f $ts.Minutes, $ts.Seconds)
}

function To-LongPath {
    param([string]$Path)
    if ($Path.StartsWith('\\?\')) { return $Path }
    if ($Path.StartsWith('\\'))    { return '\\?\UNC\' + $Path.Substring(2) }
    return '\\?\' + $Path
}

# ============================================================
# FULL-DONE MARKER (per day, per PC, on backup disk)
# ============================================================

function Get-FullDoneMarkerPath {
    # One marker per calendar day, per PC, on the backup disk.
    $markerDir = Join-Path (Join-Path $DestinationBase ".state") $env:COMPUTERNAME
    return (Join-Path $markerDir ("full-done_{0:yyyy-MM-dd}.marker" -f (Get-Date)))
}

function Test-FullAlreadyDoneToday {
    $p = Get-FullDoneMarkerPath
    return (Test-Path -LiteralPath $p)
}

function Set-FullDoneMarker {
    $p = Get-FullDoneMarkerPath
    $dir = Split-Path -Parent $p
    if (-not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    Set-Content -LiteralPath $p -Value ((Get-Date).ToString("s")) -Encoding UTF8
}

function Test-ModeAllowedNow {
    param([string]$RequestedMode)

    $now = (Get-Date).TimeOfDay

    # "FULL" mode: allowed from 00:00 up to and including 13:30,
    # OR at any time if today's FULL has not run yet (catch-up).
    if ($RequestedMode -eq 'FULL') {
        if ($now -le $FullBackupCutoff) {
            return @{ Allowed = $true }
        }
        if ($AllowFullIfNotYetRunToday -and -not (Test-FullAlreadyDoneToday)) {
            return @{
                Allowed = $true
                Reason  = ("FULL allowed outside window: no FULL has run yet today ({0:yyyy-MM-dd})." -f (Get-Date))
            }
        }
        return @{
            Allowed = $false
            Reason  = ("FULL is not allowed at this time ({0:HH:mm}). Allowed window: 00:00 - 13:30, and today's FULL is already done." -f (Get-Date))
        }
    }

    # INCREMENTAL / ARCHIVE / BOTH: allowed from 14:00 onwards.
    if ($RequestedMode -in 'INCREMENTAL','ARCHIVE','BOTH') {
        if ($now -ge $IncrementalEarliest) {
            return @{ Allowed = $true }
        }
        return @{
            Allowed = $false
            Reason  = ("{0} is not allowed at this time ({1:HH:mm}). Allowed window: from 14:00 onwards." -f $RequestedMode, (Get-Date))
        }
    }

    return @{ Allowed = $false; Reason = "Unknown mode: $RequestedMode" }
}

# ============================================================
# PROGRESS
# ============================================================

function New-BarString {
    param([char]$Char, [int]$Count)
    if ($Count -le 0) { return '' }
    return [string]::new($Char, $Count)
}

function Show-Progress {
    param(
        [string]$Label,
        [int]$Current,
        [int]$Total,
        [int64]$BytesDone,
        [int64]$BytesTotal,
        [datetime]$StartTime
    )
    $script:ProgressActive = $true

    $pct = if ($Total -gt 0) { [int](100.0 * $Current / $Total) } else { 0 }
    if ($pct -gt 100) { $pct = 100 }

    $elapsed = ((Get-Date) - $StartTime).TotalSeconds
    $speed = if ($elapsed -gt 0) { $BytesDone / $elapsed } else { 0 }
    $eta   = if ($speed -gt 0 -and $BytesTotal -gt $BytesDone) {
                ($BytesTotal - $BytesDone) / $speed
             } else { 0 }

    $consoleWidth = 100
    try { $consoleWidth = [Math]::Max(60, [Console]::WindowWidth - 1) } catch {}
    $barWidth = 30
    $filled   = [int][Math]::Floor($barWidth * $pct / 100)
    $empty    = $barWidth - $filled

    if ($UseUnicodeProgressBar) {
        $bar = (New-BarString -Char ([char]0x2588) -Count $filled) + `
               (New-BarString -Char ([char]0x2591) -Count $empty)
    } else {
        $bar = (New-BarString -Char ([char]'#')      -Count $filled) + `
               (New-BarString -Char ([char]'.')      -Count $empty)
    }

    $line = "{0,-22} [{1}] {2,3}%  {3,6}/{4,-6} files  {5,10} / {6,-10}  {7,9}/s  ETA {8}" -f `
        $Label, $bar, $pct, $Current, $Total, `
        (Format-Size $BytesDone), (Format-Size $BytesTotal), `
        (Format-Size ([int64]$speed)), (Format-Duration $eta)

    if ($line.Length -gt $consoleWidth) { $line = $line.Substring(0, $consoleWidth) } else { $line = $line.PadRight($consoleWidth) }

    [Console]::Write("`r" + $line)
}

function Clear-Progress {
    if (-not $script:ProgressActive) { return }
    $consoleWidth = 100
    try { $consoleWidth = [Math]::Max(60, [Console]::WindowWidth - 1) } catch {}
    [Console]::Write("`r" + (' ' * $consoleWidth) + "`r")
    $script:ProgressActive = $false
}

# ============================================================
# VSS
# ============================================================

$VssErrorText = @{
    1="Access denied"; 2="Invalid argument"; 3="Volume not found"; 4="Volume not supported"
    5="Unsupported context"; 6="Insufficient storage"; 7="Volume in use"
    8="Max shadow copies reached"; 9="Another shadow copy operation in progress"
    10="Provider vetoed"; 11="Provider not registered"; 12="Provider failure"; 13="Unknown error"
}

function New-VssSnapshot {
    param([string]$Volume, [int]$MaxAttempts = 20, [int]$DelaySeconds = 30)
    for ($attempt = 1; $attempt -le $MaxAttempts; $attempt++) {
        $result = (Get-WmiObject -List Win32_ShadowCopy).Create($Volume, "ClientAccessible")
        $code = [int]$result.ReturnValue
        if ($code -eq 0) { return $result.ShadowID }
        $text = $VssErrorText[$code]
        if ($code -in 9,12 -and $attempt -lt $MaxAttempts) {
            Write-Log ("VSS busy for {0}: {1} ({2}). Retry {3}/{4} in {5}s..." -f `
                $Volume, $code, $text, $attempt, ($MaxAttempts-1), $DelaySeconds) "WARN"
            Start-Sleep -Seconds $DelaySeconds; continue
        }
        throw "Shadow copy create failed: code $code ($text)"
    }
}

function Remove-DirLink {
    param([string]$Path)
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction SilentlyContinue
    if (-not $item) { return }
    if (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw "$Path exists and is NOT a symlink - refusing to touch it."
    }
    cmd.exe /c rmdir "$Path" | Out-Null
}

# ============================================================
# SETUP
# ============================================================

$today       = Get-Date
$dayOfMonth  = $today.Day
$expectedLbl = $ExpectedVolumeLabelFormat -f $dayOfMonth

$DestinationRoot = $DestinationBase
if ($IncludeComputerName) { $DestinationRoot = Join-Path $DestinationRoot $env:COMPUTERNAME }

$ChangedRoot = Join-Path $DestinationBase $ChangedRootName
if ($IncludeComputerName) { $ChangedRoot = Join-Path $ChangedRoot $env:COMPUTERNAME }

$StateRoot = Join-Path $DestinationBase ".state"
if ($IncludeComputerName) { $StateRoot = Join-Path $StateRoot $env:COMPUTERNAME }

Write-Log "=== VSS backup started: Mode=$Mode ==="
Write-Log ("Today is {0:yyyy-MM-dd} ({1}), month-day = {2:D2}" -f $today, $today.DayOfWeek, $dayOfMonth)
Write-Log "Destination root: $DestinationRoot"
Write-Log "Changed root:     $ChangedRoot"
Write-Log "State root:       $StateRoot"

if (-not (Test-Path "${DestinationDrive}:\")) {
    Write-Log "Destination drive ${DestinationDrive}: not available." "ERROR"
    Write-Log "=== Backup ended (FAILED) ==="; Close-Log; exit 1
}

$vol = Get-Volume -DriveLetter $DestinationDrive -ErrorAction SilentlyContinue
if ($vol.FileSystemType -and $vol.FileSystemType -ne 'NTFS') {
    Write-Log "WARNING: ${DestinationDrive}: is $($vol.FileSystemType), not NTFS." "WARN"
}

if ($VerifyDiskLabel) {
    if ($vol.FileSystemLabel -ne $expectedLbl) {
        Write-Log ("ERROR: Disk label mismatch. Expected '{0}', found '{1}'." -f `
            $expectedLbl, $vol.FileSystemLabel) "ERROR"
        Write-Log "=== Backup ended (FAILED) ==="; Close-Log; exit 1
    }
    Write-Log "Disk label check OK: $($vol.FileSystemLabel)"
}

foreach ($p in @($DestinationRoot, $ChangedRoot, $StateRoot)) {
    if (-not (Test-Path -LiteralPath $p)) {
        New-Item -ItemType Directory -Path $p -Force | Out-Null
    }
}

# --- Validate sources ---
$validSources = @()
foreach ($src in $SourceDirs) {
    if (Test-Path -LiteralPath $src) {
        $validSources += (Resolve-Path -LiteralPath $src).Path.TrimEnd('\')
    } else {
        Write-Log "Source not found, skipping: $src" "WARN"
    }
}
if ($validSources.Count -eq 0) { Write-Log "No valid sources. Aborting." "ERROR"; Close-Log; exit 1 }

# ============================================================
# MANIFEST HANDLING
# ============================================================

function Load-Manifest {
    param([string]$Path)
    $dict = New-Object 'System.Collections.Generic.Dictionary[string,object]' ([StringComparer]::OrdinalIgnoreCase)
    if (-not (Test-Path -LiteralPath $Path)) { return $dict }
    try {
        $reader = New-Object IO.StreamReader($Path, [Text.Encoding]::UTF8)
        try {
            while (-not $reader.EndOfStream) {
                $line = $reader.ReadLine()
                if ([string]::IsNullOrWhiteSpace($line)) { continue }
                $parts = $line -split "`t"
                if ($parts.Count -lt 3) { continue }
                $dict[$parts[0]] = [pscustomobject]@{
                    Size  = [int64]$parts[1]
                    Ticks = [int64]$parts[2]
                }
            }
        } finally { $reader.Dispose() }
    } catch {
        Write-Log "Could not read manifest $Path : $_" "WARN"
    }
    return $dict
}

function Save-Manifest {
    param([string]$Path, $Entries)
    $tmp = "$Path.tmp"
    $writer = New-Object IO.StreamWriter($tmp, $false, (New-Object Text.UTF8Encoding($false)))
    try {
        foreach ($kv in $Entries.GetEnumerator()) {
            $writer.Write($kv.Key); $writer.Write("`t")
            $writer.Write($kv.Value.Size); $writer.Write("`t")
            $writer.Write($kv.Value.Ticks); $writer.WriteLine()
        }
    } finally { $writer.Dispose() }
    Move-Item -LiteralPath $tmp -Destination $Path -Force
}

# ============================================================
# COPY ENGINE
# ============================================================

function Test-Excluded {
    param([string]$Name, [string[]]$Patterns)
    foreach ($p in $Patterns) { if ($Name -ieq $p) { return $true } }
    return $false
}

function Copy-OneItem {
    param([string]$SourcePath, [string]$DestPath)
    $destDir = Split-Path -Parent $DestPath
    if (-not (Test-Path -LiteralPath (To-LongPath $destDir))) {
        New-Item -ItemType Directory -Path (To-LongPath $destDir) -Force | Out-Null
    }
    for ($a = 1; $a -le $CopyRetries; $a++) {
        try {
            Copy-Item -LiteralPath (To-LongPath $SourcePath) -Destination (To-LongPath $DestPath) -Force -ErrorAction Stop
            $srcItem = Get-Item -LiteralPath (To-LongPath $SourcePath) -Force
            $dstItem = Get-Item -LiteralPath (To-LongPath $DestPath)   -Force
            if ($dstItem.LastWriteTime -ne $srcItem.LastWriteTime) { $dstItem.LastWriteTime = $srcItem.LastWriteTime }
            if ($dstItem.CreationTime  -ne $srcItem.CreationTime)  { $dstItem.CreationTime  = $srcItem.CreationTime  }
            return $true
        } catch {
            if ($a -lt $CopyRetries) {
                Write-Log ("Retry {0}/{1} for {2}: {3}" -f $a, $CopyRetries, $SourcePath, $_.Exception.Message) "WARN"
                Start-Sleep -Seconds $CopyRetryWaitSec
            } else {
                Write-Log ("FAILED to copy {0} -> {1}: {2}" -f $SourcePath, $DestPath, $_.Exception.Message) "ERROR"
                return $false
            }
        }
    }
}

function Get-SourceTree {
    param([string]$SourceRoot, [string[]]$ExcludeDirs, [string[]]$ExcludeFiles)
    $srcFiles = @(); $srcDirs = @()
    $stack = New-Object System.Collections.Stack
    $stack.Push($SourceRoot)
    while ($stack.Count -gt 0) {
        $current = $stack.Pop()
        try { $entries = Get-ChildItem -LiteralPath (To-LongPath $current) -Force -ErrorAction Stop }
        catch {
            Write-Log ("Cannot enumerate {0}: {1}" -f $current, $_.Exception.Message) "WARN"
            continue
        }
        foreach ($e in $entries) {
            if ($e.PSIsContainer) {
                if (Test-Excluded $e.Name $ExcludeDirs) { continue }
                $srcDirs += $e; $stack.Push($e.FullName)
            } else {
                if (Test-Excluded $e.Name $ExcludeFiles) { continue }
                $srcFiles += $e
            }
        }
    }
    return @{ Files = $srcFiles; Dirs = $srcDirs }
}

# ------------------------------------------------------------
# FULL
# ------------------------------------------------------------
function Sync-Tree-Full {
    param(
        [string]$SourceRoot, [string]$DestRoot,
        [string[]]$ExcludeDirs, [string[]]$ExcludeFiles,
        [bool]$MirrorDeletes, [string]$ProgressLabel,
        [string]$ManifestPath
    )
    $stats = [ordered]@{ Copied=0; Updated=0; Skipped=0; Deleted=0; Errors=0; Bytes=[int64]0 }
    if (-not (Test-Path -LiteralPath (To-LongPath $DestRoot))) {
        New-Item -ItemType Directory -Path (To-LongPath $DestRoot) -Force | Out-Null
    }

    Show-Progress -Label "$ProgressLabel [scan]" -Current 0 -Total 0 -BytesDone 0 -BytesTotal 0 -StartTime (Get-Date)
    $tree = Get-SourceTree -SourceRoot $SourceRoot -ExcludeDirs $ExcludeDirs -ExcludeFiles $ExcludeFiles
    $srcFiles = $tree.Files; $srcDirs = $tree.Dirs

    $totalFiles = $srcFiles.Count
    $totalBytes = [int64]0
    foreach ($f in $srcFiles) { $totalBytes += [int64]$f.Length }
    Write-Log ("Scan complete: {0} files, {1} dirs, {2}" -f $totalFiles, $srcDirs.Count, (Format-Size $totalBytes))

    $srcRootLong = (To-LongPath $SourceRoot).TrimEnd('\')
    $srcRelFiles = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    $srcRelDirs  = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($f in $srcFiles) { [void]$srcRelFiles.Add($f.FullName.Substring($srcRootLong.Length).TrimStart('\')) }
    foreach ($d in $srcDirs)  { [void]$srcRelDirs.Add($d.FullName.Substring($srcRootLong.Length).TrimStart('\')) }

    $startTime  = Get-Date
    $doneFiles  = 0
    $doneBytes  = [int64]0
    $lastUpdate = Get-Date
    $newEntries = New-Object 'System.Collections.Generic.Dictionary[string,object]' ([StringComparer]::OrdinalIgnoreCase)

    foreach ($f in $srcFiles) {
        $rel     = $f.FullName.Substring($srcRootLong.Length).TrimStart('\')
        $dst     = Join-Path $DestRoot $rel
        $dstLong = To-LongPath $dst

        $needsCopy = $true
        if (Test-Path -LiteralPath $dstLong) {
            try {
                $dstItem = Get-Item -LiteralPath $dstLong -Force
                if ($dstItem.Length -eq $f.Length -and $dstItem.LastWriteTime -eq $f.LastWriteTime) {
                    $needsCopy = $false; $stats.Skipped++
                } else { $stats.Updated++ }
            } catch { $stats.Updated++ }
        } else { $stats.Copied++ }

        if ($needsCopy) {
            if (Copy-OneItem -SourcePath $f.FullName -DestPath $dst) {
                $stats.Bytes += [int64]$f.Length
                $doneBytes   += [int64]$f.Length
            } else { $stats.Errors++ }
        }
        $doneFiles++

        $newEntries[$rel] = [pscustomobject]@{ Size = [int64]$f.Length; Ticks = $f.LastWriteTime.Ticks }

        $now = Get-Date
        if (($now - $lastUpdate).TotalMilliseconds -ge 100) {
            Show-Progress -Label $ProgressLabel -Current $doneFiles -Total $totalFiles `
                          -BytesDone $doneBytes -BytesTotal $totalBytes -StartTime $startTime
            $lastUpdate = $now
        }
    }
    Show-Progress -Label $ProgressLabel -Current $doneFiles -Total $totalFiles `
                  -BytesDone $doneBytes -BytesTotal $totalBytes -StartTime $startTime

    if ($MirrorDeletes) {
        Clear-Progress
        Write-Log "Scanning destination for deletions..." "DEBUG"
        $destRootLong = (To-LongPath $DestRoot).TrimEnd('\')
        $dstAllFiles = @(); $dstAllDirs = @()
        $stack2 = New-Object System.Collections.Stack
        $stack2.Push($DestRoot)
        while ($stack2.Count -gt 0) {
            $current = $stack2.Pop()
            try { $entries = Get-ChildItem -LiteralPath (To-LongPath $current) -Force -ErrorAction Stop } catch { continue }
            foreach ($e in $entries) {
                if ($e.PSIsContainer) {
                    if (Test-Excluded $e.Name $ExcludeDirs) { continue }
                    $dstAllDirs += $e; $stack2.Push($e.FullName)
                } else {
                    if (Test-Excluded $e.Name $ExcludeFiles) { continue }
                    $dstAllFiles += $e
                }
            }
        }
        $totalDel = $dstAllFiles.Count
        $i = 0; $startDel = Get-Date
        foreach ($df in $dstAllFiles) {
            $rel = $df.FullName.Substring($destRootLong.Length).TrimStart('\')
            if (-not $srcRelFiles.Contains($rel)) {
                try {
                    Remove-Item -LiteralPath (To-LongPath $df.FullName) -Force -ErrorAction Stop
                    $stats.Deleted++
                    [void]$newEntries.Remove($rel)
                    Write-Log ("Deleted file: $($df.FullName)") "DEBUG"
                } catch {
                    Write-Log ("Failed to delete {0}: {1}" -f $df.FullName, $_.Exception.Message) "WARN"
                    $stats.Errors++
                }
            }
            $i++
            $now = Get-Date
            if (($now - $startDel).TotalMilliseconds -ge 100) {
                Show-Progress -Label "$ProgressLabel [delete]" -Current $i -Total $totalDel `
                              -BytesDone 0 -BytesTotal 0 -StartTime $startDel
                $startDel = $now
            }
        }
        $sortedDirs = $dstAllDirs | Sort-Object { $_.FullName.Length } -Descending
        foreach ($dd in $sortedDirs) {
            $rel = $dd.FullName.Substring($destRootLong.Length).TrimStart('\')
            if (-not $srcRelDirs.Contains($rel)) {
                try {
                    Remove-Item -LiteralPath (To-LongPath $dd.FullName) -Force -Recurse -ErrorAction Stop
                    $stats.Deleted++
                } catch {}
            }
        }
        Clear-Progress
    }

    Save-Manifest -Path $ManifestPath -Entries $newEntries
    Clear-Progress
    return $stats
}

# ------------------------------------------------------------
# INCREMENTAL
# ------------------------------------------------------------
function Sync-Tree-Incremental {
    param(
        [string]$SourceRoot, [string]$DestRoot,
        [string[]]$ExcludeDirs, [string[]]$ExcludeFiles,
        [bool]$MirrorDeletes, [string]$ProgressLabel,
        [string]$ManifestPath
    )
    $stats = [ordered]@{ Copied=0; Updated=0; Skipped=0; Deleted=0; Errors=0; Bytes=[int64]0 }

    $manifest = Load-Manifest -Path $ManifestPath
    Write-Log ("Loaded manifest: {0} entries from {1}" -f $manifest.Count, $ManifestPath)

    Show-Progress -Label "$ProgressLabel [scan]" -Current 0 -Total 0 -BytesDone 0 -BytesTotal 0 -StartTime (Get-Date)
    $tree = Get-SourceTree -SourceRoot $SourceRoot -ExcludeDirs $ExcludeDirs -ExcludeFiles $ExcludeFiles
    $srcFiles = $tree.Files; $srcDirs = $tree.Dirs

    $totalFiles = $srcFiles.Count
    $totalBytes = [int64]0
    foreach ($f in $srcFiles) { $totalBytes += [int64]$f.Length }
    Write-Log ("Scan complete: {0} files, {1} dirs, {2}" -f $totalFiles, $srcDirs.Count, (Format-Size $totalBytes))

    $srcRootLong = (To-LongPath $SourceRoot).TrimEnd('\')
    $srcRelFiles = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    $srcRelDirs  = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($f in $srcFiles) { [void]$srcRelFiles.Add($f.FullName.Substring($srcRootLong.Length).TrimStart('\')) }
    foreach ($d in $srcDirs)  { [void]$srcRelDirs.Add($d.FullName.Substring($srcRootLong.Length).TrimStart('\')) }

    $startTime  = Get-Date
    $doneFiles  = 0
    $doneBytes  = [int64]0
    $lastUpdate = Get-Date
    $newEntries = New-Object 'System.Collections.Generic.Dictionary[string,object]' ([StringComparer]::OrdinalIgnoreCase)

    foreach ($f in $srcFiles) {
        $rel     = $f.FullName.Substring($srcRootLong.Length).TrimStart('\')
        $dst     = Join-Path $DestRoot $rel
        $dstLong = To-LongPath $dst
        $srcTicks = $f.LastWriteTime.Ticks
        $srcSize  = [int64]$f.Length

        $manifestHit = $false
        if ($manifest.ContainsKey($rel)) {
            $m = $manifest[$rel]
            if ($m.Size -eq $srcSize -and $m.Ticks -eq $srcTicks) { $manifestHit = $true }
        }
        $needsCopy = -not $manifestHit
        if ($manifestHit) {
            if (-not (Test-Path -LiteralPath $dstLong)) { $needsCopy = $true }
            else { $stats.Skipped++ }
        }

        if ($needsCopy) {
            $exists = Test-Path -LiteralPath $dstLong
            if ($exists) { $stats.Updated++ } else { $stats.Copied++ }
            if (Copy-OneItem -SourcePath $f.FullName -DestPath $dst) {
                $stats.Bytes += $srcSize; $doneBytes += $srcSize
            } else { $stats.Errors++ }
        }
        $doneFiles++
        $newEntries[$rel] = [pscustomobject]@{ Size = $srcSize; Ticks = $srcTicks }

        $now = Get-Date
        if (($now - $lastUpdate).TotalMilliseconds -ge 100) {
            Show-Progress -Label $ProgressLabel -Current $doneFiles -Total $totalFiles `
                          -BytesDone $doneBytes -BytesTotal $totalBytes -StartTime $startTime
            $lastUpdate = $now
        }
    }
    Show-Progress -Label $ProgressLabel -Current $doneFiles -Total $totalFiles `
                  -BytesDone $doneBytes -BytesTotal $totalBytes -StartTime $startTime

    if ($MirrorDeletes) {
        Clear-Progress
        Write-Log "Scanning destination for deletions..." "DEBUG"
        $destRootLong = (To-LongPath $DestRoot).TrimEnd('\')
        $dstAllFiles = @(); $dstAllDirs = @()
        $stack2 = New-Object System.Collections.Stack
        $stack2.Push($DestRoot)
        while ($stack2.Count -gt 0) {
            $current = $stack2.Pop()
            try { $entries = Get-ChildItem -LiteralPath (To-LongPath $current) -Force -ErrorAction Stop } catch { continue }
            foreach ($e in $entries) {
                if ($e.PSIsContainer) {
                    if (Test-Excluded $e.Name $ExcludeDirs) { continue }
                    $dstAllDirs += $e; $stack2.Push($e.FullName)
                } else {
                    if (Test-Excluded $e.Name $ExcludeFiles) { continue }
                    $dstAllFiles += $e
                }
            }
        }
        $totalDel = $dstAllFiles.Count
        $i = 0; $startDel = Get-Date
        foreach ($df in $dstAllFiles) {
            $rel = $df.FullName.Substring($destRootLong.Length).TrimStart('\')
            if (-not $srcRelFiles.Contains($rel)) {
                try {
                    Remove-Item -LiteralPath (To-LongPath $df.FullName) -Force -ErrorAction Stop
                    $stats.Deleted++
                    [void]$newEntries.Remove($rel)
                    Write-Log ("Deleted file: $($df.FullName)") "DEBUG"
                } catch {
                    Write-Log ("Failed to delete {0}: {1}" -f $df.FullName, $_.Exception.Message) "WARN"
                    $stats.Errors++
                }
            }
            $i++
            $now = Get-Date
            if (($now - $startDel).TotalMilliseconds -ge 100) {
                Show-Progress -Label "$ProgressLabel [delete]" -Current $i -Total $totalDel `
                              -BytesDone 0 -BytesTotal 0 -StartTime $startDel
                $startDel = $now
            }
        }
        $sortedDirs = $dstAllDirs | Sort-Object { $_.FullName.Length } -Descending
        foreach ($dd in $sortedDirs) {
            $rel = $dd.FullName.Substring($destRootLong.Length).TrimStart('\')
            if (-not $srcRelDirs.Contains($rel)) {
                try {
                    Remove-Item -LiteralPath (To-LongPath $dd.FullName) -Force -Recurse -ErrorAction Stop
                    $stats.Deleted++
                } catch {}
            }
        }
        Clear-Progress
    }

    Save-Manifest -Path $ManifestPath -Entries $newEntries
    Clear-Progress
    return $stats
}

# ------------------------------------------------------------
# ARCHIVE
# ------------------------------------------------------------
function Get-ArchiveRoots {
    param([string]$ChangedRoot, [string]$DriveLetter)
    return @{
        Created  = Join-Path (Join-Path $ChangedRoot $ChangedCreatedName)  $DriveLetter
        Modified = Join-Path (Join-Path $ChangedRoot $ChangedModifiedName) $DriveLetter
        Deleted  = Join-Path (Join-Path $ChangedRoot $ChangedDeletedName)  $DriveLetter
        Previous = Join-Path (Join-Path $ChangedRoot '.previous')          $DriveLetter
    }
}

function Invoke-Archive-Pass {
    param(
        [string]$SourceRoot, [string]$DestRoot,
        [string]$DriveLetter,
        [string[]]$ExcludeDirs, [string[]]$ExcludeFiles,
        [string]$ProgressLabel,
        [string]$ManifestPath,
        [string]$ChangedRoot
    )
    $stats = [ordered]@{
        Created=0; Modified=0; Deleted=0; Errors=0
        BytesCreated=[int64]0; BytesModified=[int64]0; BytesDeleted=[int64]0
    }

    $roots = Get-ArchiveRoots -ChangedRoot $ChangedRoot -DriveLetter $DriveLetter
    foreach ($r in $roots.Values) {
        if (-not (Test-Path -LiteralPath $r)) {
            New-Item -ItemType Directory -Path $r -Force | Out-Null
        }
    }

    $manifest = Load-Manifest -Path $ManifestPath
    Write-Log ("Archive: loaded previous manifest {0} entries" -f $manifest.Count)

    Show-Progress -Label "$ProgressLabel [archive scan]" -Current 0 -Total 0 -BytesDone 0 -BytesTotal 0 -StartTime (Get-Date)
    $tree = Get-SourceTree -SourceRoot $SourceRoot -ExcludeDirs $ExcludeDirs -ExcludeFiles $ExcludeFiles
    $srcFiles = $tree.Files

    $srcRootLong = (To-LongPath $SourceRoot).TrimEnd('\')
    $current = New-Object 'System.Collections.Generic.Dictionary[string,object]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($f in $srcFiles) {
        $rel = $f.FullName.Substring($srcRootLong.Length).TrimStart('\')
        $current[$rel] = [pscustomobject]@{ Size = [int64]$f.Length; Ticks = $f.LastWriteTime.Ticks; FullName = $f.FullName }
    }

    # --- Pass 1: created + modified ---
    $startTime = Get-Date
    $done = 0
    $total = $current.Count

    foreach ($kv in $current.GetEnumerator()) {
        $rel  = $kv.Key
        $info = $kv.Value

        $isCreated  = -not $manifest.ContainsKey($rel)
        $isModified = $false
        if (-not $isCreated) {
            $m = $manifest[$rel]
            if ($m.Size -ne $info.Size -or $m.Ticks -ne $info.Ticks) { $isModified = $true }
        }

        if ($isCreated) {
            $dest = Join-Path $roots.Created $rel
            if (Copy-OneItem -SourcePath $info.FullName -DestPath $dest) {
                $stats.Created++
                $stats.BytesCreated += $info.Size
            } else { $stats.Errors++ }
        } elseif ($isModified) {
            $dest = Join-Path $roots.Modified $rel
            if (Copy-OneItem -SourcePath $info.FullName -DestPath $dest) {
                $stats.Modified++
                $stats.BytesModified += $info.Size
            } else { $stats.Errors++ }

            # Previous version (if we have it in .previous)
            $prevSrc = Join-Path $roots.Previous $rel
            if (Test-Path -LiteralPath (To-LongPath $prevSrc)) {
                $dir  = Split-Path -Parent $rel
                $leaf = Split-Path -Leaf   $rel
                $ext  = [IO.Path]::GetExtension($leaf)
                $base = [IO.Path]::GetFileNameWithoutExtension($leaf)
                $leafNew = "$base.vorige-versie$ext"
                $prevDest = if ($dir) { Join-Path $roots.Modified (Join-Path $dir $leafNew) } else { Join-Path $roots.Modified $leafNew }
                [void](Copy-OneItem -SourcePath $prevSrc -DestPath $prevDest)
            }
        }

        $done++
        $now = Get-Date
        if (($now - $startTime).TotalMilliseconds -ge 100) {
            Show-Progress -Label $ProgressLabel -Current $done -Total $total `
                          -BytesDone ($stats.BytesCreated + $stats.BytesModified) -BytesTotal 0 -StartTime $startTime
            $startTime = $now
        }
    }

    # --- Pass 2: deleted ---
    foreach ($kv in $manifest.GetEnumerator()) {
        $rel = $kv.Key
        if ($current.ContainsKey($rel)) { continue }

        $prevSrc   = Join-Path $roots.Previous $rel
        $mirrorSrc = Join-Path $DestRoot $rel

        $srcForDelete = $null
        if (Test-Path -LiteralPath (To-LongPath $prevSrc))  { $srcForDelete = $prevSrc }
        elseif (Test-Path -LiteralPath (To-LongPath $mirrorSrc)) { $srcForDelete = $mirrorSrc }

        if ($srcForDelete) {
            $dest = Join-Path $roots.Deleted $rel
            if (Copy-OneItem -SourcePath $srcForDelete -DestPath $dest) {
                $stats.Deleted++
                try { $stats.BytesDeleted += (Get-Item -LiteralPath (To-LongPath $srcForDelete) -Force).Length } catch {}
            } else { $stats.Errors++ }
        } else {
            Write-Log ("Archive: cannot recover deleted file (no previous copy): $rel") "WARN"
            $stats.Errors++
        }
    }

    # --- Pass 3: refresh .previous with current source ---
    Clear-Progress
    Write-Log "Archive: updating .previous tree..." "DEBUG"
    foreach ($kv in $current.GetEnumerator()) {
        $rel  = $kv.Key
        $info = $kv.Value
        $prevDest = Join-Path $roots.Previous $rel
        $needsRefresh = $true
        if (Test-Path -LiteralPath (To-LongPath $prevDest)) {
            try {
                $pi = Get-Item -LiteralPath (To-LongPath $prevDest) -Force
                if ($pi.Length -eq $info.Size -and $pi.LastWriteTime.Ticks -eq $info.Ticks) {
                    $needsRefresh = $false
                }
            } catch {}
        }
        if ($needsRefresh) {
            [void](Copy-OneItem -SourcePath $info.FullName -DestPath $prevDest)
        }
    }

    # --- Save manifest ---
    $newManifest = New-Object 'System.Collections.Generic.Dictionary[string,object]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($kv in $current.GetEnumerator()) {
        $newManifest[$kv.Key] = [pscustomobject]@{ Size = $kv.Value.Size; Ticks = $kv.Value.Ticks }
    }
    Save-Manifest -Path $ManifestPath -Entries $newManifest

    Clear-Progress
    return $stats
}

# ============================================================
# RUN
# ============================================================

$volumes = $validSources | ForEach-Object {
    (Split-Path -Qualifier $_).TrimEnd(':') + ":\"
} | Sort-Object -Unique

# --- Enforce time-window policy ---
$policyCheck = Test-ModeAllowedNow -RequestedMode $Mode
if (-not $policyCheck.Allowed) {
    Write-Log $policyCheck.Reason "ERROR"
    Write-Log "=== Backup ended (SKIPPED - outside allowed time window) ==="
    Close-Log
    exit 0
}
if ($policyCheck.Reason) { Write-Log $policyCheck.Reason "WARN" }
Write-Log ("Time-window check OK for mode {0} at {1:HH:mm}" -f $Mode, (Get-Date))

$snapshotMap = @{}
$createdShadowIds = @()
$createdLinks = @()
$totalErrors = 0

try {
    Get-ChildItem "$env:SystemDrive\" -Directory -Force -Filter "VSS_*" -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^VSS_[A-Z]_(\d+)$' -and
                       -not (Get-Process -Id ([int]$Matches[1]) -ErrorAction SilentlyContinue) } |
        ForEach-Object {
            try { Remove-DirLink -Path $_.FullName; Write-Log "Removed stale link $($_.FullName)" }
            catch { Write-Log "Could not remove stale link $($_.FullName): $_" "WARN" }
        }

    foreach ($v in $volumes) {
        Write-Log "Creating VSS snapshot for $v ..."
        $shadowId = New-VssSnapshot -Volume $v
        $createdShadowIds += $shadowId

        $sc = Get-WmiObject Win32_ShadowCopy -Filter "ID='$shadowId'"
        Write-Log "Snapshot for $v -> $($sc.DeviceObject)"

        $letter = $v.Substring(0,1)
        $link = "$env:SystemDrive\VSS_${letter}_$PID"
        Remove-DirLink -Path $link
        cmd.exe /c mklink /d "$link" "$($sc.DeviceObject)\" | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "mklink failed for $link" }

        $createdLinks += $link
        $snapshotMap[$v] = $link
        Write-Log "Snapshot mounted at $link"
    }

    foreach ($src in $validSources) {
        $v = (Split-Path -Qualifier $src).TrimEnd(':') + ":\"
        $relative  = $src.Substring($v.Length).TrimStart('\')
        $shadowSrc = Join-Path $snapshotMap[$v] $relative

        $driveLetter = $v.Substring(0,1).ToUpper()
        $destPath    = Join-Path $DestinationRoot (Join-Path $driveLetter $relative)

        # Per-source manifest names
        $safeSrc       = ($relative -replace '[\\/:*?"<>|]', '_')
        $manifestMirror  = Join-Path $StateRoot ("{0}_{1}.tsv" -f $driveLetter, $safeSrc)
        $manifestArchive = Join-Path $StateRoot ("_changed_{0}_{1}.tsv" -f $driveLetter, $safeSrc)

        Write-Log "Source : $src"
        Write-Log "   from: $shadowSrc"
        Write-Log "   to  : $destPath"
        Write-Log "   manifest (mirror):  $manifestMirror"
        Write-Log "   manifest (archive): $manifestArchive"

        $label = $src
        if ($label.Length -gt 22) { $label = "..." + $label.Substring($label.Length - 19) }

        # ------------------------------------------------
        # INCREMENTAL (optionally first in BOTH mode)
        # ------------------------------------------------
        if ($Mode -eq 'INCREMENTAL' -or $Mode -eq 'BOTH') {
            $sw = [System.Diagnostics.Stopwatch]::StartNew()
            $inc = Sync-Tree-Incremental -SourceRoot $shadowSrc -DestRoot $destPath `
                                         -ExcludeDirs $ExcludeDirs -ExcludeFiles $ExcludeFiles `
                                         -MirrorDeletes $MirrorDeletes -ProgressLabel $label `
                                         -ManifestPath $manifestMirror
            $sw.Stop()
            $totalErrors += $inc.Errors
            Write-Log ("Done [INCREMENTAL]: copied={0} updated={1} skipped={2} deleted={3} errors={4} bytes={5} in {6:n1}s" -f `
                $inc.Copied, $inc.Updated, $inc.Skipped, $inc.Deleted, $inc.Errors, `
                (Format-Size $inc.Bytes), $sw.Elapsed.TotalSeconds)
        }

        # ------------------------------------------------
        # FULL
        # ------------------------------------------------
        if ($Mode -eq 'FULL') {
            $sw = [System.Diagnostics.Stopwatch]::StartNew()
            $f = Sync-Tree-Full -SourceRoot $shadowSrc -DestRoot $destPath `
                                -ExcludeDirs $ExcludeDirs -ExcludeFiles $ExcludeFiles `
                                -MirrorDeletes $MirrorDeletes -ProgressLabel $label `
                                -ManifestPath $manifestMirror
            $sw.Stop()
            $totalErrors += $f.Errors
            Write-Log ("Done [FULL]: copied={0} updated={1} skipped={2} deleted={3} errors={4} bytes={5} in {6:n1}s" -f `
                $f.Copied, $f.Updated, $f.Skipped, $f.Deleted, $f.Errors, `
                (Format-Size $f.Bytes), $sw.Elapsed.TotalSeconds)
        }

        # ------------------------------------------------
        # ARCHIVE (standalone, or after INCREMENTAL in BOTH mode)
        # ------------------------------------------------
        if ($Mode -eq 'ARCHIVE' -or $Mode -eq 'BOTH') {
            $sw = [System.Diagnostics.Stopwatch]::StartNew()
            $a = Invoke-Archive-Pass -SourceRoot $shadowSrc -DestRoot $destPath `
                                     -DriveLetter $driveLetter `
                                     -ExcludeDirs $ExcludeDirs -ExcludeFiles $ExcludeFiles `
                                     -ProgressLabel $label `
                                     -ManifestPath $manifestArchive `
                                     -ChangedRoot $ChangedRoot
            $sw.Stop()
            $totalErrors += $a.Errors
            Write-Log ("Done [ARCHIVE]: created={0} modified={1} deleted={2} errors={3} in {4:n1}s" -f `
                $a.Created, $a.Modified, $a.Deleted, $a.Errors, $sw.Elapsed.TotalSeconds)
            Write-Log ("             bytes created={0} modified={1} deleted={2}" -f `
                (Format-Size $a.BytesCreated), (Format-Size $a.BytesModified), (Format-Size $a.BytesDeleted))
        }
    }
}
catch {
    Clear-Progress
    Write-Log "Backup aborted: $_" "ERROR"
    $totalErrors++
}
finally {
    Clear-Progress
    Write-Log "Removing VSS snapshots..."
    foreach ($link in $createdLinks) {
        try { Remove-DirLink -Path $link; Write-Log "Link removed: $link" }
        catch { Write-Log "Failed to remove link $link : $_" "WARN" }
    }
    foreach ($id in $createdShadowIds) {
        try {
            $sc = Get-WmiObject Win32_ShadowCopy -Filter "ID='$id'"
            if ($sc) { $sc.Delete() | Out-Null; Write-Log "Snapshot removed: $id" }
        } catch { Write-Log "Failed to remove snapshot $id : $_" "WARN" }
    }
}

# --- Write FULL-done marker on a clean FULL run ---
if ($Mode -eq 'FULL' -and $totalErrors -eq 0) {
    try {
        Set-FullDoneMarker
        Write-Log "FULL-done marker written for today: $(Get-FullDoneMarkerPath)"
    } catch {
        Write-Log "Could not write FULL-done marker: $_" "WARN"
    }
}

if ($totalErrors -gt 0) {
    Write-Log "=== Backup ended (WITH $totalErrors ERRORS) ===" "ERROR"
    Close-Log
    exit 16
} else {
    Write-Log "=== Backup ended successfully ==="
    Write-Host ""
    Write-Host "Log file: $LogFile" -ForegroundColor Cyan
    Close-Log
    exit 0
}
