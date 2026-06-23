; ============================================================================
; ADMS Server - Inno Setup installer script
; Compile with Inno Setup 6 (https://jrsoftware.org/isdl.php) on Windows:
;     "C:\Program Files (x86)\Inno Setup 6\ISCC.exe" adms-installer.iss
; Produces:  Output\ADMS-Server-Setup.exe
;
; Expects these to exist next to this script before compiling (see BUILD.md):
;     build\runtime\   <- portable Apache + PHP (apache\, php\, ...)
;     build\app\       <- prepared Laravel payload (from prepare-payload.sh)
;     payload\         <- control scripts + config (committed to the repo)
; ============================================================================

#define AppName "ADMS Server"
#define AppVersion "1.0.0"
#define AppPublisher "ADMS"

[Setup]
AppId={{A1D5C0DE-ADMS-4F00-9E00-ATTENDANCE001}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#AppPublisher}
DefaultDirName={sd}\ADMS
DisableProgramGroupPage=yes
DefaultGroupName={#AppName}
; Service install + firewall changes need admin.
PrivilegesRequired=admin
OutputDir=Output
OutputBaseFilename=ADMS-Server-Setup
Compression=lzma2/max
SolidCompression=yes
WizardStyle=modern
ArchitecturesInstallIn64BitMode=x64compatible
; The bundled Apache's ServerRoot is path-sensitive; keep the default dir.
DisableDirPage=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
; Portable Apache + PHP runtime
Source: "build\runtime\*"; DestDir: "{app}\runtime"; Flags: recursesubdirs createallsubdirs ignoreversion
; Prepared Laravel application
Source: "build\app\*";     DestDir: "{app}\app";     Flags: recursesubdirs createallsubdirs ignoreversion
; Control scripts (root of install dir)
Source: "payload\*.bat";   DestDir: "{app}";         Flags: ignoreversion
Source: "payload\.env.production"; DestDir: "{app}\app"; Flags: ignoreversion
; Apache vhost template
Source: "payload\config\*"; DestDir: "{app}\config"; Flags: ignoreversion

[Dirs]
Name: "{app}\data"; Permissions: users-modify
Name: "{app}\logs"; Permissions: users-modify
; Laravel writable dirs
Name: "{app}\app\storage"; Permissions: users-modify
Name: "{app}\app\bootstrap\cache"; Permissions: users-modify

[Icons]
Name: "{group}\Start ADMS Server"; Filename: "{app}\Start-ADMS.bat"; WorkingDir: "{app}"; IconFilename: "{sys}\shell32.dll"; IconIndex: 13
Name: "{group}\Stop ADMS Server";  Filename: "{app}\Stop-ADMS.bat";  WorkingDir: "{app}"; IconFilename: "{sys}\shell32.dll"; IconIndex: 27
Name: "{group}\ADMS Admin (browser)"; Filename: "http://localhost:8080/monitoring"
Name: "{group}\Open ADMS folder"; Filename: "{app}"
Name: "{group}\Uninstall ADMS Server"; Filename: "{uninstallexe}"
Name: "{commondesktop}\Start ADMS Server"; Filename: "{app}\Start-ADMS.bat"; WorkingDir: "{app}"; IconFilename: "{sys}\shell32.dll"; IconIndex: 13; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut to start ADMS"; GroupDescription: "Shortcuts:"

[Run]
; One-time configuration: .env, DB, migrate, Apache service, scheduler, firewall.
Filename: "{app}\first-run-setup.bat"; WorkingDir: "{app}"; StatusMsg: "Configuring ADMS (database, web server, scheduler)..."; Flags: runhidden waituntilterminated
; Offer to open the admin UI when done.
Filename: "http://localhost:8080/monitoring"; Description: "Open the ADMS admin page"; Flags: postinstall shellexec nowait skipifsilent

[UninstallRun]
; Remove service, scheduled task, firewall rule before deleting files.
Filename: "{app}\uninstall-services.bat"; WorkingDir: "{app}"; Flags: runhidden waituntilterminated; RunOnceId: "RemoveADMSServices"

[UninstallDelete]
Type: filesandordirs; Name: "{app}\data"
Type: filesandordirs; Name: "{app}\logs"
Type: files; Name: "{app}\config\httpd-adms.active.conf"
