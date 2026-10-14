<#
.SYNOPSIS
    Builds, tests and packages MediCare Pharmacy: published binaries in artifacts\ and the three installers.

.DESCRIPTION
    1. dotnet test on the solution (skip with -SkipTests).
    2. dotnet publish:
         artifacts\host\          MediCare.Host         win-x64, self-contained, one folder
         artifacts\hostmonitor\   MediCare.HostMonitor  win-x64, self-contained, one folder
         artifacts\client-win10\  MediCare.Client       net10.0-windows, win-x64, self-contained
         artifacts\client-win7\   MediCare.Client       net48, framework-dependent (.NET Framework 4.8)
    3. Inno Setup (ISCC.exe) compiles installer\MediCareHost.iss and installer\MediCareClient.iss (win10 + win7)
       into artifacts\installers\. Skipped with a warning when Inno Setup 6 is not installed.

    Run on Windows with the .NET 10 SDK (the net48 build needs Windows). Windows PowerShell 5.1 or PowerShell 7.

.EXAMPLE
    .\build.ps1
.EXAMPLE
    .\build.ps1 -SkipTests -Iscc 'D:\Tools\Inno Setup 6\ISCC.exe'
#>
[CmdletBinding()]
param(
    [string]$Configuration = 'Release',
    [switch]$SkipTests,
    [switch]$SkipInstallers,
    # Path to ISCC.exe; found automatically when Inno Setup 6 is installed in the default location or on PATH.
    [string]$Iscc
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = $PSScriptRoot
$artifacts = Join-Path $root 'artifacts'
$installerDir = Join-Path $root 'installer'
$solution = Join-Path $root 'MediCarePharmacy.sln'

function Invoke-Tool {
    param([string]$Title, [string]$FilePath, [string[]]$Arguments)
    Write-Host ''
    Write-Host "==> $Title" -ForegroundColor Cyan
    Write-Host "    $FilePath $($Arguments -join ' ')" -ForegroundColor DarkGray
    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Title failed (exit code $LASTEXITCODE)." }
}

function Reset-Folder([string]$Path) {
    if (Test-Path -LiteralPath $Path) { Remove-Item -LiteralPath $Path -Recurse -Force }
    New-Item -ItemType Directory -Path $Path | Out-Null
}

function Get-ProductVersion {
    $props = [xml](Get-Content -LiteralPath (Join-Path $root 'Directory.Build.props') -Raw)
    $node = $props.SelectSingleNode('//Version')
    if ($node -and $node.InnerText) { return $node.InnerText.Trim() }
    return '1.0.0'
}

function Find-Iscc {
    if ($Iscc) {
        if (-not (Test-Path -LiteralPath $Iscc)) { throw "ISCC.exe not found at $Iscc" }
        return $Iscc
    }
    $candidates = @()
    if (${env:ProgramFiles(x86)}) { $candidates += Join-Path ${env:ProgramFiles(x86)} 'Inno Setup 6\ISCC.exe' }
    if ($env:ProgramFiles) { $candidates += Join-Path $env:ProgramFiles 'Inno Setup 6\ISCC.exe' }
    if ($env:LOCALAPPDATA) { $candidates += Join-Path $env:LOCALAPPDATA 'Programs\Inno Setup 6\ISCC.exe' }
    foreach ($c in $candidates) { if (Test-Path -LiteralPath $c) { return $c } }
    $onPath = Get-Command 'ISCC.exe' -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }
    return $null
}

$version = Get-ProductVersion
Write-Host "MediCare Pharmacy $version ($Configuration)" -ForegroundColor Green

# ---------------------------------------------------------------- tests
if (-not $SkipTests) {
    Invoke-Tool 'Run tests' 'dotnet' @('test', $solution, '-c', $Configuration, '--nologo')
}

# ---------------------------------------------------------------- publish
$hostOut = Join-Path $artifacts 'host'
$monitorOut = Join-Path $artifacts 'hostmonitor'
$win10Out = Join-Path $artifacts 'client-win10'
$win7Out = Join-Path $artifacts 'client-win7'
foreach ($dir in @($hostOut, $monitorOut, $win10Out, $win7Out)) { Reset-Folder $dir }

$common = @('-c', $Configuration, '--nologo', "-p:Version=$version")
# ReadyToRun precompiles the self-contained apps so the host service and the Windows 10/11 client start faster.
$selfContained = @('-r', 'win-x64', '--self-contained', 'true', '-p:PublishSingleFile=false', '-p:PublishReadyToRun=true')

Invoke-Tool 'Publish host service' 'dotnet' (@('publish', (Join-Path $root 'src\MediCare.Host\MediCare.Host.csproj'), '-o', $hostOut) + $common + $selfContained)
Invoke-Tool 'Publish host monitor' 'dotnet' (@('publish', (Join-Path $root 'src\MediCare.HostMonitor\MediCare.HostMonitor.csproj'), '-o', $monitorOut) + $common + $selfContained)

$client = Join-Path $root 'src\MediCare.Client\MediCare.Client.csproj'
Invoke-Tool 'Publish client (Windows 10/11, .NET 10)' 'dotnet' (@('publish', $client, '-f', 'net10.0-windows', '-o', $win10Out) + $common + $selfContained)
Invoke-Tool 'Publish client (Windows 7, .NET Framework 4.8)' 'dotnet' (@('publish', $client, '-f', 'net48', '-o', $win7Out) + $common)

$expected = @(
    (Join-Path $hostOut 'MediCare.Host.exe'),
    (Join-Path $monitorOut 'MediCare.HostMonitor.exe'),
    (Join-Path $win10Out 'MediCarePharmacy.exe'),
    (Join-Path $win7Out 'MediCarePharmacy.exe')
)
foreach ($file in $expected) {
    if (-not (Test-Path -LiteralPath $file)) { throw "Publish output missing: $file" }
}

# ---------------------------------------------------------------- installers
if ($SkipInstallers) {
    Write-Host ''
    Write-Host 'Installers skipped (-SkipInstallers).' -ForegroundColor Yellow
    return
}

$isccPath = Find-Iscc
if (-not $isccPath) {
    Write-Warning 'Inno Setup 6 (ISCC.exe) was not found, so no installers were built. Install Inno Setup 6.5+ or pass -Iscc <path>.'
    return
}

$fonts = @(Get-ChildItem -LiteralPath (Join-Path $installerDir 'fonts') -Filter '*.ttf' -ErrorAction SilentlyContinue)
if ($fonts.Count -eq 0) {
    Write-Warning 'installer\fonts has no .ttf files: the installers will not install the MediCare fonts (see installer\fonts\README.md).'
}

$installersOut = Join-Path $artifacts 'installers'
Reset-Folder $installersOut
$isccCommon = @('/Qp', "/DAppVersion=$version", "/O$installersOut")
Invoke-Tool 'Installer: host' $isccPath ($isccCommon + @((Join-Path $installerDir 'MediCareHost.iss')))
Invoke-Tool 'Installer: client (Windows 10/11)' $isccPath ($isccCommon + @('/DTarget=win10', (Join-Path $installerDir 'MediCareClient.iss')))
Invoke-Tool 'Installer: client (Windows 7)' $isccPath ($isccCommon + @('/DTarget=win7', (Join-Path $installerDir 'MediCareClient.iss')))

Write-Host ''
Write-Host "Installers written to $installersOut" -ForegroundColor Green
Get-ChildItem -LiteralPath $installersOut -Filter '*.exe' | ForEach-Object { Write-Host "  $($_.Name)  ($([math]::Round($_.Length / 1MB, 1)) MB)" }
