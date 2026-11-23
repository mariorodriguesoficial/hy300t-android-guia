<#
 Liga a sincronização deste PDV com o servidor online (HTTPS). Rode como Administrador DEPOIS de o online estar preparado
 (veja deploy-online\LEIA-ME-DEPLOY.md) e de ter o device_id e o secret gerados lá.
 Uso: configurar-sync.ps1 -DeviceId <uuid> [-Url https://mrinformatica.inf.br/rota403/api/sync/v1]
 O secret é digitado de forma oculta (não vai para a linha de comando nem para o histórico).
#>
param(
    [Parameter(Mandatory = $true)][string]$DeviceId,
    [string]$Url = 'https://mrinformatica.inf.br/rota403/api/sync/v1',
    [string]$Raiz = '', [string]$Dados = (Join-Path $env:ProgramData 'Rota403'), [switch]$SemIniciar
)
if (-not $Raiz) { $Raiz = Split-Path -Parent $PSScriptRoot }   # (não usar $PSScriptRoot em valor padrão de parâmetro: falha com parâmetro obrigatório)
. "$PSScriptRoot\lib.ps1"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$C = Get-Caminhos $Raiz $Dados
$log = Join-Path $C.Logs 'sync-config.log'
function L([string]$m) { Escrever-Log $m $log }
function Para-Php($v) {
    if ($v -is [bool]) { if ($v) { return 'true' } else { return 'false' } }
    if ($v -is [int] -or $v -is [long]) { return [string]$v }
    return "'" + (([string]$v).Replace('\', '\\').Replace("'", "\'")) + "'"
}
try {
    if ($DeviceId -notmatch '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$') { throw 'DeviceId inválido (esperado um UUID).' }
    if ($Url -notmatch '^https://') { throw 'A URL precisa ser HTTPS.' }
    $Url = $Url.TrimEnd('/')
    $sec = Read-Host 'Cole o SECRET do dispositivo (não aparece na tela)' -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
    try { $segredo = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
    if ($segredo -notmatch '^[0-9a-f]{64}$') { throw 'Secret inválido (esperado 64 caracteres hexadecimais).' }

    # 1) pacote de certificados (o PHP do Windows não traz): baixa e confere o SHA256 publicado pelo curl.se
    $dirCa = Join-Path $C.Raiz 'runtime\cacert'; $ca = Join-Path $dirCa 'cacert.pem'
    if (-not (Test-Path $ca)) {
        Garantir-Pasta $dirCa
        L 'Baixando pacote de certificados raiz (curl.se)...'
        Invoke-WebRequest -UseBasicParsing -Uri 'https://curl.se/ca/cacert.pem' -OutFile $ca
        $esperado = ((Invoke-WebRequest -UseBasicParsing -Uri 'https://curl.se/ca/cacert.pem.sha256').Content -split '\s+')[0].Trim().ToLower()
        $obtido = (Get-FileHash -Algorithm SHA256 -LiteralPath $ca).Hash.ToLower()
        if ($esperado -ne $obtido) { Remove-Item $ca -Force; throw 'Hash do pacote de certificados não confere. Abortado.' }
    }
    # 2) o servidor online responde? (health é público e não revela dados)
    try { $h = Invoke-WebRequest -UseBasicParsing -Uri ($Url + '/health') -TimeoutSec 25; $j = $h.Content | ConvertFrom-Json }
    catch { throw ('Não consegui falar com ' + $Url + '/health : ' + $_.Exception.Message + ' (o online já recebeu os arquivos e as migrações?)') }
    if (-not ($j.ok -and $j.esquema)) { throw 'O servidor online respondeu, mas o esquema de sincronização não está aplicado (rode as migrações no online).' }
    L 'Servidor online OK (health).'

    # 3) grava as chaves SYNC_* no config.local.php (sem BOM), guardando cópia protegida
    $cfg = Ler-ConfigApp $C
    $mapa = [ordered]@{}
    foreach ($p in $cfg.PSObject.Properties) { $mapa[$p.Name] = $p.Value }
    Copy-Item -LiteralPath $C.Config -Destination (Join-Path $C.Segredos 'config.local.php.antes-sync') -Force
    $mapa['SYNC_ENABLED'] = $true; $mapa['SYNC_API_URL'] = $Url; $mapa['SYNC_DEVICE_ID'] = $DeviceId.ToLower(); $mapa['SYNC_SECRET'] = $segredo
    $mapa['SYNC_CA_BUNDLE'] = $ca.Replace('\', '/')
    $linhas = @('<?php', '// Gerado pelo instalador/configurar-sync.ps1 do Rota 403. NÃO versionar, NÃO compartilhar.', 'return [')
    foreach ($k in $mapa.Keys) { $linhas += ("    '{0}' => {1}," -f $k, (Para-Php $mapa[$k])) }
    $linhas += '];'
    [System.IO.File]::WriteAllText($C.Config, ($linhas -join "`r`n") + "`r`n", (New-Object System.Text.UTF8Encoding($false)))
    L 'config.local.php atualizado (SYNC_ENABLED=true).'
    $segredo = $null

    # 4) primeira sincronização manual: mostra o resultado
    $php = Join-Path $C.Php 'php.exe'
    Write-Host 'Primeira sincronização (pode demorar se o online tiver muito histórico)...'
    $saida = & $php -c (Join-Path $C.Php 'php.ini') (Join-Path $C.App 'bin\sync-worker.php') --once 2>&1
    $saida | ForEach-Object { Write-Host $_ }
    if ($LASTEXITCODE -ne 0) { throw 'A primeira sincronização falhou (veja acima). A configuração foi gravada; corrija e rode "php bin\sync-worker.php --once".' }
    # 5) serviço em segundo plano
    if (-not $SemIniciar) { Set-Service Rota403Sync -StartupType Automatic; Restart-Service Rota403Sync; L 'Serviço Rota403Sync iniciado.' }
    Write-Host 'Sincronização ligada. Acompanhe em: Rota 403 > Sincronização (menu).' -ForegroundColor Green
    exit 0
} catch { L ('ERRO: ' + $_.Exception.Message); Write-Host ('ERRO: ' + $_.Exception.Message) -ForegroundColor Red; exit 1 }
