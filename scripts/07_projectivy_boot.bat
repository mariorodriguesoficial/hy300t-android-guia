@echo off
rem Liga o servico de acessibilidade do Projectivy e a permissao de sobreposicao,
rem para que ele assuma a tela no boot. Depois, no Projectivy:
rem Configuracoes > Geral > "Sobrepor o launcher atual" = LIGADO.
cd /d "%~dp0.."
call scripts\config.bat
set LOG=logs\07_projectivy_boot.txt
echo ==== %date% %time% ==== > %LOG%
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
%ADB% shell settings put secure enabled_accessibility_services com.spocky.projengmenu/.services.ProjectivyAccessibilityService >> %LOG% 2>&1
%ADB% shell settings put secure accessibility_enabled 1 >> %LOG% 2>&1
%ADB% shell appops set com.spocky.projengmenu SYSTEM_ALERT_WINDOW allow >> %LOG% 2>&1
%ADB% shell "dumpsys accessibility | grep -iE 'Enabled services|Bound services'" >> %LOG% 2>&1
%ADB% shell am start -n com.spocky.projengmenu/.ui.guidedActions.activities.settings.AppSettingsActivity >> %LOG% 2>&1
type %LOG%
echo.
echo No projetor abriu a configuracao do Projectivy:
echo   Geral ^> "Sobrepor o launcher atual" = LIGADO
pause
