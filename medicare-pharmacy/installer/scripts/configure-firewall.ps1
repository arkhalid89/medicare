<#
.SYNOPSIS
    Adds (or removes) the Windows Firewall rule that lets counter PCs reach the MediCare host.

.DESCRIPTION
    Inbound TCP on the host port, only from the local subnet, only on Private and Domain networks.
    Idempotent: an existing MediCare rule is replaced, so re-running with a new -Port updates it.
    Run elevated on the host PC (Windows 10/11, Windows PowerShell 5.1).

.PARAMETER Port
    Host port (MediCare:Port in appsettings). Default 8443.

.PARAMETER Program
    Optional full path of MediCare.Host.exe; when given, the rule also applies only to that program.

.PARAMETER Remove
    Remove the rule instead of adding it.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File configure-firewall.ps1 -Port 8443
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File configure-firewall.ps1 -Remove
#>
#Requires -RunAsAdministrator
[CmdletBinding()]
param(
    [ValidateRange(1, 65535)]
    [int]$Port = 8443,
    [string]$Program,
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

$RuleName = 'MediCarePharmacy-Host-In-TCP'   # stable ID; the display name is for people
$DisplayName = 'MediCare Pharmacy Host (counter PCs)'
$Group = 'MediCare Pharmacy'

try {
    $existing = @(Get-NetFirewallRule -Name $RuleName -ErrorAction SilentlyContinue)
    if ($existing.Count -gt 0) {
        $existing | Remove-NetFirewallRule
        Write-Host "Removed existing rule '$DisplayName'."
    }

    if ($Remove) {
        exit 0
    }

    $ruleArgs = @{
        Name          = $RuleName
        DisplayName   = $DisplayName
        Group         = $Group
        Description   = "Lets MediCare counter PCs on this local network connect to the host on TCP port $Port. Added by the MediCare Host installer."
        Direction     = 'Inbound'
        Action        = 'Allow'
        Protocol      = 'TCP'
        LocalPort     = $Port
        RemoteAddress = 'LocalSubnet'
        Profile       = @('Domain', 'Private')
        Enabled       = 'True'
    }
    if ($Program) { $ruleArgs.Program = $Program }
    New-NetFirewallRule @ruleArgs | Out-Null
    Write-Host "Added rule '$DisplayName': TCP $Port from LocalSubnet on Domain and Private networks."

    # The rule deliberately does not cover Public networks; warn if the shop LAN is classified that way.
    $public = @(Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object { $_.NetworkCategory -eq 'Public' })
    foreach ($p in $public) {
        Write-Warning ("Network '{0}' ({1}) is set to Public, so counter PCs on it cannot connect. " -f $p.Name, $p.InterfaceAlias) +
            'In Settings > Network & Internet, set the shop network to Private.'
    }
    exit 0
} catch {
    Write-Error "Firewall configuration failed: $($_.Exception.Message)" -ErrorAction Continue
    exit 1
}
