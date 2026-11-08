@echo off
rem Reinicia o projetor e confere qual tela ficou em primeiro plano.
cd /d "%~dp0.."
call scripts\config.bat
title Reiniciando projetor - aguarde
set LOG=logs\09_teste_boot.txt
echo ==== %date% %time% ==== > %LOG%
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
%ADB% reboot >> %LOG% 2>&1
timeout /t 40 /nobreak > nul
set /a N=0
:espera
platform-tools\adb.exe disconnect %PROJ_IP%:5555 > nul 2>&1
platform-tools\adb.exe connect %PROJ_IP%:5555 > nul 2>&1
%ADB% shell getprop sys.boot_completed 2>nul | findstr 1 > nul
if not errorlevel 1 goto pronto
set /a N+=1
if %N% GEQ 30 (echo TEMPO ESGOTADO >> %LOG% & goto fim)
timeout /t 5 /nobreak > nul
goto espera
:pronto
timeout /t 35 /nobreak > nul
echo [tela em primeiro plano] >> %LOG%
%ADB% shell "dumpsys activity activities | grep -m1 topResumedActivity" >> %LOG% 2>nul
%ADB% shell "logcat -d | grep -E 'ActivityTaskManager: START.*(HOME|projengmenu)' | head -5" >> %LOG% 2>&1
%ADB% exec-out screencap -p > logs\telas\apos_boot.png
:fim
type %LOG%
echo.
echo Esperado: com.spocky.projengmenu/.ui.home.MainActivity em primeiro plano.
echo Captura da tela: logs\telas\apos_boot.png
pause
