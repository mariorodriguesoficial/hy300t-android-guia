@echo off
rem Executa no projetor os comandos de scripts\cmd.txt (um por linha, sem "adb shell")
rem e salva uma captura da tela em logs\telas\atual.png
cd /d "%~dp0.."
call scripts\config.bat
platform-tools\adb.exe connect %PROJ_IP%:5555 > nul 2>&1
if exist scripts\cmd.txt (for /f "usebackq delims=" %%C in ("scripts\cmd.txt") do %ADB% shell %%C)
timeout /t 3 /nobreak > nul
%ADB% exec-out screencap -p > logs\telas\atual.png
start "" logs\telas\atual.png
