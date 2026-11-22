$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$iscc = "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe"
if (!(Test-Path $iscc)) { $iscc = "$env:ProgramFiles\Inno Setup 6\ISCC.exe" }
if (!(Test-Path $iscc)) { throw "Inno Setup 6 não encontrado. Instale o Inno Setup no Windows." }
$php = Join-Path $root "runtime\php\php.exe"
$maria = Join-Path $root "runtime\mariadb\bin"
if (!(Test-Path $php)) { throw "Inclua o PHP Windows x64 em runtime\php antes de compilar." }
if (!(Test-Path $maria)) { throw "Inclua o MariaDB Windows x64 em runtime\mariadb\bin antes de compilar." }
& $iscc (Join-Path $root "installer\Rota403.iss")
if ($LASTEXITCODE -ne 0) { throw "Falha na compilação do instalador." }
Write-Host "Instalador compilado na pasta installer\output."
