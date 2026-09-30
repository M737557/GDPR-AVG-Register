# ==================================================
# CONTINUOUS BACKUP VIA HELD VSS SNAPSHOT
# Must be executed in an elevated PowerShell session (Run as Administrator)
# ==================================================

# --- Configuration ---
$SourceDirs = @(

    "C:\xampp3\htdocs\"
    "c:\users\maev"
    "C:\scripts\"
    "C:\windows10\pri\Mijnbestanden\"
)

$BackupRoot      = "F:\versioningbackup"
$IntervalSeconds = 5

# Shadow IDs currently held by this script (used to clean up after a crash / closed window)
$HeldStateFile = Join-Path $BackupRoot "held_vss.txt"

# Daily log files live here (kept apart from the mirrored tree)
$LogDir = Join-Path $BackupRoot "_logs"

# Backup layout: the monitored folders are mirrored exactly, including the drive letter:
#   C:\xampp\htdocs\site\index.php  ->  <BackupRoot>\C\xampp\htdocs\site\index_20260929_143005.php
# Each version sits next to the previous ones, with a date+time suffix in the file name.

# Folder where held VSS snapshots are mounted via symbolic link
$SymlinkBaseFolder = "$env:TEMP\VSS_Mounts"

# Ensure script is running as Administrator
$IsAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $IsAdmin) {
    Write-Host "ERROR: This script requires Administrative privileges to manage Volume Shadow Copies." -ForegroundColor Red
    exit 1
}

if (-not (Test-Path $BackupRoot)) {
    Write-Host "ERROR: Target backup location does not exist: $BackupRoot" -ForegroundColor Red
    exit 1
}

foreach ($Dir in @($SymlinkBaseFolder, $LogDir)) {
    if (-not (Test-Path $Dir)) {
        New-Item -ItemType Directory -Path $Dir -Force | Out-Null
    }
}

# Held snapshots per drive: "C:" -> @{ ShadowID; SymlinkPath; DriveLetter; CreatedAt }
$HeldVSS = @{}
$LastCheckTime = (Get-Date).AddSeconds(-$IntervalSeconds)

# --- Helper Functions ---

# Creates a VSS Snapshot and mounts it to a fixed local folder via symbolic link
function Mount-VSSSnapshot {
    param ([string]$DriveLetter)

    try {
        $Drive = $DriveLetter.TrimEnd('\') + "\"

        $Result = Invoke-CimMethod -ClassName Win32_ShadowCopy -MethodName Create -Arguments @{
            Volume  = $Drive
            Context = "ClientAccessible"
        }

        if ($Result.ReturnValue -ne 0 -or -not $Result.ShadowID) {
            Write-Host "VSS creation failed for $Drive (ReturnValue: $($Result.ReturnValue))" -ForegroundColor Red
            return $null
        }

        $ShadowID     = $Result.ShadowID
        $ShadowCopy   = Get-CimInstance -ClassName Win32_ShadowCopy | Where-Object { $_.ID -eq $ShadowID }
        $DeviceObject = $ShadowCopy.DeviceObject + "\"   # Must end with backslash

        # Repoint the fixed mount folder to the new snapshot
        $SymlinkPath = Join-Path $SymlinkBaseFolder "$($DriveLetter.TrimEnd(':'))_Shadow"
        if (Test-Path $SymlinkPath) {
            cmd /c rd "$SymlinkPath" 2>$null
        }
        cmd /c mklink /d "$SymlinkPath" "$DeviceObject" | Out-Null

        if (Test-Path $SymlinkPath) {
            return @{
                ShadowID    = $ShadowID
                SymlinkPath = $SymlinkPath
                DriveLetter = $DriveLetter
            }
        }

        Write-Host "Failed to create symlink for VSS object." -ForegroundColor Red
        Remove-ShadowCopy -ShadowID $ShadowID
        return $null
    } catch {
        Write-Host "VSS Error: $($_.Exception.Message)" -ForegroundColor Red
        return $null
    }
}

# Deletes one shadow copy by ID (does not touch the symlink)
function Remove-ShadowCopy {
    param([string]$ShadowID)
    try {
        $Shadow = Get-CimInstance -ClassName Win32_ShadowCopy | Where-Object { $_.ID -eq $ShadowID }
        if ($Shadow) { Remove-CimInstance -InputObject $Shadow }
    } catch {
        Write-Host "Warning during VSS cleanup: $($_.Exception.Message)" -ForegroundColor Yellow
    }
}

# Writes the currently held shadow IDs to disk
function Save-HeldState {
    $Ids = @($script:HeldVSS.Values | ForEach-Object { $_.ShadowID })
    Set-Content -LiteralPath $HeldStateFile -Value $Ids -Encoding UTF8
}

# Replaces the held snapshot of a drive: new one first, then the old one is deleted
function Update-HeldSnapshot {
    param([string]$DriveLetter)

    $Old       = $script:HeldVSS[$DriveLetter]
    $CreatedAt = Get-Date   # taken BEFORE creation: anything written after this counts as "newer"
    $New       = Mount-VSSSnapshot -DriveLetter $DriveLetter

    if ($New) {
        $New.CreatedAt = $CreatedAt
        $script:HeldVSS[$DriveLetter] = $New
        if ($Old) { Remove-ShadowCopy -ShadowID $Old.ShadowID }
        Save-HeldState
        Write-Host "VSS snapshot for $DriveLetter refreshed and held ($($New.ShadowID))." -ForegroundColor Magenta
        return
    }

    # Refresh failed: keep the old snapshot only if its mount still works
    if ($Old -and -not (Test-Path $Old.SymlinkPath)) {
        Remove-ShadowCopy -ShadowID $Old.ShadowID
        $script:HeldVSS.Remove($DriveLetter)
        Save-HeldState
    }
}

# Translates a live path (C:\xampp\htdocs\a.php) into the snapshot path
# (<mount>\xampp\htdocs\a.php). The mount points at the DRIVE root.
function Get-VSSPath {
    param([string]$SymlinkPath, [string]$FullName)
    $DriveRelative = (Split-Path -Path $FullName -NoQualifier).TrimStart('\')
    return (Join-Path $SymlinkPath $DriveRelative)
}

# --- Startup ---

# Remove snapshots left behind by a previous run that was killed without cleanup
if (Test-Path -LiteralPath $HeldStateFile) {
    Get-Content -LiteralPath $HeldStateFile -Encoding UTF8 | Where-Object { $_ } | ForEach-Object {
        Remove-ShadowCopy -ShadowID $_
        Write-Host "Removed orphaned snapshot from previous run: $_" -ForegroundColor Gray
    }
    Remove-Item -LiteralPath $HeldStateFile -Force
}

# Create and hold one snapshot per drive that contains monitored folders
$SourceDrives = $SourceDirs | ForEach-Object { Split-Path -Path $_ -Qualifier } | Select-Object -Unique
foreach ($DriveLetter in $SourceDrives) {
    Update-HeldSnapshot -DriveLetter $DriveLetter
}

Write-Host "Backup service started with held VSS - checking every $IntervalSeconds seconds..." -ForegroundColor Cyan
Write-Host "Press CTRL+C to terminate.`n" -ForegroundColor Yellow

# --- Main Loop ---

try {
    while ($true) {
        $CurrentCheckTime = Get-Date
        $ChangedFiles = @()

        foreach ($SourceDir in $SourceDirs) {
            if (-not (Test-Path $SourceDir)) { continue }

            $FilesInDir = Get-ChildItem -Path $SourceDir -File -Recurse -ErrorAction SilentlyContinue |
                Where-Object {
                    $_.LastWriteTime -ge $LastCheckTime -and
                    $_.Name -notin @("desktop.ini", "Thumbs.db", ".DS_Store") -and
                    -not ($_.Attributes -band [System.IO.FileAttributes]::System) -and
                    -not ($_.Attributes -band [System.IO.FileAttributes]::Hidden)
                }

            if ($FilesInDir) {
                $ChangedFiles += $FilesInDir | ForEach-Object {
                    $_ | Add-Member -NotePropertyName "DriveKey" -NotePropertyValue (Split-Path -Path $_.FullName -Qualifier) -PassThru -Force
                }
            }
        }

        if ($ChangedFiles.Count -gt 0) {
            Write-Host "Detected $($ChangedFiles.Count) changed file(s)." -ForegroundColor Yellow

            # Refresh a held snapshot ONLY if it doesn't contain the latest changes yet
            foreach ($DriveLetter in ($ChangedFiles.DriveKey | Select-Object -Unique)) {
                $Held = $HeldVSS[$DriveLetter]
                $IsStale = (-not $Held) -or
                           [bool]($ChangedFiles | Where-Object { $_.DriveKey -eq $DriveLetter -and $_.LastWriteTime -ge $Held.CreatedAt } | Select-Object -First 1)
                if ($IsStale) {
                    Update-HeldSnapshot -DriveLetter $DriveLetter
                }
            }

            $LogFile   = Join-Path $LogDir ("backup_" + $CurrentCheckTime.ToString("yyyy-MM-dd") + ".txt")
            $Timestamp = $CurrentCheckTime.ToString("yyyyMMdd_HHmmss")
            $TimeLabel = $CurrentCheckTime.ToString('HH:mm:ss')
            $Counter = 0; $CopiedFiles = 0; $SkippedFiles = 0

            foreach ($File in $ChangedFiles) {
                $Counter++

                # Mirror the original location: C:\xampp\htdocs\site -> <BackupRoot>\C\xampp\htdocs\site
                $DriveFolder = $File.DriveKey.TrimEnd(':')
                $DirNoDrive  = (Split-Path -Path $File.DirectoryName -NoQualifier).TrimStart('\')
                $TargetDir   = Join-Path (Join-Path $BackupRoot $DriveFolder) $DirNoDrive
                if (-not (Test-Path -LiteralPath $TargetDir)) {
                    New-Item -ItemType Directory -Path $TargetDir -Force | Out-Null
                }

                $NewFileName = "$($File.BaseName)_${Timestamp}$($File.Extension)"
                $TargetFile  = Join-Path $TargetDir $NewFileName

                # 1. Read from the held snapshot if it already contains this version, else live file
                $ReadPath = $File.FullName
                $FromVSS  = $false
                $Held     = $HeldVSS[$File.DriveKey]
                if ($Held -and $File.LastWriteTime -lt $Held.CreatedAt) {
                    $VSSPath = Get-VSSPath -SymlinkPath $Held.SymlinkPath -FullName $File.FullName
                    if (Test-Path -LiteralPath $VSSPath) {
                        $ReadPath = $VSSPath
                        $FromVSS  = $true
                    }
                }

                # 2. Copy (snapshot first, live file as fallback)
                $CopySuccess = $false
                $LastError   = $null
                foreach ($Candidate in @($ReadPath, $File.FullName) | Select-Object -Unique) {
                    try {
                        Copy-Item -LiteralPath $Candidate -Destination $TargetFile -Force -ErrorAction Stop
                        $CopySuccess = $true
                        if ($Candidate -ne $ReadPath) { $FromVSS = $false }
                        break
                    } catch {
                        $LastError = $_.Exception.Message
                    }
                }

                if (-not $CopySuccess) {
                    Write-Host "[$TimeLabel] SKIPPED: $($File.Name) - $LastError" -ForegroundColor Yellow
                    Add-Content -LiteralPath $LogFile -Value "[SKIPPED] $($File.FullName): $LastError" -Encoding UTF8
                    $SkippedFiles++
                    continue
                }

                $Source = if ($FromVSS) { "VSS" } else { "live" }
                Write-Host "[$TimeLabel] Backed up ($Source): $($File.Name)" -ForegroundColor Green
                Add-Content -LiteralPath $LogFile -Value "[OK:$Source] $($File.FullName) -> $TargetFile" -Encoding UTF8
                $CopiedFiles++
            }

            Add-Content -LiteralPath $LogFile -Value "$(Get-Date) - $Counter processed, $CopiedFiles copied, $SkippedFiles skipped." -Encoding UTF8
        }

        $LastCheckTime = $CurrentCheckTime
        Start-Sleep -Seconds $IntervalSeconds
    }
} finally {
    # Release held snapshots on Ctrl+C / script break
    Write-Host "`nReleasing held VSS snapshots..." -ForegroundColor Yellow

    foreach ($Held in @($HeldVSS.Values)) {
        if (Test-Path $Held.SymlinkPath) {
            cmd /c rd "$($Held.SymlinkPath)" 2>$null
        }
        Remove-ShadowCopy -ShadowID $Held.ShadowID
        Write-Host "VSS Snapshot $($Held.ShadowID) released." -ForegroundColor Gray
    }
    $HeldVSS.Clear()

    if (Test-Path -LiteralPath $HeldStateFile) {
        Remove-Item -LiteralPath $HeldStateFile -Force
    }

    if (Test-Path $SymlinkBaseFolder) {
        Get-ChildItem -Path $SymlinkBaseFolder | ForEach-Object {
            cmd /c rd "$($_.FullName)" 2>$null
        }
    }

    Write-Host "Backup script exited safely." -ForegroundColor Cyan
}