@echo off
setlocal
cd /d "%~dp0.."
if not exist "runtime\php\php.exe" (
  echo PHP nao encontrado em runtime\php\php.exe
  echo Consulte LEIA-ME-WINDOWS.txt
  pause
  exit /b 1
)
if not exist "config\config.local.php" (
  echo Configuracao local ausente. Execute configurar-banco.ps1 como administrador.
  pause
  exit /b 1
)
start "Rota 403 PHP" /min "%~dp0php-servidor.bat"
timeout /t 3 /nobreak >nul
start "" "http://127.0.0.1:8080/login.php"
endlocal
