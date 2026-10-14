<#
.SYNOPSIS
    Registers, upgrades, health-checks and removes the MediCare Pharmacy host Windows service.

.DESCRIPTION
    Called by MediCareHost.iss; an administrator can also run it by hand (elevated) to repair an installation.
    Windows PowerShell 5.1 (Windows 10/11). Every action is idempotent and logs to <DataDir>\logs\setup-service.log.

    Actions
      Install         Create (or reconfigure) the service: delayed automatic start, restart on failure,
                      runs as -Account; create the data folder and restrict its ACL.
      Stop            Stop the service (kills the process if it does not stop within -TimeoutSec).
      StartAndWait    Start the service and wait until GET /api/v1/health answers ok.
      PrepareUpgrade  Stop, copy the database to -SafetyDir, keep the current binaries in <ServiceDir>.previous,
                      then empty <ServiceDir> (appsettings.Production.json is kept).
      Rollback        Stop, put back <ServiceDir>.previous and the database from -SafetyDir, start, health-check.
      Remove          Stop and delete the service. Never touches the data folder.

    Exit codes: 0 ok; 1 failed; 2 service not running after start; 3 running but /health not answering.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File host-service.ps1 -Action StartAndWait
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Install', 'Stop', 'StartAndWait', 'PrepareUpgrade', 'Rollback', 'Remove')]
    [string]$Action,

    [string]$ServiceName = 'MediCarePharmacyHost',
    [string]$DisplayName = 'MediCare Pharmacy Host',
    [string]$ServiceDir = (Join-Path ${env:ProgramFiles} 'MediCare Pharmacy\Host\Service'),
    [string]$DataDir = (Join-Path $env:ProgramData 'MediCarePharmacy\Data'),
    # Virtual service account (least privilege). Use 'LocalSystem' only if the virtual account cannot be used.
    [string]$Account = 'NT SERVICE\MediCarePharmacyHost',
    [string]$SafetyDir,
    [int]$TimeoutSec = 120
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

$ExeName = 'MediCare.Host.exe'
$KeepFiles = @('appsettings.Production.json')   # local overrides survive upgrades and rollbacks
$DbFiles = @('medicare.db', 'medicare.db-wal', 'medicare.db-shm')

function Write-Log([string]$Message) {
    $line = '{0:yyyy-MM-dd HH:mm:ss} [{1}] {2}' -f (Get-Date), $Action, $Message
    Write-Host $line   # not Write-Output: functions below return exit codes through the output stream
    try {
        $logDir = Join-Path $DataDir 'logs'
        if (-not (Test-Path -LiteralPath $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
        Add-Content -LiteralPath (Join-Path $logDir 'setup-service.log') -Value $line -Encoding UTF8
    } catch {
        # Logging must never be the reason an install fails.
    }
}

# Runs a console tool with the argument string passed verbatim (Windows PowerShell mangles embedded quotes
# when calling native programs directly, which breaks sc.exe binPath= values).
function Invoke-Native([string]$FilePath, [string]$Arguments, [int[]]$OkExitCodes = @(0)) {
    $out = [IO.Path]::GetTempFileName()
    try {
        $p = Start-Process -FilePath $FilePath -ArgumentList $Arguments -Wait -NoNewWindow -PassThru -RedirectStandardOutput $out
        $text = ((Get-Content -LiteralPath $out -Raw) -as [string])
        if ($text) { $text = $text.Trim() }
        Write-Log ("{0} {1} -> {2} {3}" -f $FilePath, $Arguments, $p.ExitCode, $text)
        if ($OkExitCodes -notcontains $p.ExitCode) {
            throw ("{0} failed with exit code {1}: {2}" -f $FilePath, $p.ExitCode, $text)
        }
        return $p.ExitCode
    } finally {
        Remove-Item -LiteralPath $out -ErrorAction SilentlyContinue
    }
}

function Get-HostService {
    return Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
}

function Get-HostConfig {
    # Same precedence as the host: appsettings.json, then appsettings.Production.json.
    $config = @{ Port = 8443; Https = $true }
    foreach ($name in @('appsettings.json', 'appsettings.Production.json')) {
        $file = Join-Path $ServiceDir $name
        if (-not (Test-Path -LiteralPath $file)) { continue }
        try {
            $json = Get-Content -LiteralPath $file -Raw | ConvertFrom-Json
            if (-not $json.PSObject.Properties['MediCare']) { continue }
            $section = $json.MediCare
            if ($section.PSObject.Properties['Port'] -and $section.Port) { $config.Port = [int]$section.Port }
            if ($section.PSObject.Properties['UseHttps'] -and $null -ne $section.UseHttps) { $config.Https = [bool]$section.UseHttps }
        } catch {
            Write-Log "Could not read $file; using port $($config.Port). $_"
        }
    }
    return $config
}

function Stop-HostService {
    $svc = Get-HostService
    if (-not $svc) { Write-Log 'Service not installed; nothing to stop.'; return }
    if ($svc.Status -eq 'Stopped') { Write-Log 'Service already stopped.'; return }

    Write-Log "Stopping $ServiceName (status $($svc.Status))."
    try {
        if ($svc.Status -ne 'StopPending') { $svc.Stop() }
        $svc.WaitForStatus('Stopped', (New-TimeSpan -Seconds $TimeoutSec))
    } catch {
        # A hung host must not block an upgrade forever. SQLite (WAL) recovers safely from a killed process.
        $procId = (Get-CimInstance -ClassName Win32_Service -Filter "Name='$ServiceName'").ProcessId
        Write-Log "Service did not stop within $TimeoutSec s ($_). Killing process $procId."
        if ($procId) { Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue }
        $svc.WaitForStatus('Stopped', (New-TimeSpan -Seconds 30))
    }
    Write-Log 'Service stopped.'
}


function Test-HostHealthy($Config) {
    if (-not ('MediCareHealthCheck' -as [type])) {
        # C# 5 so that Windows PowerShell 5.1 can compile it; raw HTTP over SslStream works on .NET Framework and .NET.
        Add-Type -TypeDefinition @'
using System;
using System.IO;
using System.Net.Security;
using System.Net.Sockets;
using System.Security.Authentication;
using System.Security.Cryptography.X509Certificates;
using System.Text;

public static class MediCareHealthCheck
{
    // Loopback only: the host's self-signed certificate never matches 127.0.0.1, so any certificate is accepted
    // here. Counter PCs pin the real fingerprint instead.
    private static bool AcceptLoopback(object sender, X509Certificate cert, X509Chain chain, SslPolicyErrors errors) { return true; }

    public static bool IsHealthy(int port, bool https, int timeoutMs)
    {
        const string host = "127.0.0.1";
        using (var tcp = new TcpClient())
        {
            var connect = tcp.BeginConnect(host, port, null, null);
            if (!connect.AsyncWaitHandle.WaitOne(timeoutMs)) return false;
            tcp.EndConnect(connect);
            tcp.ReceiveTimeout = timeoutMs;
            tcp.SendTimeout = timeoutMs;
            Stream stream = tcp.GetStream();
            if (https)
            {
                var ssl = new SslStream(stream, false, AcceptLoopback);
                ssl.AuthenticateAsClient(host, null, SslProtocols.Tls12, false);
                stream = ssl;
            }
            using (stream)
            {
                var request = Encoding.ASCII.GetBytes("GET /api/v1/health HTTP/1.1\r\nHost: " + host + ":" + port +
                    "\r\nAccept: application/json\r\nConnection: close\r\n\r\n");
                stream.Write(request, 0, request.Length);
                stream.Flush();
                using (var reader = new StreamReader(stream, Encoding.UTF8))
                {
                    var response = reader.ReadToEnd();
                    var statusLine = response.Split(new[] { '\r', '\n' }, 2)[0].Split(' ');
                    return statusLine.Length > 1 && statusLine[1] == "200" && response.Contains("\"ok\":true");
                }
            }
        }
    }
}
'@
    }
    try {
        return [MediCareHealthCheck]::IsHealthy([int]$Config.Port, [bool]$Config.Https, 5000)
    } catch {
        return $false
    }
}

# Returns 0 healthy, 2 not running, 3 running but not answering.
function Start-HostServiceAndWait {
    $svc = Get-HostService
    if (-not $svc) { throw "Service $ServiceName is not installed." }
    $config = Get-HostConfig
    if ($svc.Status -ne 'Running' -and $svc.Status -ne 'StartPending') {
        Write-Log "Starting $ServiceName."
        $svc.Start()
    }

    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 2
        $svc.Refresh()
        if ($svc.Status -eq 'Stopped') {
            Write-Log 'Service stopped by itself during start-up. See host-*.log in the logs folder.'
            return 2
        }
        if ($svc.Status -eq 'Running' -and (Test-HostHealthy $config)) {
            Write-Log "Host is healthy on port $($config.Port)."
            return 0
        }
    }
    $svc.Refresh()
    if ($svc.Status -ne 'Running') { Write-Log "Service status after $TimeoutSec s: $($svc.Status)."; return 2 }
    Write-Log "Service is running but /api/v1/health did not answer on port $($config.Port) within $TimeoutSec s."
    return 3
}

function Set-DataFolderAcl {
    if (-not (Test-Path -LiteralPath $DataDir)) { New-Item -ItemType Directory -Path $DataDir -Force | Out-Null }
    # Only SYSTEM, Administrators and the service account may open the database, certificate and backups
    # (architecture.md, threat "DB file copied"). Inheritance from ProgramData is removed so that the
    # client installer's "Users: modify" on the parent folder never reaches this folder.
    $grants = @('"*S-1-5-18:(OI)(CI)F"', '"*S-1-5-32-544:(OI)(CI)F"')
    if ($Account -and $Account -ne 'LocalSystem') { $grants += ('"{0}:(OI)(CI)M"' -f $Account) }
    Invoke-Native "$env:SystemRoot\System32\icacls.exe" ('"{0}" /inheritance:r /grant:r {1} /Q' -f $DataDir, ($grants -join ' ')) | Out-Null
}

function Install-HostService {
    $exe = Join-Path $ServiceDir $ExeName
    if (-not (Test-Path -LiteralPath $exe)) { throw "Host executable not found: $exe" }
    $sc = "$env:SystemRoot\System32\sc.exe"
    $binPath = 'binPath= "\"{0}\""' -f $exe
    $obj = if ($Account -eq 'LocalSystem') { 'obj= LocalSystem' } else { 'obj= "{0}"' -f $Account }
    $common = '{0} start= delayed-auto {1} DisplayName= "{2}"' -f $binPath, $obj, $DisplayName

    if (Get-HostService) {
        Invoke-Native $sc ('config {0} {1}' -f $ServiceName, $common) | Out-Null
    } else {
        Invoke-Native $sc ('create {0} type= own {1}' -f $ServiceName, $common) | Out-Null
    }
    Invoke-Native $sc ('description {0} "Central MediCare Pharmacy service: owns the database and serves counter PCs on the local network."' -f $ServiceName) | Out-Null
    # Restart after 5 s, 15 s, then every 60 s; reset the failure count after a day without failures.
    Invoke-Native $sc ('failure {0} reset= 86400 actions= restart/5000/restart/15000/restart/60000' -f $ServiceName) | Out-Null
    # Also apply the recovery actions when the process exits with an error code (not only on crashes).
    Invoke-Native $sc ('failureflag {0} 1' -f $ServiceName) | Out-Null
    if ($Account -ne 'LocalSystem') {
        Invoke-Native $sc ('sidtype {0} unrestricted' -f $ServiceName) | Out-Null
    }
    Set-DataFolderAcl
    Write-Log "Service $ServiceName registered for $exe as $Account."
}

function Copy-Database([string]$From, [string]$To) {
    if (-not (Test-Path -LiteralPath $To)) { New-Item -ItemType Directory -Path $To -Force | Out-Null }
    foreach ($name in $DbFiles) {
        $src = Join-Path $From $name
        if (Test-Path -LiteralPath $src) { Copy-Item -LiteralPath $src -Destination (Join-Path $To $name) -Force }
    }
}

function Clear-ServiceDir {
    if (-not (Test-Path -LiteralPath $ServiceDir)) { return }
    Get-ChildItem -LiteralPath $ServiceDir -Force |
        Where-Object { $KeepFiles -notcontains $_.Name } |
        Remove-Item -Recurse -Force
}

function Invoke-PrepareUpgrade {
    if (-not $SafetyDir) { throw '-SafetyDir is required.' }
    Stop-HostService

    if (Test-Path -LiteralPath (Join-Path $DataDir 'medicare.db')) {
        # The service is stopped, so a plain file copy is consistent (ADR-016: safety backup before upgrade).
        Copy-Database $DataDir $SafetyDir
        Write-Log "Safety copy of the database written to $SafetyDir."
    } else {
        Write-Log 'No database yet; no safety copy needed.'
    }

    if (Test-Path -LiteralPath (Join-Path $ServiceDir $ExeName)) {
        $previous = "$ServiceDir.previous"
        if (Test-Path -LiteralPath $previous) { Remove-Item -LiteralPath $previous -Recurse -Force }
        Copy-Item -LiteralPath $ServiceDir -Destination $previous -Recurse -Force
        Write-Log "Previous binaries kept in $previous."
        # Remove old binaries so files dropped in the new version do not linger.
        Clear-ServiceDir
    }
}

function Invoke-Rollback {
    $previous = "$ServiceDir.previous"
    if (-not (Test-Path -LiteralPath (Join-Path $previous $ExeName))) { throw "No previous version to roll back to ($previous)." }
    Stop-HostService

    Clear-ServiceDir
    if (-not (Test-Path -LiteralPath $ServiceDir)) { New-Item -ItemType Directory -Path $ServiceDir -Force | Out-Null }
    Get-ChildItem -LiteralPath $previous -Force |
        Where-Object { $KeepFiles -notcontains $_.Name } |
        Copy-Item -Destination $ServiceDir -Recurse -Force
    Write-Log "Binaries restored from $previous."

    if ($SafetyDir -and (Test-Path -LiteralPath (Join-Path $SafetyDir 'medicare.db'))) {
        # The new version never became healthy, so no counter could have posted to it. Keep its database
        # for diagnosis, then put back the copy taken before the upgrade (undoes any partial migration).
        $failed = Join-Path $SafetyDir 'failed-upgrade'
        Copy-Database $DataDir $failed
        foreach ($name in $DbFiles) { Remove-Item -LiteralPath (Join-Path $DataDir $name) -Force -ErrorAction SilentlyContinue }
        Copy-Database $SafetyDir $DataDir
        Write-Log "Database restored from $SafetyDir (the failed upgrade's copy is in $failed)."
    }

    return (Start-HostServiceAndWait)
}

function Remove-HostService {
    if (-not (Get-HostService)) { Write-Log 'Service not installed.'; return }
    Stop-HostService
    # 1072 = already marked for deletion.
    Invoke-Native "$env:SystemRoot\System32\sc.exe" ('delete {0}' -f $ServiceName) @(0, 1072) | Out-Null
    Write-Log "Service $ServiceName removed. The data folder $DataDir was not touched."
}

try {
    switch ($Action) {
        'Install'        { Install-HostService; exit 0 }
        'Stop'           { Stop-HostService; exit 0 }
        'StartAndWait'   { exit (Start-HostServiceAndWait) }
        'PrepareUpgrade' { Invoke-PrepareUpgrade; exit 0 }
        'Rollback'       { exit (Invoke-Rollback) }
        'Remove'         { Remove-HostService; exit 0 }
    }
} catch {
    Write-Log "ERROR: $($_.Exception.Message)"
    exit 1
}
