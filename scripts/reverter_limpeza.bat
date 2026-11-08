@echo off
rem Desfaz TUDO: reativa os apps, devolve o Inicio ao launcher do fabricante,
rem desliga a acessibilidade do Projectivy e limpa persist.sys.boot_app.
cd /d "%~dp0.."
call scripts\config.bat
set LOG=logs\reverter_limpeza.txt
echo ==== %date% %time% ==== > %LOG%
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
for %%P in (com.master.apps com.overseas.store.appstore com.master.tv.market com.abc.ninja com.htc.htclauncherhighenglishd08 com.jm.jmlauncher) do (
  %ADB% shell pm enable %%P >> %LOG% 2>&1
  %ADB% shell cmd package install-existing %%P >> %LOG% 2>&1
)
%ADB% shell "setprop persist.sys.boot_app ''" >> %LOG% 2>&1
%ADB% shell cmd role add-role-holder android.app.role.HOME com.htc.htclauncherhighenglishd08 0 >> %LOG% 2>&1
%ADB% shell cmd package set-home-activity --user 0 com.htc.htclauncherhighenglishd08/com.mao.launcher.activity.BarrelActivity >> %LOG% 2>&1
%ADB% shell settings put secure enabled_accessibility_services null >> %LOG% 2>&1
%ADB% shell input keyevent KEYCODE_HOME >> %LOG% 2>&1
type %LOG%
echo Limpeza revertida.
pause
