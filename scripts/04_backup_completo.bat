@echo off
rem BACKUP COMPLETO: todas as particoes (menos userdata) + eMMC inteiro, com SHA-256.
rem Somente leitura no projetor. Rode a partir do cmd (duplo clique), nao do PowerShell:
rem o '>' do Windows PowerShell 5.1 corrompe arquivos binarios.
rem Leva cerca de 1 hora. NAO FECHE A JANELA.
cd /d "%~dp0.."
call scripts\config.bat
title BACKUP HY300 - NAO FECHE ESTA JANELA
set OUT=backup\partitions
if not exist %OUT% mkdir %OUT%
set LOG=logs\04_backup.txt
echo ==== INICIO %date% %time% ==== > %LOG%
powershell -NoProfile -Command "$f=(Get-PSDrive C).Free/1GB; 'Livre em C: {0:N1} GB' -f $f; if($f -lt 15){exit 1}" >> %LOG% 2>&1
if errorlevel 1 (echo ABORTADO: menos de 15 GB livres & pause & exit /b 1)
platform-tools\adb.exe connect %PROJ_IP%:5555 >> %LOG% 2>&1
%ADB% shell "for d in /sys/block/mmcblk0/mmcblk0p*; do echo $(basename $d) $(cat $d/start) $(cat $d/size); done" > %OUT%\offsets.txt 2>&1
if exist %OUT%\SHA256_projetor.txt del %OUT%\SHA256_projetor.txt
for /f "tokens=*" %%P in ('%ADB% shell "ls /dev/block/by-name/ | grep -v userdata"') do (
  echo Copiando %%P ...
  echo copiando %%P >> %LOG%
  %ADB% exec-out "dd if=/dev/block/by-name/%%P bs=4M 2>/dev/null" > %OUT%\%%P.img
  %ADB% shell "sha256sum /dev/block/by-name/%%P" >> %OUT%\SHA256_projetor.txt 2>&1
)
for %%P in (mmcblk0boot0 mmcblk0boot1) do (
  echo Copiando %%P ...
  %ADB% exec-out "dd if=/dev/block/%%P bs=4M 2>/dev/null" > %OUT%\%%P.img
  %ADB% shell "sha256sum /dev/block/%%P" >> %OUT%\SHA256_projetor.txt 2>&1
)
echo Copiando o eMMC inteiro (cerca de 7,8 GB) - demora...
echo inicio mmcblk0 %time% >> %LOG%
%ADB% exec-out "dd if=/dev/block/mmcblk0 bs=4M 2>/dev/null" > %OUT%\emmc_full_mmcblk0.img
echo fim mmcblk0 %time% >> %LOG%
echo Conferindo hashes...
powershell -NoProfile -Command ^
 "$dev=@{}; Get-Content '%OUT%\SHA256_projetor.txt' | %% { $p=$_ -split '\s+'; if($p.Count -ge 2){ $dev[[IO.Path]::GetFileName($p[1])]=$p[0].ToLower() } };" ^
 "$out=@(); foreach($k in $dev.Keys){ $f=Join-Path '%OUT%' ($k+'.img'); if(Test-Path $f){ $h=(Get-FileHash $f -Algorithm SHA256).Hash.ToLower(); $s= if($h -eq $dev[$k]){'OK'}else{'DIFERENTE'}; $out+=('{0} {1} {2}' -f $s,$k,(Get-Item $f).Length) } else { $out+=('FALTA '+$k) } };" ^
 "$e=Join-Path '%OUT%' 'emmc_full_mmcblk0.img'; if(Test-Path $e){ $out+=('emmc_full_mmcblk0.img {0} bytes sha256 {1}' -f (Get-Item $e).Length,(Get-FileHash $e -Algorithm SHA256).Hash.ToLower()) };" ^
 "$out | Sort-Object | Set-Content '%OUT%\SHA256SUMS.txt'; $out | Sort-Object"
echo ==== FIM %date% %time% ==== >> %LOG%
echo.
echo BACKUP CONCLUIDO. Todas as linhas devem comecar com OK.
echo Copie a pasta backup para um HD externo e NAO compartilhe (contem chaves do aparelho).
pause
