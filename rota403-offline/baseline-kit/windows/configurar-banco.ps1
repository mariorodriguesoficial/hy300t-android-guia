param(
  [string]$MariaDbBin = "$PSScriptRoot\..\runtime\mariadb\bin",
  [string]$RootPassword = "",
  [string]$AppPassword = ""
)
$ErrorActionPreference = "Stop"
$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$appDir = Join-Path $root "app"
$sql = Join-Path $root "database\rota403.sql"
$client = Join-Path $MariaDbBin "mariadb.exe"
if (!(Test-Path $client)) { $client = Join-Path $MariaDbBin "mysql.exe" }
if (!(Test-Path $client)) { throw "Cliente MariaDB não encontrado: $MariaDbBin" }
if ([string]::IsNullOrWhiteSpace($RootPassword)) { $sec=Read-Host "Senha do administrador MariaDB" -AsSecureString; $RootPassword=[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)) }
if ([string]::IsNullOrWhiteSpace($AppPassword)) { $sec=Read-Host "Defina uma senha forte para o usuário rota403" -AsSecureString; $AppPassword=[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)) }
$AppPassword = $AppPassword.Replace("'", "''")
$env:MYSQL_PWD=$RootPassword
& $client -h 127.0.0.1 -P 3307 -u root -e "CREATE DATABASE IF NOT EXISTS rota403_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'rota403'@'127.0.0.1' IDENTIFIED BY '$AppPassword'; GRANT ALL PRIVILEGES ON rota403_local.* TO 'rota403'@'127.0.0.1'; FLUSH PRIVILEGES;"
if ($LASTEXITCODE -ne 0) { throw "Falha ao criar banco/usuário local." }
Get-Content -Raw $sql | & $client -h 127.0.0.1 -P 3307 -u root rota403_local
if ($LASTEXITCODE -ne 0) { throw "Falha ao importar o banco SQL." }
$config = @"
<?php
return [
 'DB_HOST' => '127.0.0.1',
 'DB_PORT' => '3307',
 'DB_USER' => 'rota403',
 'DB_PASS' => '$AppPassword',
 'DB_NAME' => 'rota403_local',
];
"@
Set-Content -Path (Join-Path $appDir "config\config.local.php") -Value $config -Encoding UTF8
Remove-Item Env:\MYSQL_PWD
Write-Host "Banco local configurado. Reinicie o Rota 403."
