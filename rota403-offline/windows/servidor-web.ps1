<# Supervisor do servidor web (rodado como serviço pelo WinSW): sobe um pool de php-cgi + Caddy e reinicia quem cair. #>
param([string]$Raiz = (Split-Path -Parent $PSScriptRoot), [string]$Dados = (Join-Path $env:ProgramData 'Rota403'), [int[]]$Portas = @(9001, 9002, 9003, 9004))
. "$PSScriptRoot\lib.ps1"
$C = Get-Caminhos $Raiz $Dados
$log = Join-Path $C.Logs 'web.log'
$phpcgi = Join-Path $C.Php 'php-cgi.exe'; $ini = Join-Path $C.Php 'php.ini'
$caddy = Join-Path $C.Caddy 'caddy.exe'; $caddyfile = Join-Path $C.Dados 'Caddyfile'
$filhos = @{}
function Iniciar-Filho([string]$Nome, [string]$Exe, [string[]]$Argumentos) {
    $p = Start-Process -FilePath $Exe -ArgumentList $Argumentos -WorkingDirectory $C.App -WindowStyle Hidden -PassThru
    $filhos[$Nome] = @{ Proc = $p; Exe = $Exe; Args = $Argumentos }
    Escrever-Log "iniciado $Nome (pid $($p.Id))" $log
}
try {
    $env:PHP_FCGI_MAX_REQUESTS = '500'; $env:PHPRC = $C.Php
    foreach ($p in $Portas) { Iniciar-Filho "php-cgi:$p" $phpcgi @('-c', "`"$ini`"", '-b', "127.0.0.1:$p") }
    Iniciar-Filho 'caddy' $caddy @('run', '--config', "`"$caddyfile`"", '--adapter', 'caddyfile')
    while ($true) {
        Start-Sleep -Seconds 5
        foreach ($k in @($filhos.Keys)) {
            $f = $filhos[$k]
            if ($f.Proc.HasExited) { Escrever-Log "$k terminou (código $($f.Proc.ExitCode)); reiniciando" $log; Iniciar-Filho $k $f.Exe $f.Args }
        }
    }
} finally {
    foreach ($k in @($filhos.Keys)) { try { if (-not $filhos[$k].Proc.HasExited) { $filhos[$k].Proc.Kill() } } catch { } }
    Escrever-Log 'servidor web encerrado' $log
}
