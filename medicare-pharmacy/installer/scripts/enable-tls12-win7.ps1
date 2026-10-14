<#
.SYNOPSIS
    Enables TLS 1.2 for MediCare Pharmacy counter PCs running Windows 7 SP1.

.DESCRIPTION
    The MediCare client on Windows 7 is the .NET Framework 4.8 build. It talks to the host over HTTPS
    (TLS 1.2 or newer) through .NET's SslStream, which uses Windows SChannel. This script:

      1. SChannel: turns on the TLS 1.2 client protocol (Windows 7 ships with it disabled by default).
      2. .NET Framework 4.x: sets SchUseStrongCrypto=1 and SystemDefaultTlsVersions=1 in both the
         64-bit and the 32-bit (WOW6432Node) registry views, so every .NET 4.x program offers TLS 1.2.
      3. Optional (-WinHttp): sets WinHTTP DefaultSecureProtocols to TLS 1.0/1.1/1.2. This only has an effect
         after update KB3140245 is installed. MediCare does not use WinHTTP, so this is only for other software.

    The client installer applies the same settings (with consent). This script is for IT staff who prepare
    PCs by hand or need to repair a PC. Windows must be restarted afterwards.

    Compatible with Windows PowerShell 2.0 (the version that ships with Windows 7). Run as administrator.
    Equivalent registry file: enable-tls12-win7.reg

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File enable-tls12-win7.ps1
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File enable-tls12-win7.ps1 -WinHttp
#>
param(
    [switch]$WinHttp
)

$ErrorActionPreference = 'Stop'

# A 32-bit PowerShell on 64-bit Windows only sees the 32-bit SOFTWARE hive. Re-run in the 64-bit host.
if ($env:PROCESSOR_ARCHITEW6432 -eq 'AMD64') {
    $native = Join-Path $env:WINDIR 'sysnative\WindowsPowerShell\v1.0\powershell.exe'
    $relaunchArgs = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $MyInvocation.MyCommand.Path)
    if ($WinHttp) { $relaunchArgs += '-WinHttp' }
    & $native $relaunchArgs
    exit $LASTEXITCODE
}

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Run this script as administrator (right-click PowerShell > Run as administrator).' -ForegroundColor Red
    exit 1
}

$is64Bit = [IntPtr]::Size -eq 8

function Ensure-Key([string]$Path) {
    if (Test-Path -LiteralPath $Path) { return }
    $parent = Split-Path -Path $Path -Parent
    Ensure-Key $parent
    New-Item -Path $parent -Name (Split-Path -Path $Path -Leaf) | Out-Null
}

function Set-Dword([string]$Path, [string]$Name, [int]$Value) {
    Ensure-Key $Path
    New-ItemProperty -LiteralPath $Path -Name $Name -Value $Value -PropertyType DWord -Force | Out-Null
    Write-Host ('  {0}\{1} = {2}' -f $Path, $Name, $Value)
}

try {
    Write-Host 'SChannel: enabling the TLS 1.2 client protocol'
    $tls12 = 'HKLM:\SYSTEM\CurrentControlSet\Control\SecurityProviders\SCHANNEL\Protocols\TLS 1.2\Client'
    Set-Dword $tls12 'Enabled' 1
    Set-Dword $tls12 'DisabledByDefault' 0

    Write-Host '.NET Framework 4.x: strong cryptography and OS-default TLS versions'
    $netKeys = @('HKLM:\SOFTWARE\Microsoft\.NETFramework\v4.0.30319')
    if ($is64Bit) { $netKeys += 'HKLM:\SOFTWARE\Wow6432Node\Microsoft\.NETFramework\v4.0.30319' }
    foreach ($key in $netKeys) {
        Set-Dword $key 'SchUseStrongCrypto' 1
        Set-Dword $key 'SystemDefaultTlsVersions' 1
    }

    if ($WinHttp) {
        Write-Host 'WinHTTP: default secure protocols TLS 1.0 + 1.1 + 1.2 (needs KB3140245)'
        # 0x80 TLS 1.0 | 0x200 TLS 1.1 | 0x800 TLS 1.2; TLS 1.0 is kept so other older software keeps working.
        $winHttpKeys = @('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Internet Settings\WinHttp')
        if ($is64Bit) { $winHttpKeys += 'HKLM:\SOFTWARE\Wow6432Node\Microsoft\Windows\CurrentVersion\Internet Settings\WinHttp' }
        foreach ($key in $winHttpKeys) { Set-Dword $key 'DefaultSecureProtocols' 0xA80 }
        if (-not (Get-HotFix -Id 'KB3140245' -ErrorAction SilentlyContinue)) {
            Write-Host 'Note: KB3140245 is not installed, so the WinHTTP setting has no effect yet. MediCare itself does not need it.' -ForegroundColor Yellow
        }
    }

    Write-Host ''
    Write-Host 'Done. Restart Windows so that SChannel picks up the change.' -ForegroundColor Green
    exit 0
} catch {
    Write-Host ('Failed: ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
