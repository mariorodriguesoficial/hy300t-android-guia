@echo off
title Rota 403 - Configurar QZ Tray
echo ==========================================================
echo   Rota 403 - liberar "Remember this decision"
echo ==========================================================
net session >nul 2>&1
if errorlevel 1 (
  echo.
  echo ERRO: clique com o botao DIREITO neste arquivo e escolha
  echo       "Executar como administrador".
  echo.
  pause
  exit /b 1
)
if not exist "%~dp0override.crt" (
  echo ERRO: o arquivo override.crt precisa estar na mesma pasta deste instalador.
  pause
  exit /b 1
)
set "QZDIR=%ProgramFiles%\QZ Tray"
if not exist "%QZDIR%\qz-tray.exe" set "QZDIR=%ProgramFiles(x86)%\QZ Tray"
if not exist "%QZDIR%\qz-tray.exe" (
  echo ERRO: QZ Tray nao encontrado. Instale pelo site qz.io/download e rode de novo.
  pause
  exit /b 1
)
echo QZ Tray encontrado em: %QZDIR%
echo [1/4] Fechando o QZ Tray...
taskkill /F /IM qz-tray.exe >nul 2>&1
taskkill /F /IM qz-tray-console.exe >nul 2>&1
timeout /t 3 /nobreak >nul
echo [2/4] Instalando o certificado do sistema...
copy /Y "%~dp0override.crt" "%QZDIR%\override.crt" >nul
if errorlevel 1 (
  echo ERRO ao copiar o certificado.
  pause
  exit /b 1
)
echo [3/4] Ajustando qz-tray.properties...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$d=$env:QZDIR; $p=Join-Path $d 'qz-tray.properties'; $l=@(); if (Test-Path $p) { $l=@(Get-Content $p | Where-Object { $_ -notmatch '^\s*authcert\.override' }) }; $l += ('authcert.override=' + ((Join-Path $d 'override.crt') -replace '\\','/')); Set-Content -Path $p -Value $l -Encoding ASCII"
if exist "%APPDATA%\qz\blocked.dat" (
  copy /Y "%APPDATA%\qz\blocked.dat" "%APPDATA%\qz\blocked.dat.bak" >nul
  del /F /Q "%APPDATA%\qz\blocked.dat" >nul
  echo       Lista de sites bloqueados do QZ foi limpa.
)
echo [4/4] Abrindo o QZ Tray...
start "" "%QZDIR%\qz-tray.exe"
echo.
echo PRONTO. Agora:
echo  1. Espere o icone do QZ Tray aparecer perto do relogio.
echo  2. Abra https://mrinformatica.inf.br/rota403/impressao
echo     e aperte Ctrl+F5.
echo  3. Na janela do QZ deve aparecer "Verified by mrinformatica.inf.br".
echo     Marque "Remember this decision" e clique em "Allow".
echo.
pause
