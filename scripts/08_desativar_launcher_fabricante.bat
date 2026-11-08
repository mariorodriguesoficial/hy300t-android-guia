@echo off
rem Desativa SOMENTE o launcher visual do fabricante.
rem O com.jm.jmlauncher (trampolim do botao Inicio) continua ATIVO.
cd /d "%~dp0.."
call scripts\config.bat
set LOG=logs\08_desativar_launcher_fabricante.txt
echo ==== %date% %time% ==== > %LOG%
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
%ADB% shell pm enable com.jm.jmlauncher >> %LOG% 2>&1
%ADB% shell "setprop persist.sys.boot_app ''" >> %LOG% 2>&1
%ADB% shell am force-stop com.htc.htclauncherhighenglishd08 >> %LOG% 2>&1
%ADB% shell pm disable-user --user 0 com.htc.htclauncherhighenglishd08 >> %LOG% 2>&1
%ADB% shell cmd package set-home-activity --user 0 com.spocky.projengmenu/.ui.home.MainActivity >> %LOG% 2>&1
%ADB% shell cmd role add-role-holder android.app.role.HOME com.spocky.projengmenu 0 >> %LOG% 2>&1
%ADB% shell input keyevent KEYCODE_HOME >> %LOG% 2>&1
type %LOG%
pause
