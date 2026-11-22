<#
 Compila o instalador Rota403-Offline-Setup.exe NUM WINDOWS 10/11 x64 com internet (só para compilar).
 Uso (PowerShell como Administrador, na pasta do projeto):
     powershell -ExecutionPolicy Bypass -File windows\compilar.ps1 -TravarHashes     # 1ª vez: baixa, calcula e grava os hashes
     powershell -ExecutionPolicy Bypass -File windows\compilar.ps1                   # depois: exige hashes iguais aos travados
 Saída: build\saida\Rota403-Offline-Setup.exe (+ .sha256)
#>
param([string]$Versao = '1.0.0', [switch]$TravarHashes, [switch]$SemInstalador)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$raiz = Split-Path -Parent $PSScriptRoot
$build = Join-Path $raiz 'build'; $cache = Join-Path $build 'cache'; $stage = Join-Path $build 'stage'; $saida = Join-Path $build 'saida'
foreach ($d in $build, $cache, $saida) { if (-not (Test-Path $d)) { New-Item -ItemType Directory -Path $d | Out-Null } }
function Passo([string]$m) { Write-Host ("`n== {0}" -f $m) -ForegroundColor Cyan }

Passo '1/7 Sintaxe de todos os scripts PowerShell'
$erros = 0
Get-ChildItem -Path (Join-Path $raiz 'windows') -Filter *.ps1 | ForEach-Object {
    $tokens = $null; $err = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile($_.FullName, [ref]$tokens, [ref]$err)
    if ($err -and $err.Count -gt 0) { $erros++; $err | ForEach-Object { Write-Host ("  {0}:{1} {2}" -f $_.Extent.File, $_.Extent.StartLineNumber, $_.Message) -ForegroundColor Red } }
}
if ($erros -gt 0) { throw "Há erros de sintaxe nos scripts ($erros arquivo(s)). Corrija antes de compilar." }
Write-Host '  OK'

Passo '2/7 Baixar e verificar runtimes (PHP, MariaDB, Caddy, WinSW, VC++)'
$rt = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'runtimes.json') -Encoding UTF8 | ConvertFrom-Json
$alterou = $false
$arquivos = @{}; $hashes = @{}
foreach ($nome in 'php', 'mariadb', 'caddy', 'winsw', 'vcredist') {
    $r = $rt.$nome
    $arq = if ($r.PSObject.Properties['arquivo']) { $r.arquivo } else { Split-Path -Leaf ([uri]$r.url).AbsolutePath }
    $dest = Join-Path $cache ("{0}-{1}-{2}" -f $nome, $r.versao, $arq)
    if (-not (Test-Path $dest)) { Write-Host "  baixando $nome $($r.versao)..."; Invoke-WebRequest -UseBasicParsing -Uri $r.url -OutFile $dest }
    $h = (Get-FileHash -Algorithm SHA256 -LiteralPath $dest).Hash.ToLower()
    $mutavel = $r.PSObject.Properties['mutavel'] -and $r.mutavel
    if ($mutavel) {
        $sig = Get-AuthenticodeSignature -FilePath $dest
        if ($sig.Status -ne 'Valid' -or $sig.SignerCertificate.Subject -notmatch 'Microsoft') { throw "$nome sem assinatura válida da Microsoft." }
        Write-Host ("  {0}: assinatura Microsoft OK (sha256 {1})" -f $nome, $h)
    } elseif ($r.sha256) {
        if ($r.sha256.ToLower() -ne $h) { throw "HASH DIFERENTE para $nome! esperado $($r.sha256) obtido $h. Não vou compilar." }
        Write-Host ("  {0}: hash confere" -f $nome)
    } elseif ($TravarHashes) {
        $hashes[$nome] = $h; $alterou = $true; Write-Host ("  {0}: hash TRAVADO {1}  <- confira com a página oficial do projeto" -f $nome, $h)
    } else { throw "Hash de $nome não travado. Rode com -TravarHashes uma vez, confira com a fonte oficial e faça commit de runtimes.json." }
    $arquivos[$nome] = $dest
}
if ($alterou) {
    # regrava a partir do arquivo ORIGINAL, só preenchendo os hashes novos
    $orig = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'runtimes.json') -Encoding UTF8 | ConvertFrom-Json
    foreach ($k in $hashes.Keys) { $orig.$k.sha256 = $hashes[$k] }
    $json = $orig | ConvertTo-Json -Depth 5
    [System.IO.File]::WriteAllText((Join-Path $PSScriptRoot 'runtimes.json'), $json, (New-Object System.Text.UTF8Encoding($true)))
    Write-Host '  runtimes.json atualizado com os hashes. CONFIRA-OS com os sites oficiais antes de distribuir o instalador.'
}

Passo '3/7 Montar pasta de distribuição (build\stage)'
if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
$rtDir = Join-Path $stage 'runtime'; New-Item -ItemType Directory -Path $rtDir | Out-Null
Expand-Archive -LiteralPath $arquivos['php'] -DestinationPath (Join-Path $rtDir 'php')
$tmpM = Join-Path $build 'tmp-maria'; if (Test-Path $tmpM) { Remove-Item -Recurse -Force $tmpM }
Expand-Archive -LiteralPath $arquivos['mariadb'] -DestinationPath $tmpM
$topo = Get-ChildItem $tmpM -Directory | Select-Object -First 1
Move-Item -LiteralPath $topo.FullName -Destination (Join-Path $rtDir 'mariadb'); Remove-Item -Recurse -Force $tmpM
foreach ($lixo in 'data', 'mysql-test', 'sql-bench', 'debug') { $p = Join-Path $rtDir "mariadb\$lixo"; if (Test-Path $p) { Remove-Item -Recurse -Force $p } }
Expand-Archive -LiteralPath $arquivos['caddy'] -DestinationPath (Join-Path $rtDir 'caddy')
New-Item -ItemType Directory -Path (Join-Path $rtDir 'winsw') | Out-Null; Copy-Item $arquivos['winsw'] (Join-Path $rtDir 'winsw\WinSW-x64.exe')
New-Item -ItemType Directory -Path (Join-Path $rtDir 'vcredist') | Out-Null; Copy-Item $arquivos['vcredist'] (Join-Path $rtDir 'vcredist\vc_redist.x64.exe')
# aplicação (sem segredos, sem dados, sem scripts perigosos)
$app = Join-Path $stage 'app'
& robocopy.exe (Join-Path $raiz 'app') $app /E /NFL /NDL /NJH /NJS /XD tests QZ_WINDOWS storage /XF config.local.php chave_seguranca.php ZERAR_VENDAS_E_ESTOQUE.sql 'ATUALIZACAO_*.txt' *.pem *.key *.log | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'robocopy falhou' }
New-Item -ItemType Directory -Path (Join-Path $app 'storage') -Force | Out-Null
Copy-Item -Recurse (Join-Path $raiz 'windows') (Join-Path $stage 'windows')
New-Item -ItemType Directory -Path (Join-Path $stage 'database') | Out-Null; Copy-Item (Join-Path $raiz 'database\schema.sql') (Join-Path $stage 'database\schema.sql')
Copy-Item (Join-Path $raiz 'THIRD-PARTY-NOTICES.md') $stage
Copy-Item (Join-Path $raiz 'installer\rota403.ico') $stage

Passo '4/7 Verificações do pacote (com o PHP que será instalado)'
$php = Join-Path $rtDir 'php\php.exe'
$tmpIni = Join-Path $build 'php-build.ini'
Set-Content -LiteralPath $tmpIni -Encoding ASCII -Value @("extension_dir=`"$((Join-Path $rtDir 'php\ext'))`"", 'extension=pdo_mysql', 'extension=mbstring', 'extension=openssl', 'extension=curl', 'extension=gd', 'extension=fileinfo', 'extension=intl', 'extension=iconv')
$mods = & $php -c $tmpIni -m
foreach ($m in 'pdo_mysql', 'mbstring', 'openssl', 'curl', 'gd', 'iconv') { if ($mods -notcontains $m) { throw "PHP empacotado sem a extensão $m" } }
$ruim = 0
Get-ChildItem -Recurse -Path $app -Filter *.php | ForEach-Object { $o = & $php -c $tmpIni -l $_.FullName 2>&1; if ($LASTEXITCODE -ne 0) { $ruim++; Write-Host "  $($_.FullName): $o" -ForegroundColor Red } }
if ($ruim) { throw "$ruim arquivo(s) PHP com erro de sintaxe." }
$proibidos = @(Get-ChildItem -Recurse -Path $stage -Include *.pem, *.key, config.local.php, chave_seguranca.php -ErrorAction SilentlyContinue)
if ($proibidos.Count) { throw ('Arquivo sensível no pacote: ' + ($proibidos.FullName -join ', ')) }
$cdn = Select-String -Path (Get-ChildItem -Recurse -Path $app -Include *.php, *.js, *.css | Where-Object { $_.FullName -notmatch 'assets\\vendor' }).FullName -Pattern 'cdn\.jsdelivr|unpkg\.com|fonts\.googleapis|fonts\.gstatic' -ErrorAction SilentlyContinue
if ($cdn) { throw ('Dependência de CDN encontrada: ' + ($cdn | Select-Object -First 3 | ForEach-Object { $_.Path + ':' + $_.LineNumber })) }
Write-Host '  OK: extensões, sintaxe PHP, sem segredos, sem CDN'
if ($SemInstalador) { Write-Host "`nPacote pronto em $stage (sem instalador)."; exit 0 }

Passo '5/7 Inno Setup'
function Achar-Iscc { foreach ($p in "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe", "$env:ProgramFiles\Inno Setup 6\ISCC.exe", "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe") { if (Test-Path $p) { return $p } }; return $null }
$iscc = Achar-Iscc
if (-not $iscc) {
    Write-Host '  Inno Setup não encontrado; instalando via winget...'
    & winget.exe install -e --id JRSoftware.InnoSetup --silent --accept-package-agreements --accept-source-agreements
    $iscc = Achar-Iscc
}
if (-not $iscc) { throw 'Inno Setup 6 não encontrado. Instale em https://jrsoftware.org/isdl.php e rode de novo.' }

Passo '6/7 Compilar instalador'
& $iscc "/DVersao=$Versao" "/DOrigem=$stage" "/O$saida" (Join-Path $raiz 'installer\Rota403.iss')
if ($LASTEXITCODE -ne 0) { throw 'ISCC falhou.' }

Passo '7/7 Hash do instalador'
$exe = Join-Path $saida 'Rota403-Offline-Setup.exe'
$hx = (Get-FileHash -Algorithm SHA256 -LiteralPath $exe).Hash.ToLower()
Set-Content -LiteralPath ($exe + '.sha256') -Value "$hx  Rota403-Offline-Setup.exe" -Encoding ASCII
Write-Host ("`nPRONTO: {0}`nSHA256: {1}`nTamanho: {2:N1} MB" -f $exe, $hx, ((Get-Item $exe).Length / 1MB))
