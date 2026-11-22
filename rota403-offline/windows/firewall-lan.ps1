<#
 Habilita/desabilita o acesso dos celulares (HTTPS) pela rede local. Padrão da instalação: DESABILITADO.
 Regra do Windows Firewall: somente a porta HTTPS, somente perfil de rede PRIVADO, somente sub-rede local. Nunca abre o banco nem a porta HTTP.
#>
param([ValidateSet('Habilitar', 'Desabilitar', 'Status')][string]$Acao = 'Status', [int]$PortaHttps = 8443, [int]$PortaHttp = 8080, [string]$Ips = '',
      [string]$Raiz = (Split-Path -Parent $PSScriptRoot), [string]$Dados = (Join-Path $env:ProgramData 'Rota403'))
. "$PSScriptRoot\lib.ps1"
$C = Get-Caminhos $Raiz $Dados
$nomeRegra = 'Rota403 HTTPS (LAN)'
$php = Join-Path $C.Php 'php.exe'
function Regerar([bool]$ComLan) {
    $a = @('-c', (Join-Path $C.Php 'php.ini'), (Join-Path $C.App 'bin\gerar-caddyfile.php'), "--raiz=$($C.App)", "--dados=$($C.Dados)", "--porta-http=$PortaHttp", "--porta-https=$PortaHttps")
    if ($ComLan) { $a += '--lan=1'; if ($Ips) { $a += "--ips=$Ips" } }
    $null = Executar $php $a 'Caddyfile'
    Restart-Service Rota403Web
}
switch ($Acao) {
    'Habilitar' {
        $privadas = Get-NetConnectionProfile | Where-Object { $_.NetworkCategory -eq 'Private' }
        if (-not $privadas) { Write-Warning 'Nenhuma rede está classificada como PRIVADA. Defina a rede do restaurante como Privada (Configurações > Rede) — a regra só vale nesse perfil.' }
        if (-not (Porta-Livre $PortaHttps) -and -not (Get-NetFirewallRule -DisplayName $nomeRegra -ErrorAction SilentlyContinue)) { throw "Porta $PortaHttps em uso por outro programa." }
        Regerar $true
        Get-NetFirewallRule -DisplayName $nomeRegra -ErrorAction SilentlyContinue | Remove-NetFirewallRule
        New-NetFirewallRule -DisplayName $nomeRegra -Direction Inbound -Action Allow -Protocol TCP -LocalPort $PortaHttps -Profile Private -RemoteAddress LocalSubnet -Program (Join-Path $C.Caddy 'caddy.exe') | Out-Null
        if (-not (Esperar-Porta $PortaHttps 30)) { throw 'O HTTPS não subiu. Veja logs\web.log.' }
        Write-Host 'Acesso por celular HABILITADO. No celular (mesma Wi-Fi):'
        Get-Content (Join-Path $C.Dados 'Caddyfile') | Select-String '^https://' | ForEach-Object { Write-Host ('  ' + ($_ -replace ' \{.*', '')) }
        Write-Host '  Instale o certificado da CA local:  <endereço acima>/rota403-ca.crt'
    }
    'Desabilitar' {
        Get-NetFirewallRule -DisplayName $nomeRegra -ErrorAction SilentlyContinue | Remove-NetFirewallRule
        Regerar $false
        Write-Host 'Acesso por celular DESABILITADO (regra removida; só o próprio PC acessa).'
    }
    'Status' {
        $r = Get-NetFirewallRule -DisplayName $nomeRegra -ErrorAction SilentlyContinue
        if ($r) { Write-Host ('Regra presente. Habilitada=' + $r.Enabled + ' Perfil=' + $r.Profile) } else { Write-Host 'Regra ausente (LAN desabilitada).' }
    }
}
exit 0
