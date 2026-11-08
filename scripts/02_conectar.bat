@echo off
rem Conecta ao projetor via ADB pela rede (porta 5555).
cd /d "%~dp0.."
call scripts\config.bat
set LOG=logs\02_conectar.txt
echo ==== %date% %time% ==== > %LOG%
platform-tools\adb.exe kill-server >> %LOG% 2>&1
platform-tools\adb.exe start-server >> %LOG% 2>&1
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
echo Se aparecer pedido de autorizacao no projetor, aceite (Sempre permitir). Aguardando 20s...
timeout /t 20 /nobreak > nul
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
platform-tools\adb.exe devices -l >> %LOG% 2>&1
%ADB% shell getprop ro.product.device >> %LOG% 2>&1
%ADB% shell id >> %LOG% 2>&1
type %LOG%
pause
