@echo off
rem Testa a rede ate o projetor (somente leitura).
cd /d "%~dp0.."
call scripts\config.bat
set LOG=logs\01_diagnostico_rede.txt
echo ==== %date% %time% ==== > %LOG%
echo [ipconfig] >> %LOG%
ipconfig >> %LOG%
echo [ping] >> %LOG%
ping -n 3 %PROJ_IP% >> %LOG%
echo [arp] >> %LOG%
arp -a | findstr %PROJ_IP% >> %LOG%
echo [porta 5555] >> %LOG%
powershell -NoProfile -Command "$r=Test-NetConnection %PROJ_IP% -Port 5555 -WarningAction SilentlyContinue; '5555 -> ' + $r.TcpTestSucceeded" >> %LOG% 2>&1
type %LOG%
pause
