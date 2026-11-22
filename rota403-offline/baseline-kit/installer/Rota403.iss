#define AppName "Rota 403 Offline"
#define AppVersion "1.0.0"
#define AppPublisher "Rota 403"
#define AppExeName "iniciar-rota403.bat"

[Setup]
AppId={{D2A4E2F3-9034-4A3C-90F4-403000000001}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#AppPublisher}
DefaultDirName={autopf}\Rota403Offline
DefaultGroupName=Rota 403 Offline
OutputDir=output
OutputBaseFilename=Rota403-Offline-Setup
Compression=lzma2
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64
PrivilegesRequired=admin
DisableProgramGroupPage=yes

[Dirs]
Name: "{app}\app\storage"; Permissions: users-modify
Name: "{app}\runtime"

[Files]
Source: "..\app\*"; DestDir: "{app}\app"; Flags: recursesubdirs ignoreversion
Source: "..\database\rota403.sql"; DestDir: "{app}\database"; Flags: ignoreversion
Source: "..\windows\*"; DestDir: "{app}\windows"; Flags: recursesubdirs ignoreversion
; Adicione runtimes oficiais portáteis em runtime\php e runtime\mariadb antes de compilar:
Source: "..\runtime\*"; DestDir: "{app}\runtime"; Flags: recursesubdirs ignoreversion

[Icons]
Name: "{autodesktop}\Rota 403 Offline"; Filename: "{app}\windows\iniciar-rota403.bat"; WorkingDir: "{app}"
Name: "{group}\Rota 403 Offline"; Filename: "{app}\windows\iniciar-rota403.bat"; WorkingDir: "{app}"
Name: "{group}\Parar Rota 403"; Filename: "{app}\windows\parar-rota403.bat"; WorkingDir: "{app}"

[Run]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\windows\verificar-ambiente.ps1"""; Flags: postinstall waituntilterminated
