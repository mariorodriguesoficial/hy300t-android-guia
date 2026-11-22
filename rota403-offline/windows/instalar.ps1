<#
 Instalação/reparo do Rota 403 (executado pelo instalador, como Administrador).
 Idempotente: pode rodar de novo (reparo/atualização) sem apagar dados.
 Admin inicial: o instalador grava um JSON temporário protegido; este script usa e APAGA.
#>
param(
    [string]$Raiz = (Split-Path -Parent $PSScriptRoot),
    [string]$Dados = (Join-Path $env:ProgramData 'Rota403'),
    [int]$PortaHttp = 8080, [int]$PortaDb = 3307, [int]$PortaHttps = 8443,
    [string]$AdminJson = '', [switch]$Lan, [string]$IpsLan = ''
)
. "$PSScriptRoot\lib.ps1"
$C = Get-Caminhos $Raiz $Dados
foreach ($d in @($C.Dados, $C.Logs, $C.Backups, $C.Segredos, $C.Sessoes, $C.Tmp, $C.CaddyData)) { Garantir-Pasta $d }
$log = Join-Path $C.Logs 'instalacao.log'
function L([string]$m) { Escrever-Log $m $log }
try {
    L '=== Instalação Rota 403 ==='
    $php = Join-Path $C.Php 'php.exe'
    foreach ($f in @($php, (Join-Path $C.Php 'php-cgi.exe'), (Join-Path $C.Maria 'bin\mariadbd.exe'), (Join-Path $C.Caddy 'caddy.exe'), (Join-Path $C.Winsw 'WinSW-x64.exe'))) {
        if (-not (Test-Path -LiteralPath $f)) { throw "Arquivo do pacote ausente: $f" }
    }
    # ---- permissões: dados só para SYSTEM/Admins/serviço
    Proteger-Caminho $C.Dados @($script:ContaServico)
    Proteger-Caminho $C.Segredos @()          # segredos: nem o serviço lê (só SYSTEM/Admins via tarefa de backup)
    Proteger-Caminho $C.Backups @()
    foreach ($w in @('storage', 'uploads', 'config')) { $p = Join-Path $C.App $w; Garantir-Pasta $p; $null = & icacls.exe $p /grant ("{0}:(OI)(CI)M" -f $script:ContaServico) 2>&1 }
    # ---- php.ini
    $ini = (Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'php.ini.template')).Replace('@DATADIR@', $C.Dados).Replace('@PHPDIR@', $C.Php)
    Set-Content -LiteralPath (Join-Path $C.Php 'php.ini') -Value $ini -Encoding ASCII
    $null = Executar $php @('-c', (Join-Path $C.Php 'php.ini'), '-m') 'verificação de extensões do PHP'
    foreach ($ext in 'pdo_mysql', 'mbstring', 'openssl', 'curl', 'gd', 'iconv') {
        if (-not ((& $php -c (Join-Path $C.Php 'php.ini') -m) -contains $ext)) { throw "Extensão PHP ausente: $ext" }
    }
    L 'PHP configurado.'
    # ---- portas
    foreach ($p in @($PortaHttp, $PortaDb)) {
        $svcAtivo = (Get-Service -Name 'Rota403Web', 'Rota403DB' -ErrorAction SilentlyContinue | Where-Object Status -eq 'Running')
        if (-not (Porta-Livre $p) -and -not $svcAtivo) { throw "A porta $p já está em uso por outro programa. Escolha outra porta no instalador." }
    }
    # ---- MariaDB
    $inicializado = Test-Path (Join-Path $C.DbData 'mysql')
    $segRoot = Join-Path $C.Segredos 'db-root.bin'
    if (-not $inicializado) {
        L 'Inicializando MariaDB (primeira instalação)...'
        $rootPw = Nova-Senha
        Garantir-Pasta $C.DbData
        $null = Executar (Join-Path $C.Maria 'bin\mariadb-install-db.exe') @("--datadir=$($C.DbData)", '--service=Rota403DB', "--port=$PortaDb", "--password=$rootPw", '--silent') 'mariadb-install-db'
        Salvar-Segredo $segRoot $rootPw
        # my.ini gerado pelo instalador: endurece e fixa loopback
        $ini = Join-Path $C.DbData 'my.ini'
        $extra = @(
            '', '[mysqld]', 'bind-address=127.0.0.1', 'skip-name-resolve', 'skip-networking=0',
            'character-set-server=utf8mb4', 'collation-server=utf8mb4_unicode_ci', 'innodb_flush_log_at_trx_commit=1', 'sync_binlog=0',
            "log-error=$($C.Logs.Replace('\','/'))/mariadb-error.log", 'local-infile=0', 'max_connections=60'
        )
        Add-Content -LiteralPath $ini -Value $extra -Encoding ASCII
    } else { L 'MariaDB já inicializado: dados preservados.' }
    if (-not (Test-Path $segRoot)) { throw 'Segredo do root do MariaDB ausente; restaure de backup ou reinstale com dados vazios.' }
    $rootPw = Ler-Segredo $segRoot
    # ---- serviços (DB primeiro) 
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'servicos.ps1') -Acao Instalar -Raiz $C.Raiz -Dados $C.Dados -PortaHttp $PortaHttp -PortaHttps $PortaHttps -PortaDb $PortaDb -SoBanco
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao instalar o serviço do banco.' }
    if (-not (Esperar-Porta $PortaDb 90)) { throw "MariaDB não respondeu na porta $PortaDb. Veja $($C.Logs)\mariadb-error.log" }
    $cli = Cliente-Maria $C
    $env:MYSQL_PWD = $rootPw
    try {
        $existe = (& $cli -h 127.0.0.1 -P $PortaDb -u root -N -B -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='rota403'")
        $cfgExiste = Test-Path -LiteralPath $C.Config
        if ($existe -eq '0') {
            L 'Criando banco e usuário da aplicação...'
            $appPw = Nova-Senha
            $sql = "CREATE DATABASE rota403 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " +
                   "CREATE USER IF NOT EXISTS 'rota403'@'127.0.0.1' IDENTIFIED BY '$appPw'; " +
                   "GRANT ALL PRIVILEGES ON rota403.* TO 'rota403'@'127.0.0.1'; FLUSH PRIVILEGES;"
            $null = Executar $cli @('-h', '127.0.0.1', '-P', "$PortaDb", '-u', 'root', '-e', $sql) 'criação do banco'
            Importar-Sql $cli @('-h', '127.0.0.1', '-P', "$PortaDb", '-u', 'root', '--default-character-set=utf8mb4', 'rota403') (Join-Path $C.Raiz 'database\schema.sql')
            $cfg = @"
<?php
// Gerado pelo instalador do Rota 403. NÃO versionar, NÃO compartilhar.
return [
    'APP_ENV'  => 'local',
    'DB_HOST'  => '127.0.0.1',
    'DB_PORT'  => '$PortaDb',
    'DB_USER'  => 'rota403',
    'DB_PASS'  => '$appPw',
    'DB_NAME'  => 'rota403',
    'BASE_URL' => '',
    'DATA_DIR' => '$($C.Logs.Replace('\','/'))',
    'COOKIE_SECURE' => false,
];
"@
            # PHP exige arquivo SEM BOM (um BOM vira saída antes dos cabeçalhos e quebra sessão/cookies)
            [System.IO.File]::WriteAllText($C.Config, $cfg, (New-Object System.Text.UTF8Encoding($false)))
            $null = & icacls.exe $C.Config /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F' ("{0}:R" -f $script:ContaServico) 2>&1
        } elseif (-not $cfgExiste) {
            throw 'O banco rota403 existe mas config.local.php sumiu. Restaure o arquivo do backup (pasta backups) ou contate o suporte.'
        } else { L 'Banco existente: preservado.' }
    } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }
    # ---- migrações (sempre)
    $null = Executar $php @('-c', (Join-Path $C.Php 'php.ini'), (Join-Path $C.App 'bin\migrate.php')) 'migrações'
    L 'Migrações aplicadas.'
    # ---- primeiro administrador
    if ($AdminJson -and (Test-Path -LiteralPath $AdminJson)) {
        try {
            $r = & $php -c (Join-Path $C.Php 'php.ini') (Join-Path $C.App 'bin\criar-admin.php') "--arquivo=$AdminJson" 2>&1
            if ($LASTEXITCODE -eq 0) { L 'Administrador criado.' }
            elseif ($LASTEXITCODE -eq 3) { L 'Já existem usuários: administrador não alterado.' }
            else { throw ("Falha ao criar administrador: " + ($r | Out-String)) }
            # faixa de numeração de comandas própria deste computador (evita colisão com o online ao sincronizar)
            $null = & $php -c (Join-Path $C.Php 'php.ini') (Join-Path $C.App 'bin\sync-admin.php') id-inicial 500000 2>&1
        } finally { Remove-Item -LiteralPath $AdminJson -Force -ErrorAction SilentlyContinue }
    }
    # ---- web (Caddy + php-cgi) e sync
    $argsSvc = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $PSScriptRoot 'servicos.ps1'), '-Acao', 'Instalar', '-Raiz', $C.Raiz, '-Dados', $C.Dados,
                 '-PortaHttp', "$PortaHttp", '-PortaHttps', "$PortaHttps", '-PortaDb', "$PortaDb")
    if ($Lan) { $argsSvc += '-Lan' }
    if ($IpsLan) { $argsSvc += @('-IpsLan', $IpsLan) }
    & powershell.exe @argsSvc
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao instalar os serviços web.' }
    if (-not (Esperar-Porta $PortaHttp 60)) {
        L '--- DIAGNÓSTICO do servidor web ---'
        try { Get-Service Rota403DB, Rota403Web -ErrorAction Stop | ForEach-Object { L ('serviço {0}: {1}' -f $_.Name, $_.Status) } } catch { L ('serviços: ' + $_.Exception.Message) }
        $caddyExe = Join-Path $C.Caddy 'caddy.exe'
        L ('caddy validate: ' + ((& $caddyExe validate --config (Join-Path $C.Dados 'Caddyfile') --adapter caddyfile 2>&1 | Out-String).Trim()))
        foreach ($nome in 'web.log', 'Rota403Web.err.log', 'Rota403Web.out.log', 'Rota403Web.wrapper.log') {
            $arq = Join-Path $C.Logs $nome
            if (Test-Path -LiteralPath $arq) { L ("--- {0} (final) ---" -f $nome); Get-Content -LiteralPath $arq -Tail 15 | ForEach-Object { L ('   ' + $_) } }
        }
        throw "Servidor web não respondeu na porta $PortaHttp. Veja $($C.Logs)\instalacao.log"
    }
    # ---- backup diário
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'backup.ps1') -Agendar -Raiz $C.Raiz -Dados $C.Dados
    L 'Tarefa de backup diário agendada (03:30).'
    L '=== Instalação concluída ==='
    exit 0
} catch {
    L ('ERRO: ' + $_.Exception.Message)
    exit 1
}
