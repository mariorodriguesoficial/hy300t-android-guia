; Rota 403 Offline — instalador (Inno Setup 6). Compile com windows\compilar.ps1 (define Versao e Origem).
#ifndef Versao
  #define Versao "1.0.0"
#endif
#ifndef Origem
  #define Origem "..\build\stage"
#endif
#define Nome "Rota 403 Offline"
#define DadosDir "{commonappdata}\Rota403"

[Setup]
AppId={{D2A4E2F3-9034-4A3C-90F4-403000000001}
AppName={#Nome}
AppVersion={#Versao}
AppPublisher=Rota 403
DefaultDirName={autopf}\Rota403
DisableDirPage=auto
DefaultGroupName=Rota 403
DisableProgramGroupPage=yes
OutputBaseFilename=Rota403-Offline-Setup
OutputDir=..\build\saida
SetupIconFile={#Origem}\rota403.ico
UninstallDisplayIcon={app}\rota403.ico
Compression=lzma2/max
SolidCompression=yes
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
PrivilegesRequired=admin
WizardStyle=modern
ShowLanguageDialog=no
LanguageDetectionMethod=none
SetupLogging=yes
CloseApplications=no
UsePreviousAppDir=yes

[Languages]
Name: "ptbr"; MessagesFile: "compiler:Languages\BrazilianPortuguese.isl"

[Dirs]
Name: "{app}\servicos"

[Files]
; Código e runtimes. NUNCA entram aqui: config.local.php, chave_seguranca.php, banco, backups (ficam fora/são gerados).
Source: "{#Origem}\app\*"; DestDir: "{app}\app"; Flags: recursesubdirs createallsubdirs ignoreversion; Excludes: "config.local.php,chave_seguranca.php"
Source: "{#Origem}\runtime\*"; DestDir: "{app}\runtime"; Flags: recursesubdirs createallsubdirs ignoreversion; Excludes: "vc_redist.x64.exe"
Source: "{#Origem}\runtime\vcredist\vc_redist.x64.exe"; DestDir: "{tmp}"; Flags: dontcopy
Source: "{#Origem}\windows\*"; DestDir: "{app}\windows"; Flags: recursesubdirs ignoreversion
Source: "{#Origem}\database\*"; DestDir: "{app}\database"; Flags: ignoreversion
Source: "{#Origem}\THIRD-PARTY-NOTICES.md"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#Origem}\rota403.ico"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{autoprograms}\Rota 403"; Filename: "{app}\windows\abrir-app.cmd"; IconFilename: "{app}\rota403.ico"; WorkingDir: "{app}"
Name: "{autodesktop}\Rota 403"; Filename: "{app}\windows\abrir-app.cmd"; IconFilename: "{app}\rota403.ico"; Tasks: desktopicon
Name: "{commonstartup}\Rota 403"; Filename: "{app}\windows\abrir-app.cmd"; IconFilename: "{app}\rota403.ico"; Tasks: abrirnologin
Name: "{autoprograms}\Rota 403 - Backup agora"; Filename: "{app}\windows\admin-backup.cmd"; IconFilename: "{app}\rota403.ico"
Name: "{autoprograms}\Rota 403 - Restaurar backup"; Filename: "{app}\windows\admin-restaurar.cmd"; IconFilename: "{app}\rota403.ico"
Name: "{autoprograms}\Rota 403 - Celulares (Wi-Fi)"; Filename: "{app}\windows\admin-lan.cmd"; IconFilename: "{app}\rota403.ico"
Name: "{autoprograms}\Rota 403 - Diagnóstico"; Filename: "{app}\windows\admin-diagnostico.cmd"; IconFilename: "{app}\rota403.ico"
Name: "{autoprograms}\Rota 403 - Pasta de logs e backups"; Filename: "{#DadosDir}"; IconFilename: "{app}\rota403.ico"

[Tasks]
Name: "desktopicon"; Description: "Criar atalho na área de trabalho"; Flags: checkedonce
Name: "abrirnologin"; Description: "Abrir o Rota 403 ao entrar no Windows (o servidor já inicia sozinho com o PC)"; Flags: unchecked

[Registry]
Root: HKLM; Subkey: "Software\Rota403"; ValueType: string; ValueName: "InstallDir"; ValueData: "{app}"; Flags: uninsdeletekeyifempty

[Run]
Filename: "{app}\windows\abrir-app.cmd"; Description: "Abrir o Rota 403 agora"; Flags: postinstall nowait skipifsilent shellexec

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\windows\firewall-lan.ps1"" -Acao Desabilitar"; Flags: runhidden; RunOnceId: "Rota403Fw"
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -Command ""Unregister-ScheduledTask -TaskName Rota403Backup -Confirm:$false -ErrorAction SilentlyContinue"""; Flags: runhidden; RunOnceId: "Rota403Task"
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\windows\servicos.ps1"" -Acao Remover"; Flags: runhidden; RunOnceId: "Rota403Svc"

[Code]
var
  PgAdmin, PgOpcoes: TInputQueryWizardPage;
  PgLan: TInputOptionWizardPage;
  Atualizando: Boolean;

function DadosDir: String; begin Result := ExpandConstant('{commonappdata}\Rota403'); end;
function JaInstalado: Boolean;
begin
  Result := FileExists(DadosDir + '\secrets\db-root.bin') and DirExists(DadosDir + '\mariadb-data\mysql');
end;

function PowerShell(const Args: String; var Codigo: Integer): Boolean;
begin
  Result := Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'), '-NoProfile -ExecutionPolicy Bypass ' + Args, '', SW_HIDE, ewWaitUntilTerminated, Codigo);
end;

// Executa o PowerShell gravando TODA a saída (inclusive erros) em LogArq, para diagnóstico.
function PowerShellComLog(const Args, LogArq: String; var Codigo: Integer): Boolean;
begin
  ForceDirectories(ExtractFileDir(LogArq));
  Result := Exec(ExpandConstant('{cmd}'),
    '/C ""' + ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe') + '" -NoProfile -ExecutionPolicy Bypass ' + Args + ' > "' + LogArq + '" 2>&1"',
    '', SW_HIDE, ewWaitUntilTerminated, Codigo);
end;

function UltimasLinhas(const Arq: String; Max: Integer): String;
var Txt: AnsiString; N: Integer;
begin
  Result := '(sem saída registrada)';
  if LoadStringFromFile(Arq, Txt) then begin
    N := Length(Txt);
    if N > Max then Result := '...' + Copy(Txt, N - Max + 1, Max) else Result := Txt;
  end;
end;

function PortaOcupada(Porta: Integer): Boolean;
var Codigo: Integer;
begin
  // retorna True se algo já escuta na porta (código 7 = ocupada)
  if PowerShell('-Command "if (Get-NetTCPConnection -State Listen -LocalPort ' + IntToStr(Porta) + ' -ErrorAction SilentlyContinue) { exit 7 } else { exit 0 }"', Codigo) then
    Result := (Codigo = 7)
  else
    Result := False;
end;

function SenhaForte(const S: String): Boolean;
var I: Integer; TemLetra, TemNumero: Boolean;
begin
  TemLetra := False; TemNumero := False;
  for I := 1 to Length(S) do begin
    if ((S[I] >= 'A') and (S[I] <= 'Z')) or ((S[I] >= 'a') and (S[I] <= 'z')) then TemLetra := True;
    if (S[I] >= '0') and (S[I] <= '9') then TemNumero := True;
  end;
  Result := (Length(S) >= 8) and TemLetra and TemNumero;
end;

function LoginValido(const S: String): Boolean;
var I: Integer; C: Char;
begin
  Result := (Length(S) >= 3) and (Length(S) <= 50);
  for I := 1 to Length(S) do begin
    C := S[I];
    if not (((C >= 'A') and (C <= 'Z')) or ((C >= 'a') and (C <= 'z')) or ((C >= '0') and (C <= '9')) or (C = '.') or (C = '_') or (C = '-')) then Result := False;
  end;
end;

function JsonEscapar(const S: String): String;
var R: String;
begin
  R := S;
  StringChangeEx(R, '\', '\\', True);
  StringChangeEx(R, '"', '\"', True);
  Result := R;
end;

procedure InitializeWizard;
begin
  Atualizando := JaInstalado;
  PgAdmin := CreateInputQueryPage(wpSelectDir, 'Administrador do sistema',
    'Crie o usuário administrador', 'Não existe senha padrão. Esta senha dá acesso total ao sistema: guarde-a em local seguro.');
  PgAdmin.Add('Nome completo:', False);
  PgAdmin.Add('Login (letras, números, . _ -):', False);
  PgAdmin.Add('Senha (mínimo 8, com letras e números):', True);
  PgAdmin.Add('Repita a senha:', True);
  PgOpcoes := CreateInputQueryPage(PgAdmin.ID, 'Portas locais', 'Portas usadas somente neste computador',
    'Mantenha os valores padrão, a menos que outro programa já use estas portas.');
  PgOpcoes.Add('Porta do sistema (HTTP local):', False);
  PgOpcoes.Add('Porta do banco de dados (somente local):', False);
  PgOpcoes.Values[0] := '8080'; PgOpcoes.Values[1] := '3307';
  PgLan := CreateInputOptionPage(PgOpcoes.ID, 'Celulares dos garçons', 'Acesso pela rede Wi-Fi do restaurante',
    'Pode ser ativado depois pelo atalho "Celulares (Wi-Fi)". Se ativar agora, o Windows Firewall abrirá SOMENTE a porta HTTPS, só para redes Privadas.',
    False, False);
  PgLan.Add('Permitir que celulares acessem o sistema pela rede local (HTTPS)');
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := Atualizando and ((PageID = PgAdmin.ID) or (PageID = PgOpcoes.ID) or (PageID = PgLan.ID));
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var Http, Db: Integer;
begin
  Result := True;
  if CurPageID = PgAdmin.ID then begin
    if Trim(PgAdmin.Values[0]) = '' then begin MsgBox('Informe o nome.', mbError, MB_OK); Result := False; Exit; end;
    if not LoginValido(Trim(PgAdmin.Values[1])) then begin MsgBox('Login inválido: use 3 a 50 caracteres (letras, números, ponto, hífen ou sublinhado).', mbError, MB_OK); Result := False; Exit; end;
    if not SenhaForte(PgAdmin.Values[2]) then begin MsgBox('A senha precisa ter pelo menos 8 caracteres, com letras e números.', mbError, MB_OK); Result := False; Exit; end;
    if PgAdmin.Values[2] <> PgAdmin.Values[3] then begin MsgBox('As senhas não conferem.', mbError, MB_OK); Result := False; Exit; end;
  end;
  if CurPageID = PgOpcoes.ID then begin
    Http := StrToIntDef(PgOpcoes.Values[0], 0); Db := StrToIntDef(PgOpcoes.Values[1], 0);
    if (Http < 1024) or (Http > 65535) or (Db < 1024) or (Db > 65535) or (Http = Db) then begin
      MsgBox('Use portas diferentes entre 1024 e 65535.', mbError, MB_OK); Result := False; Exit; end;
    if PortaOcupada(Http) then begin MsgBox('A porta ' + IntToStr(Http) + ' já está em uso por outro programa. Escolha outra.', mbError, MB_OK); Result := False; Exit; end;
    if PortaOcupada(Db) then begin MsgBox('A porta ' + IntToStr(Db) + ' já está em uso por outro programa. Escolha outra.', mbError, MB_OK); Result := False; Exit; end;
  end;
end;

function PortaSalva(const Nome: String; Padrao: Integer): Integer;
var V: String;
begin
  if RegQueryStringValue(HKLM, 'Software\Rota403', Nome, V) then Result := StrToIntDef(V, Padrao) else Result := Padrao;
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
var Codigo: Integer;
begin
  Result := '';
  // VC++ Redistributable (PHP e MariaDB precisam)
  if not RegValueExists(HKLM, 'SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64', 'Installed') then begin
    WizardForm.StatusLabel.Caption := 'Instalando Microsoft Visual C++ Runtime...';
    ExtractTemporaryFile('vc_redist.x64.exe');
    Exec(ExpandConstant('{tmp}\vc_redist.x64.exe'), '/install /quiet /norestart', '', SW_HIDE, ewWaitUntilTerminated, Codigo);
  end;
  // Atualização: backup verificado ANTES de trocar qualquer arquivo; se falhar, aborta sem alterar nada
  if Atualizando and FileExists(ExpandConstant('{app}\windows\atualizar.ps1')) then begin
    WizardForm.StatusLabel.Caption := 'Fazendo backup de segurança antes de atualizar...';
    if not PowerShell('-File "' + ExpandConstant('{app}\windows\atualizar.ps1') + '" -Fase Antes', Codigo) or (Codigo <> 0) then
      Result := 'O backup de segurança falhou, então a atualização foi cancelada e NADA foi alterado. Veja ' + DadosDir + '\logs\atualizacao.log';
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var Codigo: Integer; Http, Db: Integer; ArqAdmin, Json, Args: String;
begin
  if CurStep = ssPostInstall then begin
    if Atualizando then begin Http := PortaSalva('PortaHttp', 8080); Db := PortaSalva('PortaDb', 3307); end
    else begin Http := StrToIntDef(PgOpcoes.Values[0], 8080); Db := StrToIntDef(PgOpcoes.Values[1], 3307); end;
    Args := '-File "' + ExpandConstant('{app}\windows\instalar.ps1') + '" -Raiz "' + ExpandConstant('{app}') + '" -Dados "' + DadosDir + '" -PortaHttp ' + IntToStr(Http) + ' -PortaDb ' + IntToStr(Db);
    if (not Atualizando) then begin
      ArqAdmin := ExpandConstant('{tmp}\rota403-admin-') + GetDateTimeString('yyyymmddhhnnss', #0, #0) + '.json';
      Json := '{"nome":"' + JsonEscapar(Trim(PgAdmin.Values[0])) + '","login":"' + JsonEscapar(Trim(PgAdmin.Values[1])) + '","senha":"' + JsonEscapar(PgAdmin.Values[2]) + '"}';
      SaveStringToFile(ArqAdmin, Json, False);
      Exec(ExpandConstant('{sys}\icacls.exe'), '"' + ArqAdmin + '" /inheritance:r /grant:r *S-1-5-32-544:F *S-1-5-18:F', '', SW_HIDE, ewWaitUntilTerminated, Codigo);
      Args := Args + ' -AdminJson "' + ArqAdmin + '"';
      if PgLan.Values[0] then Args := Args + ' -Lan';
    end;
    WizardForm.StatusLabel.Caption := 'Configurando banco de dados e serviços (pode levar alguns minutos)...';
    if (not PowerShellComLog(Args, DadosDir + '\logs\instalador-powershell.log', Codigo)) or (Codigo <> 0) then begin
      DeleteFile(ArqAdmin);
      // NÃO usa Abort: Abort desfaz (rollback) os arquivos e some com o que precisa ser diagnosticado.
      MsgBox('A configuração falhou (código ' + IntToStr(Codigo) + '). Os arquivos foram mantidos para diagnóstico; nenhum dado foi apagado.' + #13#10#13#10 +
             'Últimas linhas do erro:' + #13#10 + UltimasLinhas(DadosDir + '\logs\instalador-powershell.log', 900) + #13#10#13#10 +
             'Logs completos em: ' + DadosDir + '\logs' + #13#10 + 'Ao concluir, o sistema NÃO estará funcionando até a correção.', mbCriticalError, MB_OK);
    end else begin
      DeleteFile(ArqAdmin);
      RegWriteStringValue(HKLM, 'Software\Rota403', 'PortaHttp', IntToStr(Http));
      RegWriteStringValue(HKLM, 'Software\Rota403', 'PortaDb', IntToStr(Db));
      SaveStringToFile(ExpandConstant('{app}\windows\porta-http.txt'), IntToStr(Http), False);
      if Atualizando then begin
        if not PowerShell('-File "' + ExpandConstant('{app}\windows\atualizar.ps1') + '" -Fase Depois -PortaHttp ' + IntToStr(Http), Codigo) or (Codigo <> 0) then
          MsgBox('A atualização terminou mas a checagem de saúde falhou. Seus dados estão intactos e há um backup "pre-atualizacao" em ' + DadosDir + '\backups. Veja logs\atualizacao.log.', mbError, MB_OK);
      end;
    end;
  end;
end;

function InitializeUninstall: Boolean;
begin
  Result := True;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var Codigo: Integer;
begin
  if CurUninstallStep = usPostUninstall then begin
    if DirExists(DadosDir) then begin
      if MsgBox('Seus DADOS (banco, vendas, backups e configurações) foram PRESERVADOS em:' + #13#10 + DadosDir + #13#10#13#10 +
                'Deseja APAGÁ-LOS também? Esta ação é IRREVERSÍVEL. Responda NÃO para manter (recomendado).', mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES then begin
        if MsgBox('Confirma apagar TODAS as vendas, comandas, usuários e backups deste computador?', mbError, MB_YESNO or MB_DEFBUTTON2) = IDYES then
          Exec(ExpandConstant('{sys}\cmd.exe'), '/c rmdir /s /q "' + DadosDir + '"', '', SW_HIDE, ewWaitUntilTerminated, Codigo);
      end;
    end;
  end;
end;
