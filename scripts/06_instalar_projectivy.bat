@echo off
rem Abre o Projectivy Launcher na Play Store do projetor, espera a instalacao
rem e define como tela inicial (preferencia + papel HOME do Android 13).
cd /d "%~dp0.."
call scripts\config.bat
set LOG=logs\06_instalar_projectivy.txt
echo ==== %date% %time% ==== > %LOG%
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
%ADB% shell am start -a android.intent.action.VIEW -d "market://details?id=com.spocky.projengmenu" >> %LOG% 2>&1
echo Aperte INSTALAR no projetor. Aguardando ate 5 minutos...
set /a N=0
:espera
%ADB% shell pm path com.spocky.projengmenu > nul 2>&1
if not errorlevel 1 goto instalado
set /a N+=1
if %N% GEQ 60 (echo TEMPO ESGOTADO >> %LOG% & goto fim)
timeout /t 5 /nobreak > nul
goto espera
:instalado
timeout /t 10 /nobreak > nul
%ADB% shell cmd package set-home-activity --user 0 com.spocky.projengmenu/.ui.home.MainActivity >> %LOG% 2>&1
%ADB% shell cmd role add-role-holder android.app.role.HOME com.spocky.projengmenu 0 >> %LOG% 2>&1
%ADB% shell input keyevent KEYCODE_HOME >> %LOG% 2>&1
echo Projectivy instalado e definido como Inicio. >> %LOG%
:fim
type %LOG%
pause
