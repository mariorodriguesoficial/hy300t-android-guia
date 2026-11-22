<#
 Teste de aceitação pós-instalação (rodar como Administrador). Imprime PASS/FAIL e grava logs\teste-instalacao.json.
 Uso: testar-instalacao.ps1 [-PortaHttp 8080] [-PortaDb 3307] [-SemBackup] [-LanEsperada]
#>
param([int]$PortaHttp = 8080, [int]$PortaDb = 3307, [int]$PortaHttps = 8443, [switch]$SemBackup, [switch]$LanEsperada,
      [string]$Raiz = (Split-Path -Parent $PSScriptRoot), [string]$Dados = (Join-Path $env:ProgramData 'Rota403'))
. "$PSScriptRoot\lib.ps1"
$C = Get-Caminhos $Raiz $Dados; Garantir-Pasta $C.Logs
$res = New-Object System.Collections.ArrayList
function T([string]$Nome, [scriptblock]$Teste) {
    $ok = $false; $det = ''
    try { $r = & $Teste; if ($r -is [bool]) { $ok = $r } elseif ($r) { $ok = [bool]$r.Ok; $det = [string]$r.Det } } catch { $det = $_.Exception.Message }
    $null = $res.Add([pscustomobject]@{ teste = $Nome; resultado = $(if ($ok) { 'PASS' } else { 'FAIL' }); detalhe = $det })
    Write-Host ('{0} {1} {2}' -f $(if ($ok) { 'PASS' } else { 'FAIL' }), $Nome, $det)
}
function Escuta([int]$Porta) { @(Get-NetTCPConnection -State Listen -LocalPort $Porta -ErrorAction SilentlyContinue) }
function Http([string]$Caminho) {
    try { $r = Invoke-WebRequest -UseBasicParsing -Uri ("http://127.0.0.1:{0}{1}" -f $PortaHttp, $Caminho) -TimeoutSec 20 -MaximumRedirection 0 -ErrorAction Stop; return [pscustomobject]@{ Cod = [int]$r.StatusCode; Corpo = [string]$r.Content } }
    catch { $resp = $_.Exception.Response; if ($resp) { return [pscustomobject]@{ Cod = [int]$resp.StatusCode; Corpo = '' } } else { return [pscustomobject]@{ Cod = 0; Corpo = '' } } }
}
$php = Join-Path $C.Php 'php.exe'; $ini = Join-Path $C.Php 'php.ini'

# ---- serviços
foreach ($s in 'Rota403DB', 'Rota403Web') { T "Serviço $s em execução" { (Get-Service $s -ErrorAction Stop).Status -eq 'Running' } }
T 'Serviço Rota403Sync instalado' { [bool](Get-Service Rota403Sync -ErrorAction SilentlyContinue) }
T 'Serviços iniciam automaticamente' { (@('Rota403DB', 'Rota403Web') | ForEach-Object { (Get-Service $_).StartType -eq 'Automatic' }) -notcontains $false }
T 'Serviços NÃO rodam como SYSTEM/Administrador' { $r = Get-CimInstance Win32_Service -Filter "Name='Rota403Web' OR Name='Rota403DB'"; ($r | Where-Object { $_.StartName -match 'LocalSystem|Administrator' }).Count -eq 0 }
# ---- rede: nada exposto fora do loopback
T "MariaDB escuta só em 127.0.0.1:$PortaDb" { $l = Escuta $PortaDb; ($l.Count -ge 1) -and (@($l | Where-Object { $_.LocalAddress -ne '127.0.0.1' }).Count -eq 0) }
T "HTTP local escuta só em 127.0.0.1:$PortaHttp" { $l = Escuta $PortaHttp; ($l.Count -ge 1) -and (@($l | Where-Object { $_.LocalAddress -ne '127.0.0.1' }).Count -eq 0) }
T 'Pool php-cgi só em loopback' { $m = @(9001, 9002, 9003, 9004 | ForEach-Object { Escuta $_ } | Where-Object { $_.LocalAddress -ne '127.0.0.1' }); $m.Count -eq 0 }
if ($LanEsperada) {
    T "HTTPS LAN escutando em $PortaHttps com regra de firewall só Privado/sub-rede" { $r = Get-NetFirewallRule -DisplayName 'Rota403 HTTPS (LAN)' -ErrorAction Stop; $f = $r | Get-NetFirewallAddressFilter; ((Escuta $PortaHttps).Count -ge 1) -and ($r.Profile -eq 'Private') -and ($f.RemoteAddress -eq 'LocalSubnet') }
} else {
    T 'LAN desabilitada: sem regra de firewall e sem porta HTTPS' { (-not (Get-NetFirewallRule -DisplayName 'Rota403 HTTPS (LAN)' -ErrorAction SilentlyContinue)) -and ((Escuta $PortaHttps).Count -eq 0) }
}
T 'Nenhuma regra de firewall abre MariaDB ou HTTP' { $x = Get-NetFirewallRule -Direction Inbound -Enabled True -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName -like 'Rota403*' } | Get-NetFirewallPortFilter | Where-Object { $_.LocalPort -in @("$PortaDb", "$PortaHttp") }; @($x).Count -eq 0 }
# ---- aplicação
T 'Tela de login responde 200' { $r = Http '/login'; @{ Ok = ($r.Cod -eq 200 -and $r.Corpo -match 'Rota 403'); Det = "HTTP $($r.Cod)" } }
T 'Login sem requisições externas (CDN/fontes)' { $r = Http '/login'; -not ($r.Corpo -match '(src|href)=["'']https?://(?!127\.0\.0\.1|localhost)') }
T 'Manifesto PWA e service worker servidos' { (Http '/manifest.php').Cod -eq 200 -and (Http '/sw.js').Cod -eq 200 }
foreach ($p in '/config/config.local.php', '/config/chave_seguranca.php', '/includes/Sync.php', '/migrations/001_sync_base.php', '/bin/migrate.php', '/storage/php-error.log') {
    T "Bloqueado: $p" { (Http $p).Cod -eq 403 }
}
T 'URL sem .php (/comandas) é reescrita (redireciona ao login)' { (Http '/comandas').Cod -in 301, 302 }
T 'Health da API de sync responde' { (Http '/api/sync/v1/health').Cod -eq 200 }
# ---- banco e migrações
$cfg = Ler-ConfigApp $C; $cli = Cliente-Maria $C
function Sql([string]$q) { $env:MYSQL_PWD = [string]$cfg.DB_PASS; try { (& $cli -h 127.0.0.1 -P $PortaDb -u $cfg.DB_USER -N -B -e $q $cfg.DB_NAME) } finally { Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue } }
T 'Esquema na versão 2 (migrações aplicadas)' { (Sql 'SELECT MAX(versao) FROM schema_migrations') -eq '2' }
T '14 tabelas base + tabelas de sync' { [int](Sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()") -ge 24 }
T 'Existe pelo menos 1 administrador' { [int](Sql "SELECT COUNT(*) FROM usuarios WHERE nivel='admin' AND ativo=1") -ge 1 }
T 'Nome do administrador está cifrado (enc1:)' { (Sql "SELECT COUNT(*) FROM usuarios WHERE nome NOT LIKE 'enc1:%'") -eq '0' }
T 'Banco usa utf8mb4' { (Sql 'SELECT @@character_set_database') -eq 'utf8mb4' }
T 'Usuário da aplicação NÃO é root' { $cfg.DB_USER -ne 'root' }
T 'Triggers de sync presentes' { [int](Sql "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()") -ge 40 }
# ---- segredos e permissões
T 'Pasta secrets sem acesso para Users/Everyone/Authenticated Users' { $a = (& icacls.exe $C.Segredos) -join ' '; -not ($a -match 'BUILTIN\\Users|Everyone|Authenticated Users') }
T 'config.local.php sem leitura para Users/Everyone' { $a = (& icacls.exe $C.Config) -join ' '; -not ($a -match 'BUILTIN\\Users|Everyone|Authenticated Users') }
T 'Backups sem acesso para Users/Everyone' { $a = (& icacls.exe $C.Backups) -join ' '; -not ($a -match 'BUILTIN\\Users|Everyone|Authenticated Users') }
T 'Nenhum arquivo .pem / chave privada QZ na instalação' { @(Get-ChildItem -LiteralPath $C.App -Recurse -Include *.pem, *.key -ErrorAction SilentlyContinue).Count -eq 0 }
T 'Admin temporário (JSON de senha) não ficou em disco' { @(Get-ChildItem -LiteralPath $env:TEMP -Filter 'rota403-admin-*.json' -ErrorAction SilentlyContinue).Count -eq 0 }
# ---- sem conexões externas dos processos do sistema
T 'Processos do Rota 403 sem conexão estabelecida com IP público' {
    $ids = @(Get-CimInstance Win32_Process | Where-Object { $_.Name -match '^(php|php-cgi|caddy|mariadbd)\.exe$' } | ForEach-Object { $_.ProcessId })
    $ext = @(Get-NetTCPConnection -State Established -ErrorAction SilentlyContinue | Where-Object { $ids -contains $_.OwningProcess -and $_.RemoteAddress -notmatch '^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|::1)' })
    @{ Ok = ($ext.Count -eq 0); Det = (($ext | ForEach-Object { $_.RemoteAddress }) -join ',') }
}
# ---- persistência após reinício dos serviços
T 'Dados persistem após reiniciar serviços' {
    $n1 = Sql 'SELECT COUNT(*) FROM usuarios'
    Restart-Service Rota403Web -Force; Stop-Service Rota403DB -Force; Start-Service Rota403DB; Start-Service Rota403Web
    $null = Esperar-Porta $PortaDb 60; $null = Esperar-Porta $PortaHttp 60
    $n2 = Sql 'SELECT COUNT(*) FROM usuarios'
    @{ Ok = ($n1 -eq $n2 -and (Http '/login').Cod -eq 200); Det = "usuarios antes=$n1 depois=$n2" }
}
# ---- backup verificável
if (-not $SemBackup) {
    T 'Backup manual verificado (restaura em banco temporário)' {
        $antes = @(Get-ChildItem $C.Backups -Filter 'rota403-*teste*.zip' -ErrorAction SilentlyContinue).Count
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'backup.ps1') -Motivo 'teste' -Raiz $C.Raiz -Dados $C.Dados | Out-Null
        $zip = Get-ChildItem $C.Backups -Filter 'rota403-*teste*.zip' | Sort-Object LastWriteTime -Descending | Select-Object -First 1
        $mf = $null; if ($zip) { Add-Type -AssemblyName System.IO.Compression.FileSystem; $z = [System.IO.Compression.ZipFile]::OpenRead($zip.FullName); try { $e = $z.GetEntry('manifest.json'); $sr = New-Object System.IO.StreamReader($e.Open()); $mf = $sr.ReadToEnd() | ConvertFrom-Json; $sr.Close() } finally { $z.Dispose() } }
        @{ Ok = ($LASTEXITCODE -eq 0 -and $zip -and $mf.verificado -eq $true); Det = $(if ($zip) { $zip.Name } else { 'sem zip' }) }
    }
}
$falhas = @($res | Where-Object resultado -eq 'FAIL').Count
$res | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath (Join-Path $C.Logs 'teste-instalacao.json') -Encoding UTF8
Write-Host ("`n{0} testes, {1} falha(s). Detalhes: {2}" -f $res.Count, $falhas, (Join-Path $C.Logs 'teste-instalacao.json'))
exit $(if ($falhas -eq 0) { 0 } else { 1 })
