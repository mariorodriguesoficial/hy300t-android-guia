<#
 Restaura um backup (.zip do backup.ps1). DESTRUTIVO: substitui o banco atual. Antes faz um backup 'pre-restauracao'.
 Uso: restaurar.ps1 -Arquivo C:\...\rota403-AAAA....zip [-Confirmar RESTAURAR]
#>
param([Parameter(Mandatory = $true)][string]$Arquivo, [string]$Confirmar = '', [switch]$SemBackupPrevio,
      [string]$Raiz = (Split-Path -Parent $PSScriptRoot), [string]$Dados = (Join-Path $env:ProgramData 'Rota403'))
. "$PSScriptRoot\lib.ps1"
Add-Type -AssemblyName System.IO.Compression.FileSystem
$C = Get-Caminhos $Raiz $Dados
$log = Join-Path $C.Logs 'restauracao.log'
function L([string]$m) { Escrever-Log $m $log }
$stage = $null
try {
    if (-not (Test-Path -LiteralPath $Arquivo)) { throw "Arquivo não encontrado: $Arquivo" }
    if ($Confirmar -ne 'RESTAURAR') {
        Write-Host 'ATENÇÃO: isto SUBSTITUI todos os dados atuais pelos do backup.' -ForegroundColor Yellow
        $Confirmar = Read-Host 'Digite RESTAURAR (em maiúsculas) para continuar'
        if ($Confirmar -ne 'RESTAURAR') { Write-Host 'Cancelado.'; exit 2 }
    }
    $stage = Join-Path $C.Tmp ('rest-' + (Get-Date -Format 'yyyyMMdd-HHmmss')); Garantir-Pasta $stage
    [System.IO.Compression.ZipFile]::ExtractToDirectory($Arquivo, $stage)
    $mf = Get-Content -Raw -LiteralPath (Join-Path $stage 'manifest.json') | ConvertFrom-Json
    $sqlFile = Join-Path $stage 'dump.sql'
    if ((Get-FileHash -Algorithm SHA256 -LiteralPath $sqlFile).Hash -ne $mf.sha256_dump) { throw 'Backup corrompido: hash do dump não confere.' }
    L "Backup válido (esquema $($mf.esquema), criado em $($mf.criado_utc))."
    if (-not $SemBackupPrevio) {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'backup.ps1') -Motivo 'pre-restauracao' -Raiz $C.Raiz -Dados $C.Dados
        if ($LASTEXITCODE -ne 0) { throw 'Não foi possível fazer o backup prévio; restauração cancelada.' }
    }
    $cfg = Ler-ConfigApp $C; $cli = Cliente-Maria $C
    foreach ($s in 'Rota403Sync', 'Rota403Web') { Stop-Service $s -Force -ErrorAction SilentlyContinue }
    $env:MYSQL_PWD = Ler-Segredo (Join-Path $C.Segredos 'db-root.bin')
    try {
        $db = $cfg.DB_NAME
        $null = Executar $cli @('-h', $cfg.DB_HOST, '-P', "$($cfg.DB_PORT)", '-u', 'root', '-e', "DROP DATABASE IF EXISTS ``$db``; CREATE DATABASE ``$db`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;") 'recriação do banco'
        Get-Content -Raw -LiteralPath $sqlFile -Encoding UTF8 | & $cli -h $cfg.DB_HOST -P $cfg.DB_PORT -u root $db
        if ($LASTEXITCODE -ne 0) { throw 'Falha ao importar o dump.' }
        $null = Executar $cli @('-h', $cfg.DB_HOST, '-P', "$($cfg.DB_PORT)", '-u', 'root', '-e', "GRANT ALL PRIVILEGES ON ``$db``.* TO 'rota403'@'127.0.0.1'; FLUSH PRIVILEGES;") 'permissões'
        # confere contagens
        $erros = @()
        foreach ($p in $mf.tabelas.PSObject.Properties) {
            $n = [int64](& $cli -h $cfg.DB_HOST -P $cfg.DB_PORT -u root -N -B -e "SELECT COUNT(*) FROM ``$($p.Name)``" $db)
            $ok = if ($mf.verificado) { $n -eq [int64]$p.Value } else { $n -ge [int64]$p.Value }   # manifesto não verificado é só referência
            if (-not $ok) { $erros += "$($p.Name): $n <> $($p.Value)" }
        }
        if ($erros.Count) { throw ('Contagens divergentes após restaurar: ' + ($erros -join '; ')) }
    } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue }
    # arquivos
    $upB = Join-Path $stage 'uploads'
    if (Test-Path $upB) { $upD = Join-Path $C.App 'uploads'; Copy-Item -Path (Join-Path $upB '*') -Destination $upD -Recurse -Force }
    $chB = Join-Path $stage 'chave_seguranca.php'; $chD = Join-Path $C.App 'config\chave_seguranca.php'
    if (Test-Path $chB) { if (Test-Path $chD) { Copy-Item $chD ($chD + '.antes-restauracao') -Force }; Copy-Item $chB $chD -Force }
    $php = Join-Path $C.Php 'php.exe'
    $null = Executar $php @('-c', (Join-Path $C.Php 'php.ini'), (Join-Path $C.App 'bin\migrate.php')) 'migrações pós-restauração'
    Start-Service Rota403Web; try { Start-Service Rota403Sync -ErrorAction Stop } catch { }
    L 'Restauração concluída e verificada (contagens iguais às do manifesto).'
    exit 0
} catch {
    L ('ERRO na restauração: ' + $_.Exception.Message)
    Start-Service Rota403Web -ErrorAction SilentlyContinue
    exit 1
} finally { if ($stage) { Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue } }
