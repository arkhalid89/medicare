; MediCare Pharmacy - Host installer (ADR-016). Inno Setup 6.5 or later.
;
; Installs the self-contained .NET 10 host service and the Host Monitor on the host PC (Windows 10/11 x64),
; registers and starts the Windows service, opens the firewall to the local subnet (with consent),
; installs the UI fonts and protects the data folder. Re-running a newer build upgrades in place:
; stop service -> safety copy of the database -> replace binaries -> start (migrations run) -> health check
; -> automatic rollback of binaries and database if the new version does not become healthy.
; Uninstall removes the service but keeps the data folder unless the administrator chooses otherwise.
;
; Build inputs (produced by ..\build.ps1):
;   ..\artifacts\host\         dotnet publish src\MediCare.Host        -r win-x64 --self-contained
;   ..\artifacts\hostmonitor\  dotnet publish src\MediCare.HostMonitor -r win-x64 --self-contained
;   fonts\*.ttf                optional, see fonts\README.md
;
; Compile: ISCC.exe [/DAppVersion=1.2.3] MediCareHost.iss

#define AppName "MediCare Pharmacy Host"
#define ServiceName "MediCarePharmacyHost"
; Virtual service account (least privilege). Set to "LocalSystem" only if the host cannot run under it.
#define ServiceAccount "NT SERVICE\MediCarePharmacyHost"
; Must match MediCare:Port in the host's appsettings (used for the firewall rule).
#define HostPort "8443"
#define HostDir "..\artifacts\host"
#define MonitorDir "..\artifacts\hostmonitor"

#ifndef AppVersion
  #define AppVersion GetVersionNumbersString(AddBackslash(SourcePath) + HostDir + "\MediCare.Host.exe")
#endif

[Setup]
; Never change this AppId: it is what makes a newer installer upgrade the existing installation in place.
AppId={{4E65573A-68F3-40DC-B816-C47014436E6D}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher=MediCare Pharmacy
AppCopyright=MediCare Pharmacy
VersionInfoVersion={#AppVersion}
VersionInfoProductName={#AppName}
DefaultDirName={autopf}\MediCare Pharmacy\Host
UsePreviousAppDir=yes
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
WizardStyle=modern
SetupIconFile=..\src\MediCare.HostMonitor\Assets\app.ico
UninstallDisplayIcon={app}\Monitor\MediCare.HostMonitor.exe
UninstallDisplayName={#AppName}
OutputDir=..\artifacts\installers
OutputBaseFilename=MediCarePharmacy-Host-Setup-{#AppVersion}
Compression=lzma2/max
SolidCompression=yes
; Closes a running Host Monitor before its files are replaced.
CloseApplications=yes
RestartApplications=no
SetupLogging=yes
ChangesAssociations=no

[Tasks]
Name: "firewall"; Description: "Allow counter PCs on this local network to connect (Windows Firewall: TCP port {#HostPort}, local subnet, Private and Domain networks only)"; GroupDescription: "Network:"

[Dirs]
Name: "{commonappdata}\MediCarePharmacy"; Flags: uninsneveruninstall
; ACL is set by host-service.ps1 once the service account exists (SYSTEM, Administrators, service account only).
Name: "{commonappdata}\MediCarePharmacy\Data"; Flags: uninsneveruninstall

[Files]
Source: "{#HostDir}\*"; DestDir: "{app}\Service"; Excludes: "*.pdb"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#MonitorDir}\*"; DestDir: "{app}\Monitor"; Excludes: "*.pdb"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "scripts\host-service.ps1"; DestDir: "{app}\Scripts"; Flags: ignoreversion
Source: "scripts\configure-firewall.ps1"; DestDir: "{app}\Scripts"; Flags: ignoreversion
; Extracted to {tmp} before files are copied, to stop the old service and take the safety copy.
Source: "scripts\host-service.ps1"; Flags: dontcopy
#include "fonts.iss"

[Icons]
Name: "{autoprograms}\MediCare Pharmacy\MediCare Host Monitor"; Filename: "{app}\Monitor\MediCare.HostMonitor.exe"; Comment: "Host service status, connected counters and the pairing fingerprint"

[Run]
Filename: "{app}\Monitor\MediCare.HostMonitor.exe"; Description: "Open MediCare Host Monitor (shows the fingerprint needed to pair counter PCs)"; Flags: postinstall nowait skipifsilent

[UninstallDelete]
Type: filesandordirs; Name: "{app}\Service"
Type: filesandordirs; Name: "{app}\Service.previous"
Type: dirifempty; Name: "{app}"

[Code]
const
  ServiceName = '{#ServiceName}';

var
  IsUpgrade: Boolean;
  UpgradePrepared: Boolean;   { old service stopped and its binaries moved to Service.previous }
  PostInstallReached: Boolean;
  SafetyDir: String;
  KeepData: Boolean;

function DataDir: String;
begin
  Result := ExpandConstant('{commonappdata}\MediCarePharmacy\Data');
end;

function ServiceExists: Boolean;
begin
  Result := RegKeyExists(HKLM, 'SYSTEM\CurrentControlSet\Services\' + ServiceName);
end;

{ Runs a PowerShell script with the 64-bit Windows PowerShell and returns its exit code (-1 if it could not start). }
function RunPowerShell(const ScriptPath, Arguments: String): Integer;
var
  Params: String;
  ResultCode: Integer;
begin
  Params := '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + ScriptPath + '" ' + Arguments;
  if Exec(ExpandConstant('{sysnative}\WindowsPowerShell\v1.0\powershell.exe'), Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
    Result := ResultCode
  else
    Result := -1;
  Log(Format('powershell %s -> %d', [Params, Result]));
end;

function ServiceArgs: String;
begin
  Result := '-ServiceName "' + ServiceName + '" -ServiceDir "' + ExpandConstant('{app}\Service') + '" -DataDir "' + DataDir + '"';
end;

function SetupLogHint: String;
begin
  Result := 'Details: ' + DataDir + '\logs\setup-service.log';
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
begin
  Result := '';
  IsUpgrade := ServiceExists;
  if not IsUpgrade then
    Exit;

  ExtractTemporaryFile('host-service.ps1');
  SafetyDir := DataDir + '\pre-upgrade\' + GetDateTimeString('yyyymmdd-hhnnss', '-', ':');
  Code := RunPowerShell(ExpandConstant('{tmp}\host-service.ps1'), '-Action PrepareUpgrade ' + ServiceArgs + ' -SafetyDir "' + SafetyDir + '"');
  if Code <> 0 then
    Result := 'Setup could not stop the MediCare host service or could not make the safety copy of the database (code ' +
      IntToStr(Code) + '). Nothing has been changed; the old version is still installed.' + #13#10#13#10 + SetupLogHint
  else
    UpgradePrepared := True;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  Script: String;
  Code: Integer;
begin
  if CurStep <> ssPostInstall then
    Exit;
  PostInstallReached := True;
  Script := ExpandConstant('{app}\Scripts\host-service.ps1');

  WizardForm.StatusLabel.Caption := 'Registering the MediCare host service...';
  Code := RunPowerShell(Script, '-Action Install ' + ServiceArgs + ' -Account "{#ServiceAccount}"');
  if Code <> 0 then
  begin
    SuppressibleMsgBox('The MediCare host service could not be registered (code ' + IntToStr(Code) + ').' + #13#10#13#10 +
      SetupLogHint, mbCriticalError, MB_OK, IDOK);
    Exit;
  end;

  if WizardIsTaskSelected('firewall') then
  begin
    WizardForm.StatusLabel.Caption := 'Adding the Windows Firewall rule...';
    if RunPowerShell(ExpandConstant('{app}\Scripts\configure-firewall.ps1'),
      '-Port {#HostPort} -Program "' + ExpandConstant('{app}\Service\MediCare.Host.exe') + '"') <> 0 then
      SuppressibleMsgBox('The Windows Firewall rule could not be added, so counter PCs may not be able to connect.' + #13#10 +
        'Run Scripts\configure-firewall.ps1 as administrator later.', mbError, MB_OK, IDOK);
  end;

  { First start creates the database (migrations) and the TLS certificate; an upgrade migrates the database. }
  WizardForm.StatusLabel.Caption := 'Starting the MediCare host and checking that it answers...';
  Code := RunPowerShell(Script, '-Action StartAndWait ' + ServiceArgs + ' -TimeoutSec 120');
  if Code = 0 then
    Exit;

  if not IsUpgrade then
  begin
    SuppressibleMsgBox('The MediCare host service was installed but did not answer within 2 minutes.' + #13#10 +
      'Open MediCare Host Monitor and check the host log in the logs folder.' + #13#10#13#10 + SetupLogHint, mbError, MB_OK, IDOK);
    Exit;
  end;

  WizardForm.StatusLabel.Caption := 'The new version did not start. Restoring the previous version...';
  Code := RunPowerShell(Script, '-Action Rollback ' + ServiceArgs + ' -SafetyDir "' + SafetyDir + '" -TimeoutSec 120');
  if Code = 0 then
    SuppressibleMsgBox('The new version of the MediCare host did not start, so the previous version and its database were restored and are running again.' + #13#10#13#10 +
      'No data was lost. Send the host log and setup log to support before trying the upgrade again.' + #13#10 + SetupLogHint, mbError, MB_OK, IDOK)
  else
    SuppressibleMsgBox('The new version of the MediCare host did not start, and restoring the previous version also failed (code ' + IntToStr(Code) + ').' + #13#10#13#10 +
      'The database from before the upgrade is safe in:' + #13#10 + SafetyDir + #13#10#13#10 +
      'Call support before using the system. ' + SetupLogHint, mbCriticalError, MB_OK, IDOK);
end;

{ Setup was cancelled (or failed) after the old service was stopped: put the previous version back and start it. }
procedure DeinitializeSetup;
var
  Script: String;
  Code: Integer;
begin
  if not UpgradePrepared or PostInstallReached then
    Exit;
  Script := ExpandConstant('{tmp}\host-service.ps1');
  Code := -1;
  if FileExists(Script) then
    Code := RunPowerShell(Script, '-Action Rollback ' + ServiceArgs + ' -SafetyDir "' + SafetyDir + '" -TimeoutSec 120');
  if Code = 0 then
    SuppressibleMsgBox('Setup did not finish, so the previous version of the MediCare host was restored and is running again.', mbInformation, MB_OK, IDOK)
  else
    SuppressibleMsgBox('Setup did not finish and the previous version of the MediCare host could not be restarted automatically (code ' + IntToStr(Code) + ').' + #13#10#13#10 +
      'Run this setup again to complete the upgrade. The database from before the upgrade is safe in:' + #13#10 + SafetyDir + #13#10#13#10 + SetupLogHint,
      mbCriticalError, MB_OK, IDOK);
end;

function InitializeUninstall: Boolean;
begin
  KeepData := True;
  Result := True;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var
  ResultCode: Integer;
begin
  case CurUninstallStep of
    usUninstall:
      begin
        if not UninstallSilent then
          if MsgBox('Keep the pharmacy data on this PC?' + #13#10#13#10 +
            'Yes (recommended): the database, backups and host certificate stay in' + #13#10 + DataDir + #13#10 +
            'and are used again if MediCare Host is reinstalled.' + #13#10#13#10 +
            'No: delete them.', mbConfirmation, MB_YESNO) = IDNO then
            KeepData := MsgBox('Permanently delete ALL pharmacy data (sales, stock, accounts, backups in the data folder and the host certificate)?' + #13#10#13#10 +
              'This cannot be undone. Backups copied to USB drives are not affected.', mbError, MB_YESNO or MB_DEFBUTTON2) <> IDYES;

        Exec(ExpandConstant('{sys}\taskkill.exe'), '/F /IM MediCare.HostMonitor.exe', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
        RunPowerShell(ExpandConstant('{app}\Scripts\host-service.ps1'), '-Action Remove -ServiceName "' + ServiceName + '" -DataDir "' + DataDir + '"');
        RunPowerShell(ExpandConstant('{app}\Scripts\configure-firewall.ps1'), '-Remove');
      end;
    usPostUninstall:
      if not KeepData then
      begin
        DelTree(DataDir, True, True, True);
        RemoveDir(ExpandConstant('{commonappdata}\MediCarePharmacy'));
      end;
  end;
end;
