; ============================================================================
;  MediCare Practice — Windows installer (Inno Setup 6)
;  Build with installer\build.ps1, which stages files into installer\build\.
; ============================================================================

#define AppName      "MediCare Practice"
#define AppVersion   "1.0.0"
#define AppPublisher "MediCare Practice"
#define AppExe       "MediCare.exe"

[Setup]
AppId={{8F3A2C1E-5B7D-4E2A-9C61-0A2463C9A227}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher={#AppPublisher}
VersionInfoVersion={#AppVersion}
VersionInfoDescription={#AppName} Setup
; A writable folder (like C:\xampp): the database and backups live inside it.
DefaultDirName={sd}\MediCarePractice
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
OutputDir=output
OutputBaseFilename=MediCarePractice-Setup-{#AppVersion}
SetupIconFile=medicare.ico
UninstallDisplayIcon={app}\{#AppExe}
UninstallDisplayName={#AppName}
Compression=lzma2/ultra64
SolidCompression=yes
LZMANumBlockThreads=4
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequired=admin
MinVersion=10.0
WizardStyle=modern
CloseApplications=no
RestartIfNeededByRun=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Messages]
WelcomeLabel2=This will install [name/ver] on your computer.%n%nMediCare Practice runs completely offline: the database and the application server are included, no internet, XAMPP or other software is needed.%n%nAfter installation, open it from the desktop icon.

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Shortcuts:"
Name: "autostart"; Description: "Start MediCare Practice automatically when Windows starts"; GroupDescription: "Options:"; Flags: unchecked
Name: "demo"; Description: "Load demo patients and visits (for evaluation only, not for a live clinic)"; GroupDescription: "Options:"; Flags: unchecked

[Dirs]
Name: "{app}"; Permissions: users-modify
Name: "{app}\data"; Permissions: users-modify
Name: "{app}\logs"; Permissions: users-modify

[Files]
Source: "build\*"; Excludes: "redist"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "build\redist\vc_redist.x64.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall; Check: VCRedistNeeded

[INI]
Filename: "{app}\data\launcher.ini"; Section: "launcher"; Key: "demo"; String: "1"; Tasks: demo

[Icons]
Name: "{autoprograms}\{#AppName}\{#AppName}"; Filename: "{app}\{#AppExe}"; WorkingDir: "{app}"
Name: "{autoprograms}\{#AppName}\Backups folder"; Filename: "{app}\app\storage\backups"
Name: "{autoprograms}\{#AppName}\Stop MediCare Practice"; Filename: "{app}\{#AppExe}"; Parameters: "--stop"; WorkingDir: "{app}"
Name: "{autoprograms}\{#AppName}\Uninstall {#AppName}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AppExe}"; WorkingDir: "{app}"; Tasks: desktopicon
Name: "{userstartup}\{#AppName}"; Filename: "{app}\{#AppExe}"; Parameters: "--tray"; WorkingDir: "{app}"; Tasks: autostart

[Run]
Filename: "{tmp}\vc_redist.x64.exe"; Parameters: "/install /quiet /norestart"; StatusMsg: "Installing Microsoft Visual C++ runtime..."; Check: VCRedistNeeded; Flags: waituntilterminated
Filename: "{app}\{#AppExe}"; Description: "Start {#AppName} now"; Flags: nowait postinstall skipifsilent runasoriginaluser

[UninstallRun]
Filename: "{app}\{#AppExe}"; Parameters: "--stop"; Flags: runhidden waituntilterminated; RunOnceId: "StopServers"

[UninstallDelete]
Type: filesandordirs; Name: "{app}\data\sessions"
Type: filesandordirs; Name: "{app}\data\tmp"
Type: files; Name: "{app}\data\my.ini"

[Code]
{ PHP 8.2 and MariaDB need the Visual C++ 2015-2022 runtime (14.29 or newer). }
function VCRedistNeeded: Boolean;
var
  Installed, Major, Minor: Cardinal;
begin
  Result := True;
  if RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Installed', Installed) and (Installed = 1) and
     RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Major', Major) and
     RegQueryDWordValue(HKLM64, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Minor', Minor) then
    Result := (Major < 14) or ((Major = 14) and (Minor < 29));
end;

{ Upgrading: stop the running servers first so their files can be replaced. }
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
  Exe: String;
begin
  Result := '';
  Exe := ExpandConstant('{app}\{#AppExe}');
  if FileExists(Exe) then
    Exec(Exe, '--stop', ExpandConstant('{app}'), SW_HIDE, ewWaitUntilTerminated, Code);
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if (CurUninstallStep = usPostUninstall) and not UninstallSilent then
    MsgBox('MediCare Practice has been removed.' + #13#10#13#10 +
           'Your patient database and backups were NOT deleted. They are kept in:' + #13#10 +
           ExpandConstant('{app}\data') + #13#10 + ExpandConstant('{app}\app\storage\backups') + #13#10#13#10 +
           'Reinstall into the same folder to continue with the same data, or delete that folder manually.',
           mbInformation, MB_OK);
end;
