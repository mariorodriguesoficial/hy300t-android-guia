@echo off
rem ============================================================
rem  CONFIGURACAO - edite apenas a linha abaixo com o IP do projetor
rem  (Configuracoes > Rede no projetor)
rem ============================================================
set PROJ_IP=192.168.0.50

set ADB=platform-tools\adb.exe -s %PROJ_IP%:5555
if not exist logs mkdir logs
if not exist logs\telas mkdir logs\telas
