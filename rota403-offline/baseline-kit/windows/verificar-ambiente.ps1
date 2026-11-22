$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$php = Join-Path $root "runtime\php\php.exe"
$mysql = Join-Path $root "runtime\mariadb\bin\mariadb.exe"
Write-Host "Verificacao do Rota 403 Offline"
if (!(Test-Path $php)) { Write-Host "[FALTA] runtime\php\php.exe"; } else {
  & $php -v | Select-Object -First 1
  & $php -m | Select-String "PDO|pdo_mysql|mbstring|openssl"
}
if (!(Test-Path $mysql)) { Write-Host "[FALTA] runtime\mariadb\bin\mariadb.exe"; } else { Write-Host "[OK] Cliente MariaDB localizado" }
if (!(Test-Path (Join-Path $root "app\config\config.local.php"))) { Write-Host "[FALTA] app\config\config.local.php" }
