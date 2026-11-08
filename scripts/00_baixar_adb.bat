@echo off
rem Baixa o Android SDK Platform-Tools oficial do Google (versao portatil).
cd /d "%~dp0.."
call scripts\config.bat
set LOG=logs\00_baixar_adb.txt
echo ==== %date% %time% ==== > %LOG%
if exist platform-tools\adb.exe (echo ADB ja existe. >> %LOG% & goto versao)
powershell -NoProfile -Command "$ProgressPreference='SilentlyContinue'; Invoke-WebRequest -Uri 'https://dl.google.com/android/repository/platform-tools-latest-windows.zip' -OutFile 'platform-tools.zip'; Expand-Archive -Force 'platform-tools.zip' '.'; 'SHA256 do zip: ' + (Get-FileHash platform-tools.zip -Algorithm SHA256).Hash" >> %LOG% 2>&1
:versao
platform-tools\adb.exe version >> %LOG% 2>&1
type %LOG%
pause
