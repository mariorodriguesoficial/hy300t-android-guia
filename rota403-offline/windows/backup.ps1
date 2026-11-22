<#
 Backup do Rota 403 (banco + uploads + chave de criptografia) em um .zip verificável.
 Uso: backup.ps1 [-Motivo diario|manual|pre-atualizacao|pre-restauracao] [-Destino PASTA_EXTRA] [-Retencao 30] [-SemVerificar] [-Agendar]
 O zip contém a chave que decifra nomes de usuários: trate-o como dado sensível (a pasta backups só é acessível a SYSTEM/Admins).
#>
param(
    [string]$Motivo = 'manual', [string]$Destino = '', [int]$Retencao = 30, [switch]$SemVerificar, [switch]$Agendar,
    [string]$Raiz = (Split-Path -Parent $PSScriptRoot), [string]$Dados = (Join-Path $env:ProgramData 'Rota403')
)
. "$PSScriptRoot\lib.ps1"
Add-Type -AssemblyName System.IO.Compression.FileSystem
$C = Get-Caminhos $Raiz $Dados
$log = Join-Path $C.Logs 'backup.log'
function L([string]$m) { Escrever-Log $m $log }

if ($Agendar) {
    $acao = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -ExecutionPolicy Bypass -File "{0}" -Motivo diario -Raiz "{1}" -Dados "{2}"' -f $PSCommandPath, $C.Raiz, $C.Dados)
    $gatilho = New-ScheduledTaskTrigger -Daily -At '03:30'
    $cfgTask = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1)
    Register-ScheduledTask -TaskName 'Rota403Backup' -Action $acao -Trigger $gatilho -Settings $cfgTask -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
    exit 0
}

$stage = $null
try {
    Garantir-Pasta $C.Backups; Garantir-Pasta $C.Tmp
    $cfg = Ler-ConfigApp $C
    $dump = Join-Path $C.Maria 'bin\mariadb-dump.exe'; if (-not (Test-Path $dump)) { $dump = Join-Path $C.Maria 'bin\mysqldump.exe' }
    $ts = Get-Date -Format 'yyyyMMdd-HHmmss'
    $stage = Join-Path $C.Tmp ("bkp-" + $ts); Garantir-Pasta $stage
    # credenciais por variável de ambiente do processo (não aparecem na linha de comando nem ficam em arquivo)
    $sqlFile = Join-Path $stage 'dump.sql'
    $env:MYSQL_PWD = [string]$cfg.DB_PASS
    try {
        $null = Executar $dump @('-h', [string]$cfg.DB_HOST, '-P', [string]$cfg.DB_PORT, '-u', [string]$cfg.DB_USER, '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob', '--default-character-set=utf8mb4', "--result-file=$sqlFile", [string]$cfg.DB_NAME) 'mariadb-dump'
    } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }
    if ((Get-Item $sqlFile).Length -lt 2000) { throw 'Dump suspeito (muito pequeno).' }
    # uploads e chave
    $up = Join-Path $C.App 'uploads'; if (Test-Path $up) { Copy-Item -LiteralPath $up -Destination (Join-Path $stage 'uploads') -Recurse -Force }
    $chave = Join-Path $C.App 'config\chave_seguranca.php'; if (Test-Path $chave) { Copy-Item -LiteralPath $chave -Destination (Join-Path $stage 'chave_seguranca.php') -Force }
    # contagens do banco vivo ANTES do dump (referência) e versão do esquema
    $cli = Cliente-Maria $C; $env:MYSQL_PWD = [string]$cfg.DB_PASS
    $antes = @{}
    try {
        $tabelas = @(& $cli -h $cfg.DB_HOST -P $cfg.DB_PORT -u $cfg.DB_USER -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$($cfg.DB_NAME)' AND table_type='BASE TABLE'" $cfg.DB_NAME)
        foreach ($t in $tabelas) { $antes[$t] = [int64](& $cli -h $cfg.DB_HOST -P $cfg.DB_PORT -u $cfg.DB_USER -N -B -e "SELECT COUNT(*) FROM ``$t``" $cfg.DB_NAME) }
        $versao = (& $cli -h $cfg.DB_HOST -P $cfg.DB_PORT -u $cfg.DB_USER -N -B -e "SELECT COALESCE(MAX(versao),0) FROM schema_migrations" $cfg.DB_NAME)
    } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }
    # (o dump já foi gerado acima com --single-transaction; vendas feitas entre a contagem e o dump só aumentam as linhas do dump)
    $contagens = $antes; $verificado = $false
    # ---- verificação: restaura o dump num banco temporário, conta o que REALMENTE está no dump e compara com a referência
    if (-not $SemVerificar) {
        $segRoot = Join-Path $C.Segredos 'db-root.bin'
        $tmpDb = 'rota403_verify_' + (Nova-Senha 4)
        $env:MYSQL_PWD = Ler-Segredo $segRoot
        try {
            $null = Executar $cli @('-h', $cfg.DB_HOST, '-P', "$($cfg.DB_PORT)", '-u', 'root', '-e', "CREATE DATABASE ``$tmpDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") 'criação do banco de verificação'
            Importar-Sql $cli @('-h', $cfg.DB_HOST, '-P', "$($cfg.DB_PORT)", '-u', 'root', '--default-character-set=utf8mb4', $tmpDb) $sqlFile
            $reais = @{}
            foreach ($t in $antes.Keys) {
                $existe = (& $cli -h $cfg.DB_HOST -P $cfg.DB_PORT -u root -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$tmpDb' AND table_name='$t'")
                if ($existe -ne '1') { throw "Tabela ausente no dump: $t" }
                $reais[$t] = [int64](& $cli -h $cfg.DB_HOST -P $cfg.DB_PORT -u root -N -B -e "SELECT COUNT(*) FROM ``$t``" $tmpDb)
                if ($reais[$t] -lt $antes[$t]) { L ("AVISO: $t tem {0} linhas no dump e tinha {1} antes (exclusões no intervalo?)" -f $reais[$t], $antes[$t]) }
            }
            $contagens = $reais; $verificado = $true
            L 'Verificação OK: dump restaurado em banco temporário, todas as tabelas presentes.'
        } catch {
            $falho = Join-Path $C.Backups ("rota403-{0}-{1}.FALHOU.txt" -f $ts, ($Motivo -replace '[^a-z0-9-]', ''))
            Set-Content -LiteralPath $falho -Value ('Backup NÃO verificado: ' + $_.Exception.Message) -Encoding UTF8
            throw
        } finally {
            & $cli -h $cfg.DB_HOST -P $cfg.DB_PORT -u root -e "DROP DATABASE IF EXISTS ``$tmpDb``" 2>&1 | Out-Null
            Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
        }
    }
    $manifesto = [ordered]@{
        app = 'Rota403'; criado_utc = (Get-Date).ToUniversalTime().ToString('o'); motivo = $Motivo; esquema = [int]$versao; verificado = $verificado
        sha256_dump = (Get-FileHash -Algorithm SHA256 -LiteralPath $sqlFile).Hash; tabelas = $contagens; maquina = $env:COMPUTERNAME
    }
    $manifesto | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $stage 'manifest.json') -Encoding UTF8
    $zip = Join-Path $C.Backups ("rota403-{0}-{1}.zip" -f $ts, ($Motivo -replace '[^a-z0-9-]', ''))
    [System.IO.Compression.ZipFile]::CreateFromDirectory($stage, $zip)
    L ("Backup criado: {0} ({1:N1} MB) verificado={2}" -f $zip, ((Get-Item $zip).Length / 1MB), $verificado)
    if ($Destino) { Garantir-Pasta $Destino; Copy-Item -LiteralPath $zip -Destination $Destino -Force; L "Cópia exportada para $Destino" }
    # retenção: remove os mais antigos que $Retencao dias, mantendo sempre os 5 mais recentes
    $todos = Get-ChildItem -LiteralPath $C.Backups -Filter 'rota403-*.zip' | Sort-Object LastWriteTime -Descending
    $todos | Select-Object -Skip 5 | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$Retencao) } | ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force; L "Removido por retenção: $($_.Name)" }
    exit 0
} catch {
    L ('ERRO no backup: ' + $_.Exception.Message); exit 1
} finally {
    if ($stage) { Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue }
}
