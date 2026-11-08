@echo off
rem Desativa apps do fabricante/rastreamento (REVERSIVEL com reverter_limpeza.bat).
rem NAO inclua com.jm.jmlauncher nesta lista: ele atende o botao Inicio.
cd /d "%~dp0.."
call scripts\config.bat
set APPS=com.master.apps com.overseas.store.appstore com.master.tv.market com.abc.ninja
set LOG=logs\05_limpeza.txt
echo ==== %date% %time% ==== > %LOG%
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
for %%P in (%APPS%) do (
  echo -- %%P >> %LOG%
  %ADB% shell am force-stop %%P >> %LOG% 2>&1
  %ADB% shell pm disable-user --user 0 %%P >> %LOG% 2>&1
)
echo [desativados agora] >> %LOG%
%ADB% shell pm list packages -d >> %LOG% 2>&1
type %LOG%
echo.
echo Teste agora: imagem, controle, HDMI, Wi-Fi, som e trapezio.
pause
