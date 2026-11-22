# Funções comuns dos scripts do Rota 403 (PowerShell 5.1). Use: . "$PSScriptRoot\lib.ps1"
Set-StrictMode -Version 2
$ErrorActionPreference = 'Stop'

function Get-Raiz { Split-Path -Parent $PSScriptRoot }                       # pasta de instalação (…\Rota403)
function Get-DadosPadrao { Join-Path $env:ProgramData 'Rota403' }
function Get-Caminhos([string]$Raiz, [string]$Dados) {
    @{
        Raiz = $Raiz; Dados = $Dados
        App = Join-Path $Raiz 'app'; Php = Join-Path $Raiz 'runtime\php'; Maria = Join-Path $Raiz 'runtime\mariadb'
        Caddy = Join-Path $Raiz 'runtime\caddy'; Winsw = Join-Path $Raiz 'runtime\winsw'; Servicos = Join-Path $Raiz 'servicos'
        DbData = Join-Path $Dados 'mariadb-data'; Logs = Join-Path $Dados 'logs'; Backups = Join-Path $Dados 'backups'
        Segredos = Join-Path $Dados 'secrets'; Sessoes = Join-Path $Dados 'sessions'; Tmp = Join-Path $Dados 'tmp'; CaddyData = Join-Path $Dados 'caddy-data'
        Config = Join-Path $Raiz 'app\config\config.local.php'
    }
}
function Escrever-Log([string]$Msg, [string]$Arquivo) {
    $linha = '[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Msg
    Write-Host $linha
    if ($Arquivo) { Add-Content -LiteralPath $Arquivo -Value $linha -Encoding UTF8 }
}
function Garantir-Pasta([string]$P) { if (-not (Test-Path -LiteralPath $P)) { New-Item -ItemType Directory -Path $P -Force | Out-Null } }

# ACL: remove herança; SYSTEM e Administradores controle total; contas extras com a permissão pedida
function Proteger-Caminho([string]$P, [string[]]$ContasModificar = @(), [string[]]$ContasLer = @()) {
    $null = & icacls.exe $P /inheritance:r 2>&1
    $null = & icacls.exe $P /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' 2>&1
    foreach ($c in $ContasModificar) { $null = & icacls.exe $P /grant:r ("{0}:(OI)(CI)M" -f $c) 2>&1 }
    foreach ($c in $ContasLer) { $null = & icacls.exe $P /grant:r ("{0}:(OI)(CI)RX" -f $c) 2>&1 }
}
$script:ContaServico = '*S-1-5-19'        # NT AUTHORITY\LocalService

function Nova-Senha([int]$Bytes = 24) {
    $b = New-Object byte[] $Bytes
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create(); $rng.GetBytes($b); $rng.Dispose()
    -join ($b | ForEach-Object { $_.ToString('x2') })
}
# Segredos locais: DPAPI escopo MÁQUINA (só esta máquina decifra) + ACL restrita
Add-Type -AssemblyName System.Security
function Salvar-Segredo([string]$Arquivo, [string]$Valor) {
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Valor)
    $enc = [System.Security.Cryptography.ProtectedData]::Protect($bytes, $null, [System.Security.Cryptography.DataProtectionScope]::LocalMachine)
    [System.IO.File]::WriteAllBytes($Arquivo, $enc)
}
function Ler-Segredo([string]$Arquivo) {
    $enc = [System.IO.File]::ReadAllBytes($Arquivo)
    $dec = [System.Security.Cryptography.ProtectedData]::Unprotect($enc, $null, [System.Security.Cryptography.DataProtectionScope]::LocalMachine)
    [System.Text.Encoding]::UTF8.GetString($dec)
}
function Esperar-Porta([int]$Porta, [int]$Segundos = 60) {
    $fim = (Get-Date).AddSeconds($Segundos)
    while ((Get-Date) -lt $fim) {
        try { $c = New-Object System.Net.Sockets.TcpClient; $c.Connect('127.0.0.1', $Porta); $c.Close(); return $true } catch { Start-Sleep -Milliseconds 500 }
    }
    return $false
}
function Porta-Livre([int]$Porta) {
    $uso = Get-NetTCPConnection -State Listen -LocalPort $Porta -ErrorAction SilentlyContinue
    return -not $uso
}
# Executa programa e falha com mensagem útil se o código de saída != 0
function Executar([string]$Exe, [string[]]$Argumentos, [string]$Descricao) {
    $saida = & $Exe @Argumentos 2>&1
    if ($LASTEXITCODE -ne 0) { throw ("Falha em {0} (código {1}): {2}" -f $Descricao, $LASTEXITCODE, (($saida | Out-String).Trim())) }
    return $saida
}
# Lê config.local.php usando o PHP do próprio pacote (sem parsear PHP à mão)
function Ler-ConfigApp($C) {
    $php = Join-Path $C.Php 'php.exe'
    $json = & $php -c (Join-Path $C.Php 'php.ini') -r "echo json_encode(include '$($C.Config.Replace('\','/'))');"
    if ($LASTEXITCODE -ne 0) { throw 'Não foi possível ler config.local.php' }
    $json | ConvertFrom-Json
}
function Cliente-Maria($C) { $e = Join-Path $C.Maria 'bin\mariadb.exe'; if (-not (Test-Path $e)) { $e = Join-Path $C.Maria 'bin\mysql.exe' }; $e }
