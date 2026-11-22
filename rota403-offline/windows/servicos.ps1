<#
 Instala/remove/inicia/para os serviços do Rota 403 usando WinSW (MariaDB usa o serviço nativo criado pelo mariadb-install-db):
   Rota403DB   MariaDB (127.0.0.1)
   Rota403Web  supervisor: pool de php-cgi + Caddy (HTTP 127.0.0.1 e, opcional, HTTPS na LAN)
   Rota403Sync worker de sincronização (instalado, mas só inicia quando SYNC_ENABLED=true em config.local.php)
#>
param(
    [ValidateSet('Instalar', 'Remover', 'Iniciar', 'Parar', 'Status')][string]$Acao = 'Status',
    [string]$Raiz = (Split-Path -Parent $PSScriptRoot), [string]$Dados = (Join-Path $env:ProgramData 'Rota403'),
    [int]$PortaHttp = 8080, [int]$PortaHttps = 8443, [int]$PortaDb = 3307, [switch]$Lan, [string]$IpsLan = '', [switch]$SoBanco
)
. "$PSScriptRoot\lib.ps1"
$C = Get-Caminhos $Raiz $Dados
$log = Join-Path $C.Logs 'servicos.log'
function L([string]$m) { Escrever-Log $m $log }

function Escrever-Xml([string]$Id, [string]$Nome, [string]$Desc, [string]$Exe, [string]$Argumentos, [string]$Dependencia, [string]$WorkDir) {
    Garantir-Pasta $C.Servicos
    Copy-Item -LiteralPath (Join-Path $C.Winsw 'WinSW-x64.exe') -Destination (Join-Path $C.Servicos "$Id.exe") -Force
    $tagDep = ''; if ($Dependencia) { $tagDep = "<depend>$Dependencia</depend>" }
    $xml = @"
<service>
  <id>$Id</id>
  <name>$Nome</name>
  <description>$Desc</description>
  <executable>$Exe</executable>
  <arguments>$([System.Security.SecurityElement]::Escape($Argumentos))</arguments>
  <workingdirectory>$WorkDir</workingdirectory>
  $tagDep
  <startmode>Automatic</startmode>
  <logpath>$($C.Logs)</logpath>
  <log mode="roll-by-size"><sizeThreshold>5120</sizeThreshold><keepFiles>5</keepFiles></log>
  <onfailure action="restart" delay="5 sec"/>
  <onfailure action="restart" delay="15 sec"/>
  <onfailure action="restart" delay="60 sec"/>
  <resetfailure>1 day</resetfailure>
  <stoptimeout>20 sec</stoptimeout>
</service>
"@
    Set-Content -LiteralPath (Join-Path $C.Servicos "$Id.xml") -Value $xml -Encoding UTF8
}
function Instalar-WinSW([string]$Id) {
    $exe = Join-Path $C.Servicos "$Id.exe"
    if (Get-Service -Name $Id -ErrorAction SilentlyContinue) { $null = & $exe stop 2>&1; $null = & $exe uninstall 2>&1 }
    $null = Executar $exe @('install') "instalação do serviço $Id"
    # conta de menor privilégio
    $null = & sc.exe config $Id obj= 'NT AUTHORITY\LocalService' 2>&1
    $null = & sc.exe failure $Id reset= 86400 actions= restart/5000/restart/15000/restart/60000 2>&1
}
function Sync-Habilitado {
    try { $cfg = Ler-ConfigApp $C; return [bool]($cfg.SYNC_ENABLED) } catch { return $false }
}

switch ($Acao) {
    'Instalar' {
        Garantir-Pasta $C.Logs
        # MariaDB: serviço nativo (criado pelo mariadb-install-db). Roda como LocalService.
        if (Get-Service -Name 'Rota403DB' -ErrorAction SilentlyContinue) {
            $null = & sc.exe config Rota403DB obj= 'NT AUTHORITY\LocalService' start= auto 2>&1
            $null = & sc.exe failure Rota403DB reset= 86400 actions= restart/5000/restart/15000/restart/60000 2>&1
            Proteger-Caminho $C.DbData @($script:ContaServico)
            if ((Get-Service Rota403DB).Status -ne 'Running') { Start-Service Rota403DB }
        }
        if ($SoBanco) { L 'Serviço do banco configurado.'; exit 0 }
        # Caddyfile (HTTP loopback + LAN opcional)
        $php = Join-Path $C.Php 'php.exe'
        $argsCad = @('-c', (Join-Path $C.Php 'php.ini'), (Join-Path $C.App 'bin\gerar-caddyfile.php'), "--raiz=$($C.App)", "--dados=$($C.Dados)", "--porta-http=$PortaHttp", "--porta-https=$PortaHttps")
        if ($Lan) { $argsCad += '--lan=1'; if ($IpsLan) { $argsCad += "--ips=$IpsLan" } }
        $null = Executar $php $argsCad 'geração do Caddyfile'
        # Web
        $ps1 = Join-Path $PSScriptRoot 'servidor-web.ps1'
        Escrever-Xml 'Rota403Web' 'Rota 403 - Servidor web' 'Pool de PHP + Caddy (HTTP local; HTTPS na LAN quando habilitado).' 'powershell.exe' `
            ('-NoProfile -ExecutionPolicy Bypass -File "{0}" -Raiz "{1}" -Dados "{2}"' -f $ps1, $C.Raiz, $C.Dados) 'Rota403DB' $C.Raiz
        Instalar-WinSW 'Rota403Web'
        # Sync (worker)
        Escrever-Xml 'Rota403Sync' 'Rota 403 - Sincronização' 'Envia/recebe alterações com a versão online. Não bloqueia o PDV.' $php `
            ('-c "{0}" "{1}"' -f (Join-Path $C.Php 'php.ini'), (Join-Path $C.App 'bin\sync-worker.php')) 'Rota403DB' $C.App
        Instalar-WinSW 'Rota403Sync'
        Start-Service Rota403Web
        if (Sync-Habilitado) { Start-Service Rota403Sync } else { L 'Sync desativado (SYNC_ENABLED=false): serviço instalado, não iniciado.' }
        L 'Serviços instalados.'
    }
    'Remover' {
        foreach ($s in 'Rota403Sync', 'Rota403Web') {
            $exe = Join-Path $C.Servicos "$s.exe"
            if ((Get-Service -Name $s -ErrorAction SilentlyContinue) -and (Test-Path $exe)) { $null = & $exe stop 2>&1; $null = & $exe uninstall 2>&1 }
        }
        if (Get-Service -Name 'Rota403DB' -ErrorAction SilentlyContinue) {
            Stop-Service Rota403DB -ErrorAction SilentlyContinue
            $null = & (Join-Path $C.Maria 'bin\mariadbd.exe') --remove Rota403DB 2>&1
        }
        L ('Serviços removidos (dados preservados em {0}).' -f $C.Dados)
    }
    'Iniciar' { foreach ($s in 'Rota403DB', 'Rota403Web') { Start-Service $s -ErrorAction SilentlyContinue }; if (Sync-Habilitado) { Start-Service Rota403Sync -ErrorAction SilentlyContinue } }
    'Parar'   { foreach ($s in 'Rota403Sync', 'Rota403Web', 'Rota403DB') { Stop-Service $s -Force -ErrorAction SilentlyContinue } }
    'Status'  { Get-Service -Name 'Rota403DB', 'Rota403Web', 'Rota403Sync' -ErrorAction SilentlyContinue | Format-Table Name, Status, StartType -AutoSize }
}
exit 0
