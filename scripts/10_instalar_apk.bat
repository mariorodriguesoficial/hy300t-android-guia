@echo off
rem Instala um APK no projetor. Uso: arraste o arquivo .apk sobre este script.
cd /d "%~dp0.."
call scripts\config.bat
if "%~1"=="" (echo Arraste um arquivo .apk sobre este script. & pause & exit /b 1)
set LOG=logs\10_instalar_apk.txt
echo ==== %date% %time% ==== > %LOG%
echo Arquivo: %~1 >> %LOG%
powershell -NoProfile -Command "'SHA256: ' + (Get-FileHash '%~1' -Algorithm SHA256).Hash" >> %LOG% 2>&1
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
%ADB% install -r "%~1" >> %LOG% 2>&1
type %LOG%
pause
