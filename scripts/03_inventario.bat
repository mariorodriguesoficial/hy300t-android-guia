@echo off
rem Inventario do sistema. SOMENTE LEITURA - nao altera nada no projetor.
cd /d "%~dp0.."
call scripts\config.bat
set P=backup\props
if not exist %P% mkdir %P%
platform-tools\adb.exe connect %PROJ_IP%:5555 > nul 2>&1
%ADB% shell getprop > %P%\getprop.txt 2>&1
%ADB% shell cat /proc/cpuinfo > %P%\cpuinfo.txt 2>&1
%ADB% shell cat /proc/meminfo > %P%\meminfo.txt 2>&1
%ADB% shell uname -a > %P%\uname.txt 2>&1
%ADB% shell cat /proc/partitions > %P%\partitions.txt 2>&1
%ADB% shell df -h > %P%\df.txt 2>&1
%ADB% shell "for p in /dev/block/by-name/*; do r=$(readlink -f $p); echo $(basename $p) $r $(blockdev --getsize64 $r); done" > %P%\particoes_tamanhos.txt 2>&1
%ADB% shell "for d in /sys/block/mmcblk0/mmcblk0p*; do echo $(basename $d) $(cat $d/start) $(cat $d/size); done" > %P%\offsets.txt 2>&1
%ADB% shell "cat /proc/device-tree/model 2>/dev/null" > %P%\dt_model.txt 2>&1
%ADB% shell pm list packages -f -s > %P%\pacotes_sistema.txt 2>&1
%ADB% shell pm list packages -f -3 > %P%\pacotes_usuario.txt 2>&1
%ADB% shell pm list packages -d > %P%\pacotes_desativados.txt 2>&1
%ADB% shell cmd package resolve-activity --brief -c android.intent.category.HOME -a android.intent.action.MAIN > %P%\home.txt 2>&1
%ADB% shell "id; ls -l /system/xbin/su 2>&1" > %P%\root.txt 2>&1
%ADB% shell "pm list packages | grep -iE 'hotack|htc\.|silentsdk|writesn|eventupload|expandsdk|storeos|otaupdate|master|overseas|abc.ninja'" > %P%\suspeitos.txt 2>&1
echo ===== Resumo =====
findstr /C:"[ro.product.device]" /C:"[ro.board.platform]" /C:"[ro.build.type]" /C:"[ro.build.version.release]" /C:"[service.adb.root]" /C:"[ro.product.vendor.model]" %P%\getprop.txt
echo.
echo Launcher padrao:
type %P%\home.txt
echo.
echo Apps suspeitos encontrados:
type %P%\suspeitos.txt
echo.
echo Arquivos salvos em %P%
pause
