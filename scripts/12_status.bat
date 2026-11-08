@echo off
rem Mostra o estado atual (somente leitura).
cd /d "%~dp0.."
call scripts\config.bat
set LOG=logs\12_status.txt
echo ==== %date% %time% ==== > %LOG%
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
echo [primeiro plano] >> %LOG%
%ADB% shell "dumpsys activity activities | grep -m1 topResumedActivity" >> %LOG% 2>nul
echo [launcher padrao] >> %LOG%
%ADB% shell cmd package resolve-activity --brief -c android.intent.category.HOME -a android.intent.action.MAIN >> %LOG% 2>&1
echo [papel HOME] >> %LOG%
%ADB% shell "dumpsys role | grep -A1 'name=android.app.role.HOME'" >> %LOG% 2>&1
echo [boot_app] >> %LOG%
%ADB% shell getprop persist.sys.boot_app >> %LOG% 2>&1
echo [desativados] >> %LOG%
%ADB% shell pm list packages -d >> %LOG% 2>&1
echo [processos] >> %LOG%
%ADB% shell "ps -A | grep -E 'jmlauncher|projengmenu|tcorrection|mbox'" >> %LOG% 2>&1
echo [acessibilidade] >> %LOG%
%ADB% shell settings get secure enabled_accessibility_services >> %LOG% 2>&1
type %LOG%
pause
