; MediCare Pharmacy - Client (counter PC) installer (ADR-004, ADR-016). Inno Setup 6.5 or later.
;
; One script, two editions, chosen at compile time:
;   ISCC.exe /DTarget=win10 MediCareClient.iss   Windows 10/11 x64, self-contained .NET 10 build
;   ISCC.exe /DTarget=win7  MediCareClient.iss   Windows 7 SP1 (x86/x64), .NET Framework 4.8 build
;
; Build inputs (produced by ..\build.ps1):
;   ..\artifacts\client-win10\  dotnet publish src\MediCare.Client -f net10.0-windows -r win-x64 --self-contained
;   ..\artifacts\client-win7\   dotnet publish src\MediCare.Client -f net48
;   fonts\*.ttf                 optional, see fonts\README.md
;
; Both editions share one AppId, so they upgrade in place and a PC moved from Windows 7 to Windows 10
; can switch edition by installing over the top.

#ifndef Target
  #define Target "win10"
#endif
#if Target != "win10" && Target != "win7"
  #error Target must be "win10" or "win7"
#endif

#define AppName "MediCare Pharmacy"
#define ExeName "MediCarePharmacy.exe"
#define SourceBuild "..\artifacts\client-" + Target

#ifndef AppVersion
  #define AppVersion GetVersionNumbersString(AddBackslash(SourcePath) + SourceBuild + "\" + ExeName)
#endif

[Setup]
; Never change this AppId: it is what makes a newer installer upgrade the existing installation in place.
AppId={{F8C0317C-5458-493F-9CB6-9A1E7F0F7C58}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher=MediCare Pharmacy
AppCopyright=MediCare Pharmacy
VersionInfoVersion={#AppVersion}
VersionInfoProductName={#AppName}
DefaultDirName={autopf}\MediCare Pharmacy\Client
UsePreviousAppDir=yes
DisableProgramGroupPage=yes
PrivilegesRequired=admin
WizardStyle=modern
SetupIconFile=..\src\MediCare.Client\Assets\app.ico
UninstallDisplayIcon={app}\{#ExeName}
UninstallDisplayName={#AppName}
OutputDir=..\artifacts\installers
OutputBaseFilename=MediCarePharmacy-Client-{#Target}-Setup-{#AppVersion}
Compression=lzma2/max
SolidCompression=yes
; Closes a running MediCare Pharmacy before its files are replaced (drafts are kept on disk by the client).
CloseApplications=yes
RestartApplications=no
SetupLogging=yes
#if Target == "win10"
MinVersion=10.0
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
#else
MinVersion=6.1sp1
; The net48 build is AnyCPU: 32-bit Windows 7 is supported, and 64-bit Windows installs to Program Files.
ArchitecturesInstallIn64BitMode=x64compatible
#endif

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Shortcuts:"
#if Target == "win7"
Name: "tls12"; Description: "Enable TLS 1.2 in Windows and .NET Framework (required to connect securely to the MediCare host; Windows restart needed)"; GroupDescription: "Security:"
#endif

[Dirs]
; client.json (host address and pinned certificate fingerprint) is shared by every Windows user on this PC.
Name: "{commonappdata}\MediCarePharmacy"; Permissions: users-modify; Flags: uninsneveruninstall

[Files]
Source: "{#SourceBuild}\*"; DestDir: "{app}"; Excludes: "*.pdb"; Flags: ignoreversion recursesubdirs createallsubdirs
#include "fonts.iss"

[Icons]
Name: "{autoprograms}\MediCare Pharmacy"; Filename: "{app}\{#ExeName}"; Comment: "MediCare Pharmacy point of sale and back office"
Name: "{autodesktop}\MediCare Pharmacy"; Filename: "{app}\{#ExeName}"; Comment: "MediCare Pharmacy point of sale and back office"; Tasks: desktopicon

#if Target == "win7"
[Registry]
; Same settings as scripts\enable-tls12-win7.ps1. No uninsdelete* flag, so they stay after uninstall: they are system-wide security settings
; that other software may rely on once enabled.
Root: HKLM; Subkey: "SYSTEM\CurrentControlSet\Control\SecurityProviders\SCHANNEL\Protocols\TLS 1.2\Client"; ValueType: dword; ValueName: "Enabled"; ValueData: 1; Tasks: tls12
Root: HKLM; Subkey: "SYSTEM\CurrentControlSet\Control\SecurityProviders\SCHANNEL\Protocols\TLS 1.2\Client"; ValueType: dword; ValueName: "DisabledByDefault"; ValueData: 0; Tasks: tls12
Root: HKLM32; Subkey: "SOFTWARE\Microsoft\.NETFramework\v4.0.30319"; ValueType: dword; ValueName: "SchUseStrongCrypto"; ValueData: 1; Tasks: tls12
Root: HKLM32; Subkey: "SOFTWARE\Microsoft\.NETFramework\v4.0.30319"; ValueType: dword; ValueName: "SystemDefaultTlsVersions"; ValueData: 1; Tasks: tls12
Root: HKLM64; Subkey: "SOFTWARE\Microsoft\.NETFramework\v4.0.30319"; ValueType: dword; ValueName: "SchUseStrongCrypto"; ValueData: 1; Tasks: tls12; Check: IsWin64
Root: HKLM64; Subkey: "SOFTWARE\Microsoft\.NETFramework\v4.0.30319"; ValueType: dword; ValueName: "SystemDefaultTlsVersions"; ValueData: 1; Tasks: tls12; Check: IsWin64
#endif

[Run]
; Run as the signed-in user, not elevated: the client must never run with administrator rights.
Filename: "{app}\{#ExeName}"; Description: "Start MediCare Pharmacy"; Flags: postinstall nowait skipifsilent runasoriginaluser

[Code]
#if Target == "win7"
const
  DotNet48Release = 528040;
  DotNet48Url = 'https://dotnet.microsoft.com/download/dotnet-framework/net48';
  Tls12ClientKey = 'SYSTEM\CurrentControlSet\Control\SecurityProviders\SCHANNEL\Protocols\TLS 1.2\Client';

var
  Tls12WasOff: Boolean;

function IsDotNet48Installed: Boolean;
var
  Release: Cardinal;
begin
  Result := RegQueryDWordValue(HKLM, 'SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full', 'Release', Release) and (Release >= DotNet48Release);
end;

function IsTls12ClientOn: Boolean;
var
  Enabled, DisabledByDefault: Cardinal;
begin
  Result := RegQueryDWordValue(HKLM, Tls12ClientKey, 'Enabled', Enabled) and (Enabled <> 0) and
    RegQueryDWordValue(HKLM, Tls12ClientKey, 'DisabledByDefault', DisabledByDefault) and (DisabledByDefault = 0);
end;

function InitializeSetup: Boolean;
var
  Version: TWindowsVersion;
begin
  Result := True;
  if not IsDotNet48Installed then
  begin
    SuppressibleMsgBox('MediCare Pharmacy for Windows 7 needs Microsoft .NET Framework 4.8, which is not installed on this PC.' + #13#10#13#10 +
      'Install the .NET Framework 4.8 offline installer (ndp48-x86-x64-allos-enu.exe) from' + #13#10 + DotNet48Url + #13#10 +
      '(copy it on a USB drive if this PC has no internet), restart Windows, then run this setup again.',
      mbCriticalError, MB_OK, IDOK);
    Result := False;
    Exit;
  end;

  GetWindowsVersionEx(Version);
  if (Version.Major >= 10) and not WizardSilent then
    Result := MsgBox('This is the Windows 7 edition of MediCare Pharmacy. This PC runs Windows 10 or 11, where the Windows 10/11 edition is recommended.' + #13#10#13#10 +
      'Install the Windows 7 edition anyway?', mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES;

  Tls12WasOff := not IsTls12ClientOn;
end;

{ SChannel reads its protocol settings at boot, so ask for a restart only when this setup turned TLS 1.2 on. }
function NeedRestart: Boolean;
begin
  Result := Tls12WasOff and WizardIsTaskSelected('tls12');
end;
#endif
