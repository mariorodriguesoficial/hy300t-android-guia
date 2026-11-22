<#
 Atualização (chamada pelo instalador). -Fase Antes: para serviços e faz backup. -Fase Depois: migra o banco, sobe serviços e checa saúde.
 Dados, uploads, config.local.php e chave NUNCA são sobrescritos (não fazem parte dos arquivos do instalador).
 Se a checagem falhar, o backup 'pre-atualizacao' continua disponível para restaurar (restaurar.ps1).
#>
param([ValidateSet('Antes', 'Depois')][string]$Fase, [int]$PortaHttp = 8080,
      [string]$Raiz = (Split-Path -Parent $PSScriptRoot), [string]$Dados = (Join-Path $env:ProgramData 'Rota403'))
. "$PSScriptRoot\lib.ps1"
$C = Get-Caminhos $Raiz $Dados; Garantir-Pasta $C.Logs
$log = Join-Path $C.Logs 'atualizacao.log'
function L([string]$m) { Escrever-Log $m $log }
try {
    if ($Fase -eq 'Antes') {
        if (-not (Get-Service Rota403DB -ErrorAction SilentlyContinue)) { L 'Primeira instalação: nada a preservar.'; exit 0 }
        if ((Get-Service Rota403DB).Status -ne 'Running') { Start-Service Rota403DB; $null = Esperar-Porta 3307 60 }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'backup.ps1') -Motivo 'pre-atualizacao' -Raiz $C.Raiz -Dados $C.Dados
        if ($LASTEXITCODE -ne 0) { throw 'Backup prévio falhou: atualização abortada (nada foi alterado).' }
        foreach ($s in 'Rota403Sync', 'Rota403Web') { Stop-Service $s -Force -ErrorAction SilentlyContinue }
        L 'Pronto para atualizar arquivos.'
    } else {
        $php = Join-Path $C.Php 'php.exe'
        $null = Executar $php @('-c', (Join-Path $C.Php 'php.ini'), (Join-Path $C.App 'bin\migrate.php')) 'migrações'
        Start-Service Rota403Web
        if (-not (Esperar-Porta $PortaHttp 60)) { throw 'Servidor web não subiu após a atualização.' }
        $r = Invoke-WebRequest -UseBasicParsing -Uri ("http://127.0.0.1:{0}/api/sync/v1/health" -f $PortaHttp) -TimeoutSec 20
        if ($r.StatusCode -ne 200) { throw 'Checagem de saúde falhou.' }
        try { $cfg = Ler-ConfigApp $C; if ($cfg.SYNC_ENABLED) { Start-Service Rota403Sync } } catch { }
        L 'Atualização concluída e saudável.'
    }
    exit 0
} catch { L ('ERRO: ' + $_.Exception.Message); exit 1 }
