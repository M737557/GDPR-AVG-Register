<#
.SYNOPSIS
    VSS-based, CRC-64-calculated mirror backup (no robocopy). Destination is
    hard-locked to F:\versioningbackup and each source is mirrored under its
    exact drive-letter + full path.

.DESCRIPTION
    Example mappings (DestinationRoot = F:\versioningbackup):
        C:\xampp3\htdocs\                 -> F:\versioningbackup\C\xampp3\htdocs
        C:\users\maev                     -> F:\versioningbackup\C\users\maev
        C:\scripts\                       -> F:\versioningbackup\C\scripts
        C:\windows10\pri\Mijnbestanden\   -> F:\versioningbackup\C\windows10\pri\Mijnbestanden

    How a run works:
      1. VSS snapshot of the source volume(s), so open/locked files are captured
      2. LIST  : all source files and all files already in F:\versioningbackup
                 (names, sizes, dates only - nothing is read yet)
      3. CRC-64   : only files that CAN have a duplicate are hashed: a file whose
                 size occurs nowhere else is unique for sure and is hashed for
                 free while it is copied. Hashes are cached between runs.
                 Source and backup are hashed at the same time, multi-threaded.
      4. PLAN  : every source file is compared with the backup:
                   same path, same CRC-64          -> nothing to do
                   same CRC-64 elsewhere in backup -> NOT copied; hard link to the
                                                   existing copy (0 bytes extra)
                   same path, different content -> old copy moved to
                                                   _versions\<run>\..., new one stored
                   content not in the backup    -> unique, will be copied
      5. The plan (how many files / GB will really be written) is shown
      6. SYNC  : the plan is executed; files that no longer exist at the source
                 are removed from the mirror (never from _versions)

    - Files are read with backup semantics (SeBackupPrivilege /
      SeRestorePrivilege), so file ACLs don't block reads
    - Each file is written to a temp file first, then moved in (without
      replace), so a failed copy never leaves a half-written file in the backup
    - Failures are logged as HASH / READ / WRITE / REPLACE / LINK / VERSION
    - Refuses to run if destination is not exactly F:\versioningbackup

.NOTES
    Run as Administrator. F: must be NTFS for hard links.
    The first run hashes everything (source + existing backup) once; after
    that only new/changed files are read, thanks to the hash cache.
    The C# helper types are compiled per code version, so the script can be
    run again (also after changes) in the same PowerShell window.
#>

# ============================================================
# CONFIGURATION
# ============================================================

$SourceDirs = @(
    "C:\xampp3\htdocs\"
    "C:\users\maev"
    "C:\scripts\"
    "C:\windows10\pri\Mijnbestanden\"
)

# Destination is LOCKED to this exact path.
$DestinationRoot = "F:\versioningbackup"

# Warn (but continue) when less than this would remain free after the backup.
$MinFreeAfterBackup = 20GB

# Maximum number of copies of this script running at the same time.
$MaxInstances = 2

# VSS retry: how long to wait when another shadow copy is being created
$VssRetryAttempts = 20      # 20 x 30s = up to 10 minutes of waiting
$VssRetryDelaySec = 30

# If VSS still can't be used after all retries, copy from the live disk
# instead of aborting. Locked/open files will then fail to copy.
$FallbackWithoutVss = $true

# Per-file retries (not used for "access denied", which never fixes itself)
$CopyRetries       = 2
$CopyRetryDelaySec = 5

# Excluded names (case-insensitive). Excluded items are also never deleted
# at the destination.
$ExcludeDirs  = @('$RECYCLE.BIN', 'System Volume Information')
$ExcludeFiles = @('pagefile.sys', 'hiberfil.sys', 'swapfile.sys')

$LogDir  = "F:\BackupLogs"
$RunStamp = "{0:yyyy-MM-dd_HH-mm-ss}" -f (Get-Date)
$LogFile  = Join-Path $LogDir ("Backup_{0}.log" -f $RunStamp)

# EFS-encrypted files can't be read in backup mode. Skip them (not counted as
# failures, existing copies at the destination are kept) and list them in a
# CSV (; separated for Dutch Excel). Encrypted folders are still backed up.
$SkipEncrypted   = $true
$EncryptedReport = Join-Path $LogDir ("Backup_{0}_ENCRYPTED.csv" -f $RunStamp)

# ---------------- CRC-64 comparison / deduplication ----------------

# The script never waits for input: the plan is shown in the console/log and
# executed immediately. It only aborts if the unique files don't fit on F:.

# Source file whose content (CRC-64) already exists somewhere in the backup,
# but not at its own path:
#   'HardLink' : NTFS hard link to the existing copy. Uses no extra space and
#                the file is still at its correct path for a restore.
#   'Skip'     : store nothing (the file is then missing at its own path).
$DuplicateMode = 'HardLink'

# File at the same path whose content has changed:
#   'Version'  : move the old copy to _versions\<run>\..., store the new one
#   'Ignore'   : keep the old copy, the change is NOT backed up
$ChangedFiles = 'Version'
$VersionsRoot = Join-Path $DestinationRoot ("_versions\" + $RunStamp)

# CRC-64 cache: a file with the same path, size and modify time is not read
# again. Set to $false once to force a full re-hash of source and backup.
$TrustHashCache = $true
$HashCacheFile  = Join-Path $LogDir "hashcache-crc64.tsv"

# Parallel CRC-64: how many files are hashed at the same time, per disk.
# SSD/NVMe: 8 is fast, especially with many small files (every file open
# waits for VSS + the virus scanner, more threads hide that waiting).
# USB/external HARD DISK: keep 1, parallel reads make a spinning disk seek
# back and forth and become slower.
# Source (C:) and backup (F:) are hashed at the same time.
$HashThreadsSource = [Math]::Max(4, [Math]::Min(8, [Environment]::ProcessorCount))
$HashThreadsDest   = 1

# $false = don't force every single file to disk (much faster with many
# small files, especially on USB); F: is flushed once at the end instead.
# $true  = flush after every file (slow, safest against power loss mid-run).
$FlushEachFile = $false

# ============================================================
# NATIVE BACKUP-SEMANTICS FILE I/O
# ============================================================

# The C# code gets its own namespace, named after a hash of the code itself.
# So running a changed version of this script in the same PowerShell window
# never collides with types from an older version ("type already exists").
$CSharpSource = @'
using System;
using System.ComponentModel;
using System.IO;
using System.Runtime.InteropServices;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using Microsoft.Win32.SafeHandles;

namespace __NS__ {

public class BackupIOException : IOException
{
    public string Side;
    public int Win32Error;
    public BackupIOException(string side, int err, string path)
        : base(side + " failed (" + err + ": " + new Win32Exception(err).Message + "): " + path)
    { Side = side; Win32Error = err; }
}

// CRC-64/XZ (ECMA-182 polynomial, reflected), slicing-by-8.
// Not cryptography, so Windows FIPS mode does not matter.
// Files are only compared when their SIZE is equal as well, so an accidental
// CRC-64 collision is practically impossible.
public sealed class Crc64
{
    const ulong Poly = 0xC96C5795D7870F42UL;
    static readonly ulong[] T = BuildTable();
    ulong crc = ulong.MaxValue;

    static ulong[] BuildTable()
    {
        var t = new ulong[8 * 256];
        for (int i = 0; i < 256; i++)
        {
            ulong c = (ulong)i;
            for (int k = 0; k < 8; k++) c = (c & 1) != 0 ? (c >> 1) ^ Poly : c >> 1;
            t[i] = c;
        }
        for (int i = 0; i < 256; i++)
            for (int k = 1; k < 8; k++)
                t[k * 256 + i] = (t[(k - 1) * 256 + i] >> 8) ^ t[(int)(t[(k - 1) * 256 + i] & 0xFF)];
        return t;
    }

    public void Update(byte[] buf, int off, int n)
    {
        ulong c = crc;
        ulong[] t = T;
        int end = off + n;
        while (end - off >= 8)
        {
            ulong v = c ^ ((ulong)buf[off]
                        | ((ulong)buf[off + 1] << 8)  | ((ulong)buf[off + 2] << 16) | ((ulong)buf[off + 3] << 24)
                        | ((ulong)buf[off + 4] << 32) | ((ulong)buf[off + 5] << 40) | ((ulong)buf[off + 6] << 48)
                        | ((ulong)buf[off + 7] << 56));
            c = t[1792 + (int)(v & 0xFF)]         ^ t[1536 + (int)((v >> 8) & 0xFF)]
              ^ t[1280 + (int)((v >> 16) & 0xFF)] ^ t[1024 + (int)((v >> 24) & 0xFF)]
              ^ t[768  + (int)((v >> 32) & 0xFF)] ^ t[512  + (int)((v >> 40) & 0xFF)]
              ^ t[256  + (int)((v >> 48) & 0xFF)] ^ t[(int)(v >> 56)];
            off += 8;
        }
        while (off < end) c = t[(int)((c ^ buf[off++]) & 0xFF)] ^ (c >> 8);
        crc = c;
    }

    // 16 uppercase hex chars
    public string Finish() { return (~crc).ToString("X16"); }

    public static string Hash(Stream s)
    {
        var h   = new Crc64();
        var buf = new byte[1 << 20];
        int n;
        while ((n = s.Read(buf, 0, buf.Length)) > 0) h.Update(buf, 0, n);
        return h.Finish();
    }
}

// Hashes a list of files on background threads (parallel per file).
// PowerShell polls DoneFiles/DoneBytes for progress.
public sealed class HashJob
{
    public readonly string[] Paths;
    public readonly long[]   Lengths;
    public readonly string[] Results;
    public readonly string[] Errors;
    long doneFiles, doneBytes, okFiles, okBytes;
    public long OkFiles { get { return Interlocked.Read(ref okFiles); } }
    public long OkBytes { get { return Interlocked.Read(ref okBytes); } }
    public long DoneFiles { get { return Interlocked.Read(ref doneFiles); } }
    public long DoneBytes { get { return Interlocked.Read(ref doneBytes); } }
    readonly CancellationTokenSource cts = new CancellationTokenSource();
    readonly Task task;
    readonly System.Diagnostics.Stopwatch sw = System.Diagnostics.Stopwatch.StartNew();
    public double ElapsedSeconds { get { return sw.Elapsed.TotalSeconds; } }

    public HashJob(string[] paths, long[] lengths, int threads)
    {
        Paths = paths; Lengths = lengths;
        Results = new string[paths.Length];
        Errors  = new string[paths.Length];
        if (threads < 1) threads = 1;
        task = Task.Factory.StartNew(() =>
        {
            try
            {
                if (threads == 1)
                {
                    for (int i = 0; i < Paths.Length; i++)   // strictly in order: best for a hard disk
                    {
                        cts.Token.ThrowIfCancellationRequested();
                        One(i);
                    }
                }
                else
                {
                    var opt = new ParallelOptions { MaxDegreeOfParallelism = threads, CancellationToken = cts.Token };
                    Parallel.For(0, Paths.Length, opt, One);
                }
            }
            catch (OperationCanceledException) { }
            finally { sw.Stop(); }
        }, TaskCreationOptions.LongRunning);
    }

    void One(int i)
    {
        try
        {
            Results[i] = BackupIO.Crc(Paths[i]);
            Interlocked.Increment(ref okFiles);
            Interlocked.Add(ref okBytes, Lengths[i]);
        }
        catch (Exception e) { Errors[i] = (e.InnerException ?? e).Message; }
        Interlocked.Increment(ref doneFiles);
        Interlocked.Add(ref doneBytes, Lengths[i]);
    }

    public bool Wait(int ms) { return task.Wait(ms); }

    // Stores all results in the cache dictionary; returns indexes that failed.
    public int[] MergeInto(Dictionary<string, string> cache, string[] keys)
    {
        var failed = new List<int>();
        for (int i = 0; i < Results.Length; i++)
        {
            if (Results[i] != null) cache[keys[i]] = Results[i];
            else failed.Add(i);
        }
        return failed.ToArray();
    }
    public void Cancel()     { cts.Cancel(); }
}

public static class BackupIO
{
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern SafeFileHandle CreateFileW(string name, uint access, uint share, IntPtr sa,
        uint disposition, uint flags, IntPtr template);
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern bool SetFileTime(SafeFileHandle h, ref long created, ref long accessed, ref long written);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool SetFileAttributesW(string name, uint attrs);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool MoveFileExW(string from, string to, uint flags);
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool DeleteFileW(string name);
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern bool ReadFile(SafeFileHandle h, byte[] buf, int count, out int read, IntPtr overlapped);
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern bool WriteFile(SafeFileHandle h, byte[] buf, int count, out int written, IntPtr overlapped);
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern bool FlushFileBuffers(SafeFileHandle h);

    // One 1 MB buffer per thread, reused for every file (no allocation per file)
    [ThreadStatic] static byte[] tbuf;
    static byte[] Buf() { return tbuf ?? (tbuf = new byte[1 << 20]); }
    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool CreateHardLinkW(string newName, string existing, IntPtr sa);

    [DllImport("advapi32.dll", SetLastError = true)]
    static extern bool OpenProcessToken(IntPtr process, uint access, out IntPtr token);
    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool LookupPrivilegeValueW(string system, string name, out long luid);
    [StructLayout(LayoutKind.Sequential, Pack = 4)]
    struct TOKEN_PRIVILEGES { public int Count; public long Luid; public int Attributes; }
    [DllImport("advapi32.dll", SetLastError = true)]
    static extern bool AdjustTokenPrivileges(IntPtr token, bool disableAll, ref TOKEN_PRIVILEGES state,
        int len, IntPtr prev, IntPtr retLen);
    [DllImport("kernel32.dll")] static extern IntPtr GetCurrentProcess();
    [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr h);
    [DllImport("kernel32.dll")] static extern IntPtr GetStdHandle(int n);
    [DllImport("kernel32.dll")] static extern bool GetConsoleMode(IntPtr h, out uint mode);
    [DllImport("kernel32.dll")] static extern bool SetConsoleMode(IntPtr h, uint mode);

    // Windows console "QuickEdit": one click in the window selects text and
    // FREEZES the script until you press ENTER. Switch it off during the run.
    // Returns the old mode (to restore), or -1 if there is no classic console.
    public static long DisableQuickEdit()
    {
        IntPtr h = GetStdHandle(-10);   // STD_INPUT_HANDLE
        uint mode;
        if (!GetConsoleMode(h, out mode)) return -1;
        SetConsoleMode(h, (mode & ~0x0040u) | 0x0080u);   // -QUICK_EDIT, +EXTENDED_FLAGS
        return mode;
    }

    public static void RestoreConsoleMode(long mode)
    {
        if (mode >= 0) SetConsoleMode(GetStdHandle(-10), (uint)mode);
    }

    const uint GENERIC_READ          = 0x80000000;
    const uint GENERIC_WRITE         = 0x40000000;
    const uint FILE_WRITE_ATTRIBUTES = 0x00000100;
    const uint SHARE_ALL             = 0x00000007;
    const uint OPEN_EXISTING         = 3;
    const uint CREATE_ALWAYS         = 2;
    const uint ATTR_NORMAL           = 0x00000080;
    const uint FLAG_BACKUP           = 0x02000000;
    const uint FLAG_SEQUENTIAL       = 0x08000000;
    const uint MOVE_WRITE_THROUGH    = 0x8;
    const int  ERROR_FILE_EXISTS     = 80;
    const int  ERROR_ALREADY_EXISTS  = 183;

    // Long-path prefix so paths over 260 chars work
    static string L(string p)
    {
        if (p.StartsWith(@"\\?\")) return p;
        if (p.StartsWith(@"\\"))   return @"\\?\UNC\" + p.Substring(2);
        return @"\\?\" + p;
    }

    public static bool EnablePrivilege(string name)
    {
        IntPtr token;
        if (!OpenProcessToken(GetCurrentProcess(), 0x0020 | 0x0008, out token)) return false;
        try
        {
            long luid;
            if (!LookupPrivilegeValueW(null, name, out luid)) return false;
            var tp = new TOKEN_PRIVILEGES { Count = 1, Luid = luid, Attributes = 0x2 };
            if (!AdjustTokenPrivileges(token, false, ref tp, 0, IntPtr.Zero, IntPtr.Zero)) return false;
            return Marshal.GetLastWin32Error() == 0;   // 1300 = not all assigned
        }
        finally { CloseHandle(token); }
    }

    static SafeFileHandle Open(string path, uint access, uint disposition, uint flags, string side)
    {
        var h = CreateFileW(L(path), access, SHARE_ALL, IntPtr.Zero, disposition, flags, IntPtr.Zero);
        if (h.IsInvalid) throw new BackupIOException(side, Marshal.GetLastWin32Error(), path);
        return h;
    }

    // CRC-64 of a file, read with backup semantics. Returns 16 uppercase hex chars.
    public static string Crc(string path)
    {
        using (var h = Open(path, GENERIC_READ, OPEN_EXISTING, FLAG_BACKUP | FLAG_SEQUENTIAL, "HASH"))
        {
            var c   = new Crc64();
            var buf = Buf();
            int n;
            while (true)
            {
                if (!ReadFile(h, buf, buf.Length, out n, IntPtr.Zero))
                    throw new BackupIOException("HASH", Marshal.GetLastWin32Error(), path);
                if (n == 0) break;
                c.Update(buf, 0, n);
            }
            return c.Finish();
        }
    }

    // Self-test: CRC-64 of a string (used to verify the implementation at start)
    public static string CrcText(string text)
    {
        using (var ms = new MemoryStream(System.Text.Encoding.ASCII.GetBytes(text)))
            return Crc64.Hash(ms);
    }

    // Creates newPath as a hard link to existing. Never overwrites.
    // Returns false if newPath already exists.
    public static bool HardLink(string newPath, string existing)
    {
        if (CreateHardLinkW(L(newPath), L(existing), IntPtr.Zero)) return true;
        int err = Marshal.GetLastWin32Error();
        if (err == ERROR_FILE_EXISTS || err == ERROR_ALREADY_EXISTS) return false;
        throw new BackupIOException("LINK", err, newPath + " -> " + existing);
    }

    // Rename/move on the same volume. Fails if 'to' exists.
    public static void MoveNoReplace(string from, string to)
    {
        if (!MoveFileExW(L(from), L(to), MOVE_WRITE_THROUGH))
            throw new BackupIOException("VERSION", Marshal.GetLastWin32Error(), from + " -> " + to);
    }

    // Copies src -> dst via a temp file, sets timestamps + attributes.
    // Calculates the CRC-64 while copying (source is read only once).
    // Never overwrites an existing dst.
    // Returns number of bytes copied, or -1 if dst already existed (skipped).
    public static long CopyFile(string src, string dst, long created, long written, uint attrs,
                                bool flush, out string crc)
    {
        crc = null;
        string tmp = dst + ".vsstmp";
        long len = 0;
        string hash;
        try
        {
            using (var hIn  = Open(src, GENERIC_READ,  OPEN_EXISTING, FLAG_BACKUP | FLAG_SEQUENTIAL, "READ"))
            using (var hOut = Open(tmp, GENERIC_WRITE, CREATE_ALWAYS, FLAG_BACKUP | ATTR_NORMAL,    "WRITE"))
            {
                var c   = new Crc64();
                var buf = Buf();
                int n, w;
                while (true)
                {
                    if (!ReadFile(hIn, buf, buf.Length, out n, IntPtr.Zero))
                        throw new BackupIOException("READ", Marshal.GetLastWin32Error(), src);
                    if (n == 0) break;
                    c.Update(buf, 0, n);
                    if (!WriteFile(hOut, buf, n, out w, IntPtr.Zero) || w != n)
                        throw new BackupIOException("WRITE", Marshal.GetLastWin32Error(), dst);
                    len += n;
                }
                if (flush && !FlushFileBuffers(hOut))
                    throw new BackupIOException("WRITE", Marshal.GetLastWin32Error(), dst);
                hash = c.Finish();
                if (!SetFileTime(hOut, ref created, ref written, ref written))
                    throw new BackupIOException("SETTIME", Marshal.GetLastWin32Error(), dst);
            }
        }
        catch
        {
            DeleteFileW(L(tmp));
            throw;
        }

        crc = hash;
        string ld = L(dst);
        // No MOVE_REPLACE: fails if dst already exists, so nothing is overwritten
        if (!MoveFileExW(L(tmp), ld, MOVE_WRITE_THROUGH))
        {
            int err = Marshal.GetLastWin32Error();
            DeleteFileW(L(tmp));
            if (err == ERROR_FILE_EXISTS || err == ERROR_ALREADY_EXISTS) return -1;
            throw new BackupIOException("REPLACE", err, dst);
        }
        SetFileAttributesW(ld, attrs == 0 ? ATTR_NORMAL : attrs);
        return len;
    }

    // Flushes everything written to a volume to disk in one go (admin only)
    public static void FlushVolume(string letter)
    {
        var h = CreateFileW(@"\\.\" + letter + ":", GENERIC_READ | GENERIC_WRITE, SHARE_ALL, IntPtr.Zero,
                            OPEN_EXISTING, 0, IntPtr.Zero);
        if (h.IsInvalid) throw new BackupIOException("FLUSH", Marshal.GetLastWin32Error(), letter + ":");
        using (h)
        {
            if (!FlushFileBuffers(h)) throw new BackupIOException("FLUSH", Marshal.GetLastWin32Error(), letter + ":");
        }
    }

    public static void SetDirTimes(string path, long created, long written)
    {
        using (var h = Open(path, FILE_WRITE_ATTRIBUTES, OPEN_EXISTING, FLAG_BACKUP, "SETTIME"))
        {
            if (!SetFileTime(h, ref created, ref written, ref written))
                throw new BackupIOException("SETTIME", Marshal.GetLastWin32Error(), path);
        }
    }
}
}
'@

$sha = [System.Security.Cryptography.SHA256]::Create()
$codeHash = ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($CSharpSource))) -replace '-', '').Substring(0, 16)
$TypeNs = "VersioningBackup_$codeHash"
if (-not ("$TypeNs.BackupIO" -as [type])) {
    Add-Type -TypeDefinition $CSharpSource.Replace('__NS__', $TypeNs)
}
$BackupIO   = "$TypeNs.BackupIO"          -as [type]
$BackupIOEx = "$TypeNs.BackupIOException" -as [type]
$HashJobT   = "$TypeNs.HashJob"           -as [type]

# Attributes carried over to the backup copy
$AttrMask = [IO.FileAttributes]::ReadOnly -bor [IO.FileAttributes]::Hidden -bor `
            [IO.FileAttributes]::System   -bor [IO.FileAttributes]::Archive -bor `
            [IO.FileAttributes]::NotContentIndexed

# ============================================================
# HELPERS
# ============================================================

# The log file stays open for the whole run (opening/closing it for every
# line was very slow with thousands of files, especially on F:).
$script:LogWriter = $null
function Get-LogWriter {
    if (-not $script:LogWriter) {
        $script:LogWriter = New-Object IO.StreamWriter($LogFile, $true, (New-Object Text.UTF8Encoding($false)))
    }
    return $script:LogWriter
}
function Close-Log {
    if ($script:LogWriter) { try { $script:LogWriter.Dispose() } catch {} ; $script:LogWriter = $null }
}

function Write-Log {
    param([string]$Message, [string]$Level = "INFO")
    $line = "{0} [{1}] {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Level, $Message
    Write-Host $line
    $w = Get-LogWriter
    $w.WriteLine($line)
    $w.Flush()
}

# Per-file lines: log file only, keeps the console readable
function Write-LogFile {
    param([string]$Message)
    (Get-LogWriter).WriteLine(("{0} [FILE] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message))
}

function Format-Size {
    param([int64]$Bytes)
    if ($Bytes -lt 0)   { return "-" + (Format-Size (-$Bytes)) }
    if ($Bytes -ge 1TB) { return "{0:N2} TB" -f ($Bytes / 1TB) }
    if ($Bytes -ge 1GB) { return "{0:N2} GB" -f ($Bytes / 1GB) }
    if ($Bytes -ge 1MB) { return "{0:N2} MB" -f ($Bytes / 1MB) }
    if ($Bytes -ge 1KB) { return "{0:N2} KB" -f ($Bytes / 1KB) }
    return "$Bytes B"
}

# Map a source path to its exact destination path under F:\versioningbackup.
function Get-MirrorDestination {
    param(
        [Parameter(Mandatory)][string]$SourcePath,
        [Parameter(Mandatory)][string]$DestinationRoot
    )
    $full = (Resolve-Path -LiteralPath $SourcePath).Path.TrimEnd('\')
    if ($full -notmatch '^([A-Za-z]):\\(.*)$') {
        throw "Source path must be drive-qualified (e.g. C:\...): $SourcePath"
    }
    $driveLetter = $Matches[1].ToUpper()
    $remainder   = $Matches[2]
    if ([string]::IsNullOrWhiteSpace($remainder)) {
        return (Join-Path $DestinationRoot $driveLetter)
    }
    return (Join-Path $DestinationRoot (Join-Path $driveLetter $remainder))
}

# Classifies a source entry the same way for the plan and the sync.
function Get-EntryKind {
    param([IO.FileSystemInfo]$Entry)
    $isDir = [bool]($Entry.Attributes -band [IO.FileAttributes]::Directory)
    if ( ($isDir -and $ExcludeDirs -contains $Entry.Name) -or
         (-not $isDir -and $ExcludeFiles -contains $Entry.Name) ) { return 'Excluded' }
    if ($Entry.Attributes -band [IO.FileAttributes]::ReparsePoint) { return 'Link' }
    if (-not $isDir -and $SkipEncrypted -and
        ($Entry.Attributes -band [IO.FileAttributes]::Encrypted)) { return 'EncryptedFile' }
    if ($isDir) { return 'Dir' }
    return 'File'
}

# ============================================================
# CRC-64 HASHING, CACHE AND BACKUP INDEX
# ============================================================
#
# Speed-ups:
#  - Only files whose SIZE occurs more than once (source vs backup, or twice
#    in the source) can be duplicates, so only those are hashed up front.
#    A file with a unique size is unique for sure and is hashed for free
#    while it is copied.
#  - Hash cache: same path + size + modify time = CRC-64 not read again.
#  - Hashing runs on background threads, source and backup at the same time.
#  - Table-driven CRC-64 (slicing-by-8), 1 MB read buffers.

# Cache key = "<size>|<modify ticks UTC>|<full path>", value = CRC-64.
# $HashCache     : loaded from disk (previous runs)
# $HashCacheUsed : every hash known in this run (always trusted)
# $DestIndex     : CRC-64 -> one path in the backup that has that content
$HashCache     = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([StringComparer]::OrdinalIgnoreCase)
$HashCacheUsed = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([StringComparer]::OrdinalIgnoreCase)
$DestIndex     = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([StringComparer]::Ordinal)
$SeenPaths     = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
$SeenKeys      = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
$HashStats     = @{ Hashed = 0; HashedBytes = [int64]0; CacheHits = 0; HashSeconds = 0.0 }

# Flat lists (faster than one object per file)
$DL = @{ Path = New-Object 'System.Collections.Generic.List[string]'
         Len  = New-Object 'System.Collections.Generic.List[long]'
         Tick = New-Object 'System.Collections.Generic.List[long]'
         Key  = New-Object 'System.Collections.Generic.List[string]' }
$SL = @{ Read   = New-Object 'System.Collections.Generic.List[string]'
         Orig   = New-Object 'System.Collections.Generic.List[string]'
         Target = New-Object 'System.Collections.Generic.List[string]'
         Len    = New-Object 'System.Collections.Generic.List[long]'
         Tick   = New-Object 'System.Collections.Generic.List[long]'
         Key    = New-Object 'System.Collections.Generic.List[string]' }

$FA_Dir     = [IO.FileAttributes]::Directory
$FA_Reparse = [IO.FileAttributes]::ReparsePoint

function Resolve-CachedCrc {
    param([string]$Key)
    $h = $null
    if ($HashCacheUsed.TryGetValue($Key, [ref]$h)) { return $h }
    if ($TrustHashCache -and $HashCache.TryGetValue($Key, [ref]$h)) {
        $HashCacheUsed[$Key] = $h
        $HashStats.CacheHits++
        return $h
    }
    return $null
}

# CRC-64 if already known (cache / this run), else $null. Never reads the file.
function Get-KnownCrc {
    param([string]$KeyPath, [int64]$Length, [int64]$Ticks)
    return (Resolve-CachedCrc "$Length|$Ticks|$KeyPath")
}

# CRC-64, reading the file if not known yet.
function Get-CachedCrc {
    param([string]$ReadPath, [string]$KeyPath, [int64]$Length, [int64]$Ticks)
    $key = "$Length|$Ticks|$KeyPath"
    $h = Resolve-CachedCrc $key
    if ($h) { return $h }
    $h = $BackupIO::Crc($ReadPath)
    $HashStats.Hashed++
    $HashStats.HashedBytes += $Length
    $HashCacheUsed[$key] = $h
    return $h
}

# Remember the hash of a file this script just wrote/linked/moved,
# so it never has to be read again.
function Set-KnownHash {
    param([string]$Path, [string]$Crc)
    try {
        $fi = [IO.FileInfo]::new($Path)
        $HashCacheUsed["$($fi.Length)|$($fi.LastWriteTimeUtc.Ticks)|$Path"] = $Crc
    } catch {}
}

function Read-HashCacheFile {
    $d = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([StringComparer]::OrdinalIgnoreCase)
    if (Test-Path -LiteralPath $HashCacheFile) {
        foreach ($line in [IO.File]::ReadLines($HashCacheFile)) {
            if ($line.Length -gt 17 -and $line[16] -eq "`t") {
                $d[$line.Substring(17)] = $line.Substring(0, 16)
            }
        }
    }
    return ,$d
}

# Saves the cache: everything known this run, plus older entries for files
# that still exist and were not seen with a different size/time this run
# (e.g. sources that are commented out right now).
function Export-HashCache {
    $lock = Wait-NamedLock -Name "Global\VersioningBackup_HashCache" -TimeoutSec 300
    try {
        $onDisk = Read-HashCacheFile
        $tmp = "$HashCacheFile.$PID.tmp"
        $w = New-Object IO.StreamWriter($tmp, $false, (New-Object Text.UTF8Encoding($false)))
        $count = 0
        try {
            foreach ($kv in $HashCacheUsed.GetEnumerator()) {
                $w.Write($kv.Value); $w.Write("`t"); $w.WriteLine($kv.Key); $count++
            }
            foreach ($kv in $onDisk.GetEnumerator()) {
                if ($HashCacheUsed.ContainsKey($kv.Key)) { continue }
                $p = $kv.Key.Split([char]'|', 3)[2]
                if ($SeenPaths.Contains($p) -and -not $SeenKeys.Contains($kv.Key)) { continue }
                if (-not [IO.File]::Exists($p)) { continue }
                $w.Write($kv.Value); $w.Write("`t"); $w.WriteLine($kv.Key); $count++
            }
        }
        finally { $w.Dispose() }

        if ([IO.File]::Exists($HashCacheFile)) { [IO.File]::Replace($tmp, $HashCacheFile, [NullString]::Value) }
        else { [IO.File]::Move($tmp, $HashCacheFile) }
        return $count
    }
    finally { Unlock-NamedLock $lock }
}

# Lists every file already in the backup (incl. _versions). No reading.
function Build-DestList {
    param([string]$Dir)
    try { $entries = @(([IO.DirectoryInfo]$Dir).EnumerateFileSystemInfos()) }
    catch {
        Write-Log "Cannot list $Dir : $($_.Exception.GetBaseException().Message)" "WARN"
        return
    }
    foreach ($e in $entries) {
        $a = $e.Attributes
        if ($a -band $FA_Reparse) { continue }
        if ($a -band $FA_Dir) { Build-DestList -Dir $e.FullName; continue }
        if ($e.Name.EndsWith('.vsstmp', [StringComparison]::OrdinalIgnoreCase)) { continue }
        $DL.Path.Add($e.FullName)
        $DL.Len.Add($e.Length)
        $DL.Tick.Add($e.LastWriteTimeUtc.Ticks)
        if ($DL.Path.Count % 1000 -eq 0) {
            Write-Progress -Activity "Listing backup" -Status "$($DL.Path.Count) files"
        }
    }
}

# Lists every source file (from the snapshot) + what the mirror would delete.
function Plan-Tree {
    param([string]$Src, [string]$Dst, $Plan)

    try { $entries = @(([IO.DirectoryInfo]$Src).EnumerateFileSystemInfos()) }
    catch {
        $Plan.Unreadable++
        Write-Log "Cannot read folder $Src : $($_.Exception.GetBaseException().Message)" "WARN"
        return
    }

    $keep = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($e in $entries) {
        $kind = Get-EntryKind $e
        if ($kind -eq 'Link') { continue }
        [void]$keep.Add($e.Name)
        $target = [IO.Path]::Combine($Dst, $e.Name)
        if ($kind -eq 'Dir') { Plan-Tree -Src $e.FullName -Dst $target -Plan $Plan }
        elseif ($kind -eq 'File') {
            $SL.Read.Add($e.FullName)
            $SL.Orig.Add($Plan.Root + $e.FullName.Substring($Plan.ShadowRoot.Length))
            $SL.Target.Add($target)
            $SL.Len.Add($e.Length)
            $SL.Tick.Add($e.LastWriteTimeUtc.Ticks)
            if ($SL.Read.Count % 1000 -eq 0) {
                Write-Progress -Activity "Listing sources" -Status "$($SL.Read.Count) files"
            }
        }
    }

    if ([IO.Directory]::Exists($Dst)) {
        try {
            foreach ($d in [IO.Directory]::GetFileSystemEntries($Dst)) {
                if (-not $keep.Contains([IO.Path]::GetFileName($d))) { $Plan.DeleteList.Add($d) }
            }
        } catch {}
    }
}

function New-HashBatch {
    param([string]$Label, [int]$Threads)
    return @{
        Label = $Label; Threads = $Threads; Total = [int64]0; Failed = 0; Job = $null
        Read  = New-Object 'System.Collections.Generic.List[string]'
        Keys  = New-Object 'System.Collections.Generic.List[string]'
        Lens  = New-Object 'System.Collections.Generic.List[long]'
    }
}

# Runs all batches at the same time (each on its own threads) with progress.
function Invoke-HashBatches {
    param([object[]]$Batches)
    $active = @($Batches | Where-Object { $_.Read.Count -gt 0 })
    if ($active.Count -eq 0) { return }

    $sw = [Diagnostics.Stopwatch]::StartNew()
    foreach ($b in $active) {
        foreach ($l in $b.Lens) { $b.Total += $l }
        Write-Log ("CRC-64 {0}: {1} files, {2}, {3} thread(s)" -f $b.Label, $b.Read.Count, (Format-Size $b.Total), $b.Threads)
        $b.Job = $HashJobT::new($b.Read.ToArray(), $b.Lens.ToArray(), $b.Threads)
    }
    try {
        while ($true) {
            $allDone = $true
            for ($i = 0; $i -lt $active.Count; $i++) {
                $b = $active[$i]
                if (-not $b.Job.Wait(0)) { $allDone = $false }
                $done = $b.Job.DoneBytes
                $el   = [Math]::Max(0.001, $b.Job.ElapsedSeconds)
                $pct  = if ($b.Total -gt 0) { [Math]::Min(100, [int](100 * $done / $b.Total)) } else { 100 }
                $rate = $done / $el
                $eta  = if ($rate -gt 0) { [int](($b.Total - $done) / $rate) } else { -1 }
                Write-Progress -Id ($i + 1) -Activity "CRC-64 $($b.Label) (copying starts when this is done)" `
                    -PercentComplete $pct -SecondsRemaining $eta -Status ("{0}/{1} files ({2:N0}/s), {3} of {4}, {5}/s" -f `
                    $b.Job.DoneFiles, $b.Read.Count, ($b.Job.DoneFiles / $el), (Format-Size $done), (Format-Size $b.Total),
                    (Format-Size ([int64]$rate)))
            }
            if ($allDone) { break }
            Start-Sleep -Milliseconds 500
        }
    }
    finally {
        # Also on CTRL+C: stop the background threads
        for ($i = 0; $i -lt $active.Count; $i++) {
            if (-not $active[$i].Job.Wait(0)) { $active[$i].Job.Cancel(); [void]$active[$i].Job.Wait(15000) }
            Write-Progress -Id ($i + 1) -Activity "CRC-64 $($active[$i].Label)" -Completed
        }
    }

    foreach ($b in $active) {
        $failed = $b.Job.MergeInto($HashCacheUsed, $b.Keys.ToArray())
        $HashStats.Hashed      += $b.Job.OkFiles
        $HashStats.HashedBytes += $b.Job.OkBytes
        $b.Failed = $failed.Count
        foreach ($i in $failed) {
            Write-Log "Cannot hash $($b.Keys[$i].Split([char]'|', 3)[2]) : $($b.Job.Errors[$i])" "WARN"
        }
    }
    $HashStats.HashSeconds += $sw.Elapsed.TotalSeconds
    Write-Log ("CRC-64 done in {0:N1}s: {1} read ({2}/s)" -f $sw.Elapsed.TotalSeconds, (Format-Size $HashStats.HashedBytes),
        (Format-Size ([int64]($HashStats.HashedBytes / [Math]::Max(0.001, $sw.Elapsed.TotalSeconds)))))
}

# Returns a path in the backup that really has this CRC-64, or $null.
function Find-ExistingCopy {
    param([string]$Crc)
    $p = $null
    if (-not $DestIndex.TryGetValue($Crc, [ref]$p)) { return $null }
    try {
        $fi = [IO.FileInfo]::new($p)
        if ($fi.Exists -and
            (Get-CachedCrc -ReadPath $p -KeyPath $p -Length $fi.Length -Ticks $fi.LastWriteTimeUtc.Ticks) -eq $Crc) {
            return $p
        }
    } catch {}
    [void]$DestIndex.Remove($Crc)
    return $null
}

# ============================================================
# MIRROR ENGINE (replaces robocopy /MIR)
# ============================================================

# Deletes a file or folder at the destination. Never follows links,
# never touches anything outside $DestinationRoot.
function Remove-DestItem {
    param([string]$Path, $Stats)

    if (-not $Path.StartsWith($DestinationRoot + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to delete outside $DestinationRoot : $Path"
    }
    try {
        $attr  = [IO.File]::GetAttributes($Path)
        $isDir = [bool]($attr -band [IO.FileAttributes]::Directory)
        $isLnk = [bool]($attr -band [IO.FileAttributes]::ReparsePoint)

        if ($isDir -and -not $isLnk) {
            foreach ($child in [IO.Directory]::GetFileSystemEntries($Path)) {
                Remove-DestItem -Path $child -Stats $Stats
            }
        }
        [IO.File]::SetAttributes($Path, [IO.FileAttributes]::Normal)
        if ($isDir) { [IO.Directory]::Delete($Path, $false) }   # on a link: removes only the link
        else        { [IO.File]::Delete($Path) }

        $Stats.Deleted++
        Write-LogFile "Deleted: $Path"
    }
    catch {
        Write-Log "Could not delete $Path : $($_.Exception.GetBaseException().Message)" "WARN"
    }
}

function Add-Failure {
    param($Stats, [string]$Message)
    $Stats.Failed++
    $Stats.FailedList.Add($Message)
    Write-Log "Failed: $Message" "ERROR"
}

function Sync-OneFile {
    param([IO.FileInfo]$File, [string]$Target, $Stats)

    $Stats.Files++
    if ($Stats.Files -eq 1 -or $Stats.ProgressTimer.ElapsedMilliseconds -ge 250) {
        $Stats.ProgressTimer.Restart()
        $el = [Math]::Max(0.001, $Stats.Timer.Elapsed.TotalSeconds)
        Write-Progress -Activity "Copying $($Stats.Root) -> F:" `
            -PercentComplete ([Math]::Min(100, [int](100 * $Stats.Files / [Math]::Max(1, $Stats.Expected)))) `
            -Status ("{0}/{1} files, {2} copied ({3}, {4}/s), {5} linked, {6} unchanged, {7} failed" -f `
                $Stats.Files, $Stats.Expected, $Stats.Copied, (Format-Size $Stats.Bytes),
                (Format-Size ([int64]($Stats.Bytes / $el))), $Stats.Linked, $Stats.Same, $Stats.Failed)
    }

    $origPath = $Stats.Root + $File.FullName.Substring($Stats.ShadowRoot.Length)
    $len   = $File.Length
    $ticks = $File.LastWriteTimeUtc.Ticks
    # Known from the plan, or $null when the size is unique (then it is
    # unique content for sure; the CRC-64 is calculated while copying)
    $crc = Get-KnownCrc -KeyPath $origPath -Length $len -Ticks $ticks
    $newLinkGroup = $false

    # --- 1. Something already at this exact path? ---
    if ([IO.File]::Exists($Target)) {
        $ti   = [IO.FileInfo]::new($Target)
        $dcrc = Get-KnownCrc -KeyPath $Target -Length $ti.Length -Ticks $ti.LastWriteTimeUtc.Ticks

        if ($ti.Length -eq $len) {
            # Same size: compare CRC-64 (normally both already known from the plan)
            try {
                if (-not $crc)  { $crc  = Get-CachedCrc -ReadPath $File.FullName -KeyPath $origPath -Length $len -Ticks $ticks }
                if (-not $dcrc) { $dcrc = Get-CachedCrc -ReadPath $Target -KeyPath $Target -Length $ti.Length -Ticks $ti.LastWriteTimeUtc.Ticks }
            }
            catch {
                Add-Failure $Stats ("{0} [source attributes: {1}]" -f $_.Exception.GetBaseException().Message, $File.Attributes)
                return
            }
            if ($dcrc -eq $crc) { $Stats.Same++; return }     # identical: nothing to do
        }
        # Different size = changed for sure, no need to read anything

        if ($ChangedFiles -ne 'Version') {
            $Stats.ChangedIgnored++
            Write-LogFile "Changed, NOT backed up (ChangedFiles=Ignore): $Target"
            return
        }

        # Keep the old content: move it to _versions\<run>\... (never overwritten)
        $verPath = Join-Path $VersionsRoot $Target.Substring($DestinationRoot.Length + 1)
        try {
            [void][IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($verPath))
            $BackupIO::MoveNoReplace($Target, $verPath)
        }
        catch {
            Add-Failure $Stats $_.Exception.GetBaseException().Message
            return
        }
        if ($dcrc) {
            $DestIndex[$dcrc] = $verPath
            Set-KnownHash -Path $verPath -Crc $dcrc
        }
        $Stats.Versioned++
        Write-LogFile "Old version moved: $Target -> $verPath"
    }

    # --- 2. Same content already somewhere in the backup? Don't copy. ---
    if ($crc) {
        $existing = Find-ExistingCopy -Crc $crc
        if ($existing) {
            if ($DuplicateMode -eq 'Skip') {
                $Stats.Deduped++
                $Stats.SavedBytes += $len
                Write-LogFile "Duplicate, not stored (same CRC-64 as $existing): $origPath"
                return
            }
            try {
                if ($BackupIO::HardLink($Target, $existing)) {
                    $Stats.Linked++
                    $Stats.SavedBytes += $len
                    Set-KnownHash -Path $Target -Crc $crc
                    Write-LogFile "Linked: $Target -> $existing"
                }
                else {
                    $Stats.Skipped++
                    Write-LogFile "Exists, not overwritten: $Target"
                }
                return
            }
            catch {
                $lex = $_.Exception.GetBaseException()
                if ($lex -is $BackupIOEx -and $lex.Win32Error -eq 1142) {
                    # NTFS allows max 1023 hard links per file. Store one new
                    # copy; the next duplicates will link to that copy.
                    $newLinkGroup = $true
                    $Stats.LinkLimit++
                    Write-LogFile "Link limit (1023) reached for $existing - storing a new copy: $Target"
                }
                else {
                    Write-Log ("Hard link failed, copying instead: {0}" -f $lex.Message) "WARN"
                }
            }
        }
    }

    # --- 3. Unique content: copy it (CRC-64 is calculated during the copy). ---
    for ($attempt = 0; ; $attempt++) {
        try {
            $copyCrc = $null
            $n = $BackupIO::CopyFile(
                $File.FullName, $Target,
                $File.CreationTimeUtc.ToFileTimeUtc(),
                $File.LastWriteTimeUtc.ToFileTimeUtc(),
                [uint32]($File.Attributes -band $AttrMask),
                $FlushEachFile,
                [ref]$copyCrc)
            if ($n -lt 0) {
                $Stats.Skipped++
                Write-LogFile "Exists, not overwritten: $Target"
                return
            }
            $Stats.Copied++
            $Stats.Bytes += $n
            $HashCacheUsed["$len|$ticks|$origPath"] = $copyCrc
            if ($newLinkGroup -or -not $DestIndex.ContainsKey($copyCrc)) { $DestIndex[$copyCrc] = $Target }
            Set-KnownHash -Path $Target -Crc $copyCrc
            Write-LogFile "Copied: $Target"
            return
        }
        catch {
            $ex   = $_.Exception.GetBaseException()
            $code = if ($ex -is $BackupIOEx) { $ex.Win32Error } else { 0 }

            if ($code -ne 5 -and $attempt -lt $CopyRetries) {
                Write-Log ("Retry {0}/{1} in {2}s: {3}" -f ($attempt + 1), $CopyRetries, $CopyRetryDelaySec, $ex.Message) "WARN"
                Start-Sleep -Seconds $CopyRetryDelaySec
                continue
            }
            Add-Failure $Stats ("{0} [source attributes: {1}]" -f $ex.Message, $File.Attributes)
            return
        }
    }
}

function Sync-Tree {
    param([string]$Src, [string]$Dst, $Stats)

    $srcInfo = [IO.DirectoryInfo]$Src

    try {
        if (-not [IO.Directory]::Exists($Dst)) { [void][IO.Directory]::CreateDirectory($Dst) }
        $entries = @($srcInfo.EnumerateFileSystemInfos())
    }
    catch {
        # Can't list the source: do NOT touch the destination folder,
        # otherwise the mirror step would delete its whole backup.
        Add-Failure $Stats "Cannot read folder $Src : $($_.Exception.GetBaseException().Message)"
        return
    }

    $keep = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)

    foreach ($e in $entries) {
        $kind = Get-EntryKind $e

        if ($kind -eq 'Excluded') {
            [void]$keep.Add($e.Name)          # excluded: leave destination copy alone
            continue
        }

        if ($kind -eq 'Link') {
            # Junctions/symlinks (e.g. "Application Data" in a profile) are not followed
            $Stats.Links++
            Write-LogFile "Skipped link: $($e.FullName)"
            continue
        }

        if ($SkipEncrypted -and ($e.Attributes -band [IO.FileAttributes]::Encrypted)) {
            $origPath = $Stats.Root + $e.FullName.Substring($Stats.ShadowRoot.Length)
            $Stats.EncryptedList.Add([PSCustomObject]@{
                Type          = if ($kind -eq 'Dir') { 'Folder' } else { 'File' }
                FullName      = $origPath
                Length        = if ($kind -eq 'Dir') { $null } else { $e.Length }
                LastWriteTime = $e.LastWriteTime
            })
            if ($kind -eq 'EncryptedFile') {
                $Stats.Encrypted++
                [void]$keep.Add($e.Name)      # keep any existing backup copy
                Write-LogFile "Skipped encrypted: $origPath"
                continue
            }
            # Encrypted folder: contents are still processed; only its
            # encrypted files are skipped.
        }

        [void]$keep.Add($e.Name)
        $target = [IO.Path]::Combine($Dst, $e.Name)

        if ($kind -eq 'Dir') {
            if ([IO.File]::Exists($target)) { Remove-DestItem -Path $target -Stats $Stats }
            Sync-Tree -Src $e.FullName -Dst $target -Stats $Stats
        }
        else {
            if ([IO.Directory]::Exists($target)) { Remove-DestItem -Path $target -Stats $Stats }
            Sync-OneFile -File $e -Target $target -Stats $Stats
        }
    }

    # Mirror: remove what no longer exists at the source
    try {
        foreach ($d in @([IO.Directory]::GetFileSystemEntries($Dst))) {
            if (-not $keep.Contains([IO.Path]::GetFileName($d))) {
                Remove-DestItem -Path $d -Stats $Stats
            }
        }
    }
    catch {
        Write-Log "Could not list destination $Dst for cleanup: $($_.Exception.GetBaseException().Message)" "WARN"
    }

    # Folder timestamps last (writing children changes them)
    try {
        $BackupIO::SetDirTimes($Dst, $srcInfo.CreationTimeUtc.ToFileTimeUtc(), $srcInfo.LastWriteTimeUtc.ToFileTimeUtc())
    }
    catch { Write-LogFile "Could not set folder times on $Dst" }
}

# ============================================================
# PRE-FLIGHT CHECKS
# ============================================================

$isAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "ERROR: This script must be run as Administrator." -ForegroundColor Red
    exit 1
}

if ($DuplicateMode -notin 'HardLink', 'Skip') {
    Write-Host "ERROR: DuplicateMode must be 'HardLink' or 'Skip'." -ForegroundColor Red
    exit 1
}
if ($ChangedFiles -notin 'Version', 'Ignore') {
    Write-Host "ERROR: ChangedFiles must be 'Version' or 'Ignore'." -ForegroundColor Red
    exit 1
}

if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Path $LogDir -Force | Out-Null
}

Write-Log "=== Backup started ==="

# CRC-64/XZ self-test (standard check value) - never compare with a broken hash
if ($BackupIO::CrcText("123456789") -ne "995DC9BBDF1939FA" -or
    $BackupIO::CrcText("") -ne "0000000000000000") {
    Write-Log "CRC-64 self-test FAILED - aborting" "ERROR"
    exit 1
}
Write-Log "CRC-64 self-test OK"

foreach ($priv in 'SeBackupPrivilege', 'SeRestorePrivilege') {
    if ($BackupIO::EnablePrivilege($priv)) { Write-Log "$priv enabled" }
    else { Write-Log "Could not enable $priv - files with restrictive ACLs may fail" "WARN" }
}

# --- HARD LOCK: destination must be exactly F:\versioningbackup ---

$expectedRoot = "F:\versioningbackup"

if ($DestinationRoot -ne $expectedRoot) {
    Write-Host "ERROR: DestinationRoot is locked to $expectedRoot" -ForegroundColor Red
    exit 1
}

$DestinationRoot = $DestinationRoot.TrimEnd('\')

if ($DestinationRoot -ne $expectedRoot) {
    Write-Host "ERROR: DestinationRoot must resolve to exactly $expectedRoot" -ForegroundColor Red
    exit 1
}

if (-not (Test-Path "F:\")) {
    Write-Host "ERROR: Drive F: is not available." -ForegroundColor Red
    exit 1
}

if ($DuplicateMode -eq 'HardLink') {
    $fsType = (Get-Volume -DriveLetter F -ErrorAction SilentlyContinue).FileSystemType
    if ($fsType -and $fsType -ne 'NTFS') {
        Write-Host "ERROR: F: is $fsType; hard links need NTFS. Use `$DuplicateMode = 'Skip'." -ForegroundColor Red
        exit 1
    }
}

if (-not (Test-Path $DestinationRoot)) {
    Write-Log "Creating destination root: $DestinationRoot"
    New-Item -ItemType Directory -Path $DestinationRoot -Force | Out-Null
}

$validSources = @()
foreach ($src in $SourceDirs) {
    if (Test-Path -LiteralPath $src) {
        $validSources += (Resolve-Path -LiteralPath $src).Path.TrimEnd('\')
    } else {
        Write-Log "Source not found, skipping: $src" "WARN"
    }
}

if ($validSources.Count -eq 0) {
    Write-Log "No valid source directories found. Aborting." "ERROR"
    exit 1
}

# ============================================================
# VSS HELPERS
# ============================================================

$VssErrorText = @{
    1  = "Access denied"
    2  = "Invalid argument"
    3  = "Specified volume not found"
    4  = "Specified volume not supported"
    5  = "Unsupported shadow copy context"
    6  = "Insufficient storage"
    7  = "Volume is in use"
    8  = "Maximum number of shadow copies reached"
    9  = "Another shadow copy operation is already in progress"
    10 = "Shadow copy provider vetoed the operation"
    11 = "Shadow copy provider not registered"
    12 = "Shadow copy provider failure"
    13 = "Unknown error"
}

function New-VssSnapshot {
    param(
        [Parameter(Mandatory)][string]$Volume,
        [int]$MaxAttempts = 6,
        [int]$DelaySeconds = 30
    )
    for ($attempt = 1; $attempt -le $MaxAttempts; $attempt++) {
        $result = (Get-WmiObject -List Win32_ShadowCopy).Create($Volume, "ClientAccessible")
        $code   = [int]$result.ReturnValue

        if ($code -eq 0) { return $result.ShadowID }

        $text = $VssErrorText[$code]
        if ($code -in 9, 12 -and $attempt -lt $MaxAttempts) {
            Write-Log ("VSS busy for {0}: code {1} ({2}). Retry {3}/{4} in {5}s..." -f `
                $Volume, $code, $text, $attempt, ($MaxAttempts - 1), $DelaySeconds) "WARN"
            Start-Sleep -Seconds $DelaySeconds
            continue
        }
        throw "Shadow copy create failed with code $code ($text)"
    }
}

function Wait-NamedLock {
    param([string]$Name, [int]$TimeoutSec = -1, [string]$WaitMessage)
    $m = New-Object System.Threading.Mutex($false, $Name)
    try {
        if ($m.WaitOne(0)) { return $m }
        if ($WaitMessage) { Write-Log $WaitMessage }
        $ms = if ($TimeoutSec -lt 0) { [System.Threading.Timeout]::Infinite } else { $TimeoutSec * 1000 }
        if ($m.WaitOne($ms)) { return $m }
        $m.Dispose()
        return $null
    }
    catch [System.Threading.AbandonedMutexException] {
        return $m
    }
}

function Unlock-NamedLock {
    param($Mutex)
    if ($Mutex) { try { $Mutex.ReleaseMutex() } catch {} ; $Mutex.Dispose() }
}

function Get-PathHash {
    param([string]$Path)
    $sha   = [System.Security.Cryptography.SHA256]::Create()
    $bytes = $sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Path.ToLowerInvariant()))
    return ([BitConverter]::ToString($bytes) -replace '-', '').Substring(0, 16)
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
# SNAPSHOT -> LIST -> CRC-64 -> PLAN -> SYNC
# (cleanup always runs, even on error/CTRL+C)
# ============================================================

$volumes = $validSources | ForEach-Object {
    (Split-Path -Qualifier $_).TrimEnd(':') + ":\"
} | Sort-Object -Unique

$snapshotMap      = @{}
$createdShadowIds = @()
$createdLinks     = @()
$liveVolumes      = @()
$syncResults      = @()
$sourceJobs       = @()
$destLocks        = @()
$fatal            = $false
$instanceSlot     = $null
$hashCacheLoaded  = $false

# No accidental freeze by clicking in the console window
$oldConsoleMode = $BackupIO::DisableQuickEdit()
if ($oldConsoleMode -ge 0) { Write-Log "Console QuickEdit disabled during the backup (clicking won't pause it)" }

try {
    # One named mutex per slot. Unlike a semaphore, a mutex is freed by Windows
    # automatically when a run is killed (window closed, crash, CTRL+C), so a
    # broken run can never block future backups.
    for ($slot = 1; $slot -le $MaxInstances -and -not $instanceSlot; $slot++) {
        $m = New-Object System.Threading.Mutex($false, "Global\VersioningBackup_Slot$slot")
        $got = $false
        try   { $got = $m.WaitOne(0) }
        catch [System.Threading.AbandonedMutexException] { $got = $true }   # previous run died
        if ($got) { $instanceSlot = $m } else { $m.Dispose() }
    }
    if (-not $instanceSlot) {
        throw "Already $MaxInstances backup instances running. Try again later."
    }
    Write-Log "Instance slot acquired (max $MaxInstances concurrent, PID $PID)"

    Get-ChildItem "$env:SystemDrive\" -Directory -Force -Filter "VSS_*" -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '^VSS_[A-Z]_(\d+)$' -and
                       -not (Get-Process -Id ([int]$Matches[1]) -ErrorAction SilentlyContinue) } |
        ForEach-Object {
            try { Remove-DirLink -Path $_.FullName; Write-Log "Removed stale link $($_.FullName)" }
            catch { Write-Log "Could not remove stale link $($_.FullName): $_" "WARN" }
        }

    # ---------------- 1. SNAPSHOT ----------------

    Write-Log "Creating VSS snapshot..."

    foreach ($vol in $volumes) {
        try {
            $createLock = Wait-NamedLock -Name "Global\VersioningBackup_VssCreate" -TimeoutSec 1800 `
                -WaitMessage "Other backup instance is creating a snapshot, waiting..."
            if (-not $createLock) { throw "Timed out waiting for other instance's snapshot creation" }
            try {
                $shadowId = New-VssSnapshot -Volume $vol -MaxAttempts $VssRetryAttempts -DelaySeconds $VssRetryDelaySec
            }
            finally { Unlock-NamedLock $createLock }
        }
        catch {
            if (-not $FallbackWithoutVss) { throw }
            Write-Log "VSS unavailable for $vol ($_)" "WARN"
            Write-Log "Falling back to LIVE copy of $vol - open/locked files may be skipped" "WARN"
            $snapshotMap[$vol] = $vol.TrimEnd('\')
            $liveVolumes += $vol
            continue
        }
        $createdShadowIds += $shadowId

        $sc = Get-WmiObject Win32_ShadowCopy -Filter "ID='$shadowId'"
        Write-Log "Snapshot created for $vol -> $($sc.DeviceObject)"

        $letter = $vol.Substring(0, 1)
        $link   = "$env:SystemDrive\VSS_${letter}_$PID"
        Remove-DirLink -Path $link
        cmd.exe /c mklink /d "$link" "$($sc.DeviceObject)\" | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "mklink failed for $link" }

        $createdLinks += $link
        $snapshotMap[$vol] = $link
        Write-Log "Snapshot mounted at $link"
    }

    foreach ($src in $validSources) {
        $vol      = (Split-Path -Qualifier $src).TrimEnd(':') + ":\"
        $relative = $src.Substring($vol.Length - 1)
        $destPath = Get-MirrorDestination -SourcePath $src -DestinationRoot $DestinationRoot
        $sourceJobs += [PSCustomObject]@{
            Source    = $src
            ShadowSrc = $snapshotMap[$vol] + $relative
            Dest      = $destPath
            LockName  = "Global\VersioningBackup_Dest_" + (Get-PathHash $destPath)
        }
    }

    # Lock all destination trees from plan until sync is done
    # (fixed order, so two instances can never deadlock each other)
    foreach ($name in @($sourceJobs | ForEach-Object LockName | Sort-Object -Unique)) {
        $destLocks += Wait-NamedLock -Name $name -WaitMessage "Other instance is syncing the same destination, waiting..."
    }

    # ---------------- 2. LIST BACKUP + SOURCES (no reading yet) ----------------

    $HashCache = Read-HashCacheFile
    $hashCacheLoaded = $true
    Write-Log ("Hash cache: {0} entries ({1})" -f $HashCache.Count, $HashCacheFile)
    if (-not $TrustHashCache) { Write-Log "TrustHashCache = false: every file is re-hashed" "WARN" }

    $Plan = @{
        Root = ''; ShadowRoot = ''
        Files = 0; TotalBytes = [int64]0
        Same  = 0; SameBytes  = [int64]0
        Dup   = 0; DupBytes   = [int64]0
        Copy  = 0; CopyBytes  = [int64]0
        Changed = 0; HashErrors = 0; Unreadable = 0; NoHashNeeded = 0
        NewHashes  = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::Ordinal)
        DeleteList = New-Object 'System.Collections.Generic.List[string]'
    }

    $JobFileCount = @{}
    $sw = [Diagnostics.Stopwatch]::StartNew()
    Build-DestList -Dir $DestinationRoot
    Write-Progress -Activity "Listing backup" -Completed

    foreach ($job in $sourceJobs) {
        Write-Log "Listing : $($job.Source)"
        Write-Log "   via  : $($job.ShadowSrc)"
        Write-Log "   to   : $($job.Dest)"
        $Plan.Root       = $job.Source
        $Plan.ShadowRoot = $job.ShadowSrc
        $before = $SL.Read.Count
        Plan-Tree -Src $job.ShadowSrc -Dst $job.Dest -Plan $Plan
        $JobFileCount[$job.Source] = $SL.Read.Count - $before
    }
    Write-Progress -Activity "Listing sources" -Completed
    Write-Log ("Listed in {0:N1}s: {1} source files, {2} files in backup" -f `
        $sw.Elapsed.TotalSeconds, $SL.Read.Count, $DL.Path.Count)

    # ---------------- 3. CRC-64 ONLY WHERE NEEDED ----------------

    # A file can only have a duplicate if another file has the same size.
    $srcSizeCount = New-Object 'System.Collections.Generic.Dictionary[long,int]'
    foreach ($l in $SL.Len) {
        $c = 0; [void]$srcSizeCount.TryGetValue($l, [ref]$c); $srcSizeCount[$l] = $c + 1
    }
    $destSizes  = New-Object 'System.Collections.Generic.HashSet[long]'
    $destByPath = New-Object 'System.Collections.Generic.Dictionary[string,int]' ([StringComparer]::OrdinalIgnoreCase)
    for ($i = 0; $i -lt $DL.Path.Count; $i++) {
        [void]$destSizes.Add($DL.Len[$i])
        $destByPath[$DL.Path[$i]] = $i
    }

    $bDest = New-HashBatch -Label "backup (F:)" -Threads $HashThreadsDest
    $bSrc  = New-HashBatch -Label "sources"     -Threads $HashThreadsSource

    for ($i = 0; $i -lt $DL.Path.Count; $i++) {
        $key = "$($DL.Len[$i])|$($DL.Tick[$i])|$($DL.Path[$i])"
        $DL.Key.Add($key)
        [void]$SeenPaths.Add($DL.Path[$i]); [void]$SeenKeys.Add($key)
        if (Resolve-CachedCrc $key) { continue }
        if ($srcSizeCount.ContainsKey($DL.Len[$i])) {
            $bDest.Read.Add($DL.Path[$i]); $bDest.Keys.Add($key); $bDest.Lens.Add($DL.Len[$i])
        }
    }
    for ($i = 0; $i -lt $SL.Read.Count; $i++) {
        $l   = $SL.Len[$i]
        $key = "$l|$($SL.Tick[$i])|$($SL.Orig[$i])"
        $SL.Key.Add($key)
        [void]$SeenPaths.Add($SL.Orig[$i]); [void]$SeenKeys.Add($key)
        if (Resolve-CachedCrc $key) { continue }
        if ($srcSizeCount[$l] -gt 1 -or $destSizes.Contains($l)) {
            $bSrc.Read.Add($SL.Read[$i]); $bSrc.Keys.Add($key); $bSrc.Lens.Add($l)
        }
        else { $Plan.NoHashNeeded++ }
    }
    Write-Log ("CRC-64 needed: {0} source + {1} backup files; {2} from cache; {3} source files skipped (unique size)" -f `
        $bSrc.Read.Count, $bDest.Read.Count, $HashStats.CacheHits, $Plan.NoHashNeeded)

    Write-Log "Copying to F: starts as soon as the CRC-64 plan is complete."
    Invoke-HashBatches -Batches @($bDest, $bSrc)

    if ($bSrc.Read.Count -gt 0 -and $bSrc.Failed -ge $bSrc.Read.Count) {
        throw "Not a single source file could be hashed - check the warnings above. Nothing was written."
    }

    # ---------------- 4. PLAN (in memory, no disk access) ----------------

    $sw.Restart()

    for ($i = 0; $i -lt $DL.Path.Count; $i++) {
        $h = $null
        if ($HashCacheUsed.TryGetValue($DL.Key[$i], [ref]$h) -and
            -not $DestIndex.ContainsKey($h)) {
            $DestIndex[$h] = $DL.Path[$i]
        }
    }

    for ($i = 0; $i -lt $SL.Read.Count; $i++) {
        $l = $SL.Len[$i]
        $Plan.Files++
        $Plan.TotalBytes += $l
        $crc = $null
        [void]$HashCacheUsed.TryGetValue($SL.Key[$i], [ref]$crc)

        $di = 0
        if ($destByPath.TryGetValue($SL.Target[$i], [ref]$di)) {
            if ($DL.Len[$di] -eq $l) {
                $dcrc = $null
                [void]$HashCacheUsed.TryGetValue($DL.Key[$di], [ref]$dcrc)
                if (-not $crc -or -not $dcrc) { $Plan.HashErrors++; continue }
                if ($crc -eq $dcrc) { $Plan.Same++; $Plan.SameBytes += $l; continue }
            }
            $Plan.Changed++
            if ($ChangedFiles -ne 'Version') { continue }
        }

        if ($crc -and ($DestIndex.ContainsKey($crc) -or -not $Plan.NewHashes.Add($crc))) {
            $Plan.Dup++
            $Plan.DupBytes += $l
            continue
        }
        $Plan.Copy++
        $Plan.CopyBytes += $l
    }
    $Plan.HashErrors += $bSrc.Failed
    Write-Log ("Plan calculated in {0:N1}s" -f $sw.Elapsed.TotalSeconds)

    $destFree  = (Get-PSDrive -Name F).Free
    $destTotal = $destFree + (Get-PSDrive -Name F).Used
    $freeAfter = $destFree - $Plan.CopyBytes
    $srcDisk   = Get-PSDrive -Name ((Split-Path -Qualifier $validSources[0]).TrimEnd(':'))

    $dupAction     = if ($DuplicateMode -eq 'HardLink') { 'hard link, 0 bytes' } else { 'not stored' }
    $changedAction = if ($ChangedFiles -eq 'Version') { 'old copy -> _versions' } else { 'IGNORED, not backed up' }

    Write-Host ""
    Write-Log "================ CRC-64 BACKUP PLAN ================"
    Write-Log ("Source files checked : {0,8}  ({1})" -f $Plan.Files, (Format-Size $Plan.TotalBytes))
    Write-Log ("Unchanged (same CRC-64) : {0,8}  ({1})  -> skipped" -f $Plan.Same, (Format-Size $Plan.SameBytes))
    Write-Log ("Duplicate content    : {0,8}  ({1})  -> {2}" -f $Plan.Dup, (Format-Size $Plan.DupBytes), $dupAction)
    Write-Log ("Changed content      : {0,8}  -> {1}" -f $Plan.Changed, $changedAction)
    Write-Log ("UNIQUE, to copy      : {0,8}  ({1})" -f $Plan.Copy, (Format-Size $Plan.CopyBytes))
    Write-Log ("To delete from mirror: {0,8}" -f $Plan.DeleteList.Count)
    if ($Plan.HashErrors -gt 0 -or $Plan.Unreadable -gt 0) {
        Write-Log ("Could not hash       : {0,8} files, {1} folders unreadable" -f $Plan.HashErrors, $Plan.Unreadable) "WARN"
    }
    Write-Log ("CRC-64 read from disk   : {0} files ({1}) in {2:N1}s, {3} from cache, {4} not needed (unique size)" -f `
        $HashStats.Hashed, (Format-Size $HashStats.HashedBytes), $HashStats.HashSeconds, $HashStats.CacheHits, $Plan.NoHashNeeded)
    Write-Log ("Dest free space      : {0} of {1}, after backup: {2}" -f `
        (Format-Size $destFree), (Format-Size $destTotal), (Format-Size $freeAfter))
    Write-Log ("Source drive free    : {0}" -f (Format-Size $srcDisk.Free))
    Write-Log "================================================="

    if ($Plan.DeleteList.Count -gt 0) {
        $Plan.DeleteList | Select-Object -First 10 | ForEach-Object { Write-Log "  will delete: $_" "WARN" }
        if ($Plan.DeleteList.Count -gt 10) { Write-Log "  ... and $($Plan.DeleteList.Count - 10) more (see log)" "WARN" }
        $Plan.DeleteList | ForEach-Object { Write-LogFile "Planned delete: $_" }
    }
    Write-Host ""

    # ---------------- 5. SPACE CHECK ----------------

    $versionWork = if ($ChangedFiles -eq 'Version') { $Plan.Changed } else { 0 }
    $work = $Plan.Copy + $Plan.Dup + $versionWork + $Plan.DeleteList.Count

    if ($work -eq 0) {
        Write-Log "Nothing to write: the backup already matches the source."
    }
    else {
        # Never waits for input. Only stops when the unique files can't fit at all.
        if ($freeAfter -lt 0) {
            throw ("Not enough space on F: - {0} to copy, only {1} free. Nothing was written." -f `
                (Format-Size $Plan.CopyBytes), (Format-Size $destFree))
        }
        if ($freeAfter -le $MinFreeAfterBackup) {
            Write-Log ("Only {0} free after backup (minimum {1}) - continuing anyway." -f `
                (Format-Size $freeAfter), (Format-Size $MinFreeAfterBackup)) "WARN"
        }
        Write-Log "Executing plan."
    }

    # ---------------- 6. SYNC ----------------

    Write-Log "Starting copy to F:"

    foreach ($job in $sourceJobs) {
        Write-Log "Syncing : $($job.Source)"
        Write-Log "   to   : $($job.Dest)"

        $stats = @{
            Timer          = [Diagnostics.Stopwatch]::StartNew()
            ProgressTimer  = [Diagnostics.Stopwatch]::StartNew()
            Expected       = $JobFileCount[$job.Source]
            Root           = $job.Source
            ShadowRoot     = $job.ShadowSrc
            Encrypted      = 0
            EncryptedList  = New-Object 'System.Collections.Generic.List[object]'
            Files          = 0
            Same           = 0
            Copied         = 0
            Linked         = 0
            LinkLimit      = 0
            Deduped        = 0
            Versioned      = 0
            ChangedIgnored = 0
            Skipped        = 0
            Failed         = 0
            Deleted        = 0
            Links          = 0
            Bytes          = [int64]0
            SavedBytes     = [int64]0
            FailedList     = New-Object 'System.Collections.Generic.List[string]'
        }

        try {
            Sync-Tree -Src $job.ShadowSrc -Dst $job.Dest -Stats $stats
        }
        finally {
            Write-Progress -Activity "Copying $($job.Source) -> F:" -Completed
        }

        Write-Log ("Done {0}: {1} files, {2} unchanged, {3} copied ({4}), {5} linked + {6} dedup-skipped (saved {7}), {8} versioned, {9} changed-ignored, {10} deleted, {11} links skipped, {12} encrypted skipped, {13} failed, {14} extra copies (1023-link limit)" -f `
            $job.Source, $stats.Files, $stats.Same, $stats.Copied, (Format-Size $stats.Bytes),
            $stats.Linked, $stats.Deduped, (Format-Size $stats.SavedBytes), $stats.Versioned,
            $stats.ChangedIgnored, $stats.Deleted, $stats.Links, $stats.Encrypted, $stats.Failed, $stats.LinkLimit)

        $syncResults += [PSCustomObject]@{
            Source        = $job.Source
            Copied        = $stats.Copied
            Bytes         = $stats.Bytes
            Linked        = $stats.Linked + $stats.Deduped
            SavedBytes    = $stats.SavedBytes
            Versioned     = $stats.Versioned
            Failed        = $stats.Failed
            FailedList    = $stats.FailedList
            Encrypted     = $stats.Encrypted
            EncryptedList = $stats.EncryptedList
        }
    }

    if (-not $FlushEachFile) {
        Write-Log "Flushing F: to disk..."
        $flushed  = $false
        $flushErr = @()
        # 1. Direct volume flush (can be blocked by Defender "Controlled folder
        #    access" / anti-virus, which treat raw volume access as suspicious)
        try   { $BackupIO::FlushVolume("F"); $flushed = $true }
        catch { $flushErr += $_.Exception.GetBaseException().Message }
        # 2. Via the Windows storage service (not blocked by those)
        if (-not $flushed -and (Get-Command Write-VolumeCache -ErrorAction SilentlyContinue)) {
            try   { Write-VolumeCache -DriveLetter F -ErrorAction Stop; $flushed = $true }
            catch { $flushErr += $_.Exception.Message }
        }
        if ($flushed) { Write-Log "F: flushed" }
        else {
            Write-Log ("Could not force a flush of F: ({0}). Not a problem: Windows writes its cache to disk within seconds. Use 'Safely remove hardware' before unplugging F:." -f ($flushErr -join ' / '))
        }
    }
}
catch {
    Write-Log "Backup aborted: $_" "ERROR"
    $fatal = $true
}
finally {
    $BackupIO::RestoreConsoleMode($oldConsoleMode)
    foreach ($l in $destLocks) { Unlock-NamedLock $l }

    # Save hashes even after an abort, so the work isn't lost
    if ($hashCacheLoaded) {
        try   { $n = Export-HashCache; Write-Log "Hash cache saved: $n entries" }
        catch { Write-Log "Could not save hash cache: $_" "WARN" }
    }

    Unlock-NamedLock $instanceSlot

    Write-Log "Removing VSS snapshots..."

    foreach ($link in $createdLinks) {
        try   { Remove-DirLink -Path $link; Write-Log "Link removed: $link" }
        catch { Write-Log "Failed to remove link $link : $_" "WARN" }
    }
    foreach ($id in $createdShadowIds) {
        try {
            $sc = Get-WmiObject Win32_ShadowCopy -Filter "ID='$id'"
            if ($sc) { $sc.Delete() | Out-Null; Write-Log "Snapshot removed: $id" }
        }
        catch { Write-Log "Failed to remove snapshot $id : $_" "WARN" }
    }
}

# ============================================================
# SUMMARY
# ============================================================

if ($fatal) {
    Write-Log "=== Backup ended (FAILED) ===" "ERROR"
    Close-Log
    Write-Host "Log file: $LogFile" -ForegroundColor Cyan
    exit 1
}

$failedTotal = 0; $copiedTotal = 0; $bytesTotal = [int64]0
$linkedTotal = 0; $savedTotal  = [int64]0; $versionTotal = 0
foreach ($r in $syncResults) {
    $failedTotal += $r.Failed;  $copiedTotal += $r.Copied; $bytesTotal   += $r.Bytes
    $linkedTotal += $r.Linked;  $savedTotal  += $r.SavedBytes; $versionTotal += $r.Versioned
}
Write-Log ("Total: {0} unique files copied ({1}), {2} duplicates not copied (saved {3}), {4} old versions kept in {5}" -f `
    $copiedTotal, (Format-Size $bytesTotal), $linkedTotal, (Format-Size $savedTotal), $versionTotal, $VersionsRoot)

$encList = @($syncResults | ForEach-Object { $_.EncryptedList })
if ($encList.Count -gt 0) {
    $encFiles = @($encList | Where-Object Type -eq 'File').Count
    $encDirs  = $encList.Count - $encFiles
    try {
        $encList | Export-Csv -LiteralPath $EncryptedReport -NoTypeInformation -Delimiter ';' -Encoding UTF8
        Write-Log ("Skipped {0} encrypted file(s) ({1} encrypted folder(s) found). List: {2}" -f `
            $encFiles, $encDirs, $EncryptedReport) "WARN"
    }
    catch { Write-Log "Could not write encrypted-files CSV: $_" "WARN" }
}

if ($failedTotal -gt 0) {
    Write-Log "Backup finished WITH ERRORS: $failedTotal item(s) failed:" "ERROR"
    $syncResults | ForEach-Object { $_.FailedList } | Select-Object -First 50 |
        ForEach-Object { Write-Log "  $_" "ERROR" }
    if ($failedTotal -gt 50) { Write-Log "  ... and $($failedTotal - 50) more (see log)" "ERROR" }
} elseif ($liveVolumes.Count -gt 0) {
    Write-Log "Backup finished WITHOUT VSS for: $($liveVolumes -join ', ')" "WARN"
} else {
    Write-Log "Backup finished successfully."
}

Write-Log "=== Backup ended ==="
Write-Host ""
Write-Host "Log file: $LogFile" -ForegroundColor Cyan
Close-Log