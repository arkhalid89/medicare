<#
  MediCare Practice — builds the offline Windows installer (setup.exe).

    powershell -ExecutionPolicy Bypass -File installer\build.ps1
    powershell -ExecutionPolicy Bypass -File installer\build.ps1 -DownloadOnly

  Steps
    1. Download (once, cached in installer\cache) and verify SHA-256:
         PHP 8.2 NTS x64, MariaDB 10.11 x64, Microsoft VC++ 2015-2022 runtime
    2. Stage runtime\php, runtime\mariadb (trimmed) and app\ (the PHP project)
    3. Compile the launcher MediCare.exe with the C# compiler built into Windows
    4. Compile installer\medicare.iss with Inno Setup 6 -> installer\output\
#>
param([switch]$DownloadOnly)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
$dir = $PSScriptRoot
$cache = Join-Path $dir 'cache'
$stage = Join-Path $dir 'build'
New-Item -ItemType Directory -Force $cache | Out-Null

$downloads = @(
    @{ Name = 'php-8.2.34-nts-Win32-vs16-x64.zip'; Url = 'https://windows.php.net/downloads/releases/php-8.2.34-nts-Win32-vs16-x64.zip';
       Alt = 'https://windows.php.net/downloads/releases/archives/php-8.2.34-nts-Win32-vs16-x64.zip';
       Sha = '03249b5c9414c6dbe30276f4a7598bd9d2a7417ee81f709b06b99e9c4a2aff4f' },
    @{ Name = 'mariadb-10.11.19-winx64.zip'; Url = 'https://archive.mariadb.org/mariadb-10.11.19/winx64-packages/mariadb-10.11.19-winx64.zip';
       Alt = 'https://downloads.mariadb.org/rest-api/mariadb/10.11.19/mariadb-10.11.19-winx64.zip';
       Sha = '398ea30e5036010bbebe01d2b1804280424dcc2626e36d8e95155c04d25a0490' },
    @{ Name = 'vc_redist.x64.exe'; Url = 'https://aka.ms/vs/17/release/vc_redist.x64.exe'; Alt = $null; Sha = $null }
)

function Step($text) { Write-Host "`n==> $text" -ForegroundColor Cyan }

# ---------------------------------------------------------------- 1. downloads
Step 'Downloading runtimes (cached)'
foreach ($d in $downloads) {
    $file = Join-Path $cache $d.Name
    if (-not (Test-Path $file)) {
        Write-Host "  downloading $($d.Name) ..."
        try { Invoke-WebRequest -Uri $d.Url -OutFile $file -UseBasicParsing }
        catch {
            if (-not $d.Alt) { throw }
            Write-Host "  primary mirror failed, trying alternative ..."
            Invoke-WebRequest -Uri $d.Alt -OutFile $file -UseBasicParsing
        }
    }
    if ($d.Sha) {
        $hash = (Get-FileHash $file -Algorithm SHA256).Hash.ToLower()
        if ($hash -ne $d.Sha) { Remove-Item $file; throw "Checksum mismatch for $($d.Name) - file removed, run again." }
        Write-Host "  $($d.Name) verified (sha256)"
    } else {
        $sig = Get-AuthenticodeSignature $file
        if ($sig.Status -ne 'Valid' -or $sig.SignerCertificate.Subject -notmatch 'Microsoft') { Remove-Item $file; throw "$($d.Name) is not signed by Microsoft - removed." }
        Write-Host "  $($d.Name) verified (Microsoft signature)"
    }
}
if ($DownloadOnly) { Write-Host "`nDownloads ready in $cache"; exit 0 }

# ------------------------------------------------------------------- 2. stage
Step 'Staging files'
if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
New-Item -ItemType Directory -Force "$stage\runtime", "$stage\app", "$stage\redist" | Out-Null

Expand-Archive (Join-Path $cache $downloads[0].Name) "$stage\runtime\php"
Copy-Item (Join-Path $dir 'php.ini') "$stage\runtime\php\php.ini"
# Keep only what the application needs from PHP.
Get-ChildItem "$stage\runtime\php\ext" -Filter *.dll | Where-Object { $_.Name -notin @('php_pdo_mysql.dll', 'php_mbstring.dll', 'php_opcache.dll', 'php_curl.dll', 'php_fileinfo.dll') } | Remove-Item
Remove-Item "$stage\runtime\php\dev", "$stage\runtime\php\extras" -Recurse -Force -ErrorAction SilentlyContinue

Expand-Archive (Join-Path $cache $downloads[1].Name) "$stage\runtime\mdb-tmp"
$mdb = Get-ChildItem "$stage\runtime\mdb-tmp" -Directory | Select-Object -First 1
Move-Item $mdb.FullName "$stage\runtime\mariadb"
Remove-Item "$stage\runtime\mdb-tmp" -Recurse -Force
# Trim MariaDB: tests, headers, debug symbols, static libraries, extra tools.
foreach ($p in 'mysql-test', 'sql-bench', 'include', 'lib', 'data', 'docs') {
    Remove-Item "$stage\runtime\mariadb\$p" -Recurse -Force -ErrorAction SilentlyContinue
}
Get-ChildItem "$stage\runtime\mariadb" -Recurse -Include *.pdb, *.lib | Remove-Item -Force
$keepBin = @('mysqld.exe', 'mariadbd.exe', 'mysql_install_db.exe', 'mariadb-install-db.exe', 'mysqladmin.exe', 'mariadb-admin.exe', 'mysql.exe', 'mariadb.exe', 'mysqldump.exe', 'mariadb-dump.exe')
Get-ChildItem "$stage\runtime\mariadb\bin" -Filter *.exe | Where-Object { $_.Name -notin $keepBin } | Remove-Item -Force

Copy-Item (Join-Path $cache 'vc_redist.x64.exe') "$stage\redist\"

# Application files (no dev/test/runtime data).
$exclude = @('installer', 'docker', 'docker-compose.yml', '.git', '.gitignore', '.claude')
Get-ChildItem $root -Force | Where-Object { $_.Name -notin $exclude } | ForEach-Object {
    Copy-Item $_.FullName "$stage\app\" -Recurse -Force
}
Get-ChildItem "$stage\app\storage" -Recurse -File | Where-Object { $_.Name -notin @('.htaccess', '.gitkeep') } | Remove-Item -Force
Get-ChildItem "$stage\app\public\uploads" -Recurse -File | Where-Object { $_.Name -ne '.htaccess' } | Remove-Item -Force
Get-ChildItem "$stage\app\public\uploads" -Directory | Remove-Item -Recurse -Force

# -------------------------------------------------------------- 3. launcher
Step 'Building icon and launcher'
& (Join-Path $dir 'make-icon.ps1') -Out (Join-Path $dir 'medicare.ico')
$csc = "$env:WINDIR\Microsoft.NET\Framework64\v4.0.30319\csc.exe"
& $csc /nologo /target:winexe /optimize+ /platform:x64 "/out:$stage\MediCare.exe" "/win32icon:$dir\medicare.ico" `
    /reference:System.Windows.Forms.dll /reference:System.Drawing.dll (Join-Path $dir 'launcher\MediCareLauncher.cs')
if ($LASTEXITCODE -ne 0) { throw 'Launcher compilation failed.' }

# ------------------------------------------------------------- 4. installer
Step 'Compiling installer'
$iscc = @("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe", "$env:ProgramFiles\Inno Setup 6\ISCC.exe", "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $iscc) { throw 'Inno Setup 6 not found. Install it:  winget install JRSoftware.InnoSetup' }
& $iscc /Q (Join-Path $dir 'medicare.iss')
if ($LASTEXITCODE -ne 0) { throw 'Inno Setup compilation failed.' }

$out = Get-ChildItem (Join-Path $dir 'output') -Filter *.exe | Sort-Object LastWriteTime -Descending | Select-Object -First 1
Write-Host ("`nInstaller ready: {0}  ({1:N1} MB)" -f $out.FullName, ($out.Length / 1MB)) -ForegroundColor Green
