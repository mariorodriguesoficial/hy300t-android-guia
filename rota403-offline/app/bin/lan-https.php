<?php
// Gera o Caddyfile do acesso HTTPS pela LAN (celulares). O app PHP continua em 127.0.0.1; só o Caddy escuta na rede.
// Uso: php bin/lan-https.php --dados=DIR [--porta-app=8080] [--porta-https=8443] [--ips=192.168.0.10,...] [--nome=rota403.local]
$o = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $o[$m[1]] = $m[2];
$dados = rtrim($o['dados'] ?? '', '/\\'); if ($dados === '') { fwrite(STDERR, "Informe --dados=DIR\n"); exit(2); }
$pApp = (int) ($o['porta-app'] ?? 8080); $pTls = (int) ($o['porta-https'] ?? 8443);

function ipsPrivados(): array
{
    $r = [];
    foreach (net_get_interfaces() as $nome => $i) foreach (($i['unicast'] ?? []) as $u) {
        $ip = $u['address'] ?? ''; if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
        if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) $r[] = $ip;   // só redes privadas; nunca IP público
    }
    return array_values(array_unique($r));
}
$ips = isset($o['ips']) ? array_filter(array_map('trim', explode(',', $o['ips']))) : ipsPrivados();
foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { fwrite(STDERR, "IP inválido: $ip\n"); exit(2); }
if (!$ips) { fwrite(STDERR, "Nenhum IP de rede privada encontrado. Conecte ao Wi-Fi/rede ou use --ips=.\n"); exit(1); }
$sites = array_map(fn($ip) => "https://$ip:$pTls", $ips);
if (!empty($o['nome']) && preg_match('/^[a-z0-9.-]+$/i', $o['nome'])) $sites[] = 'https://' . $o['nome'] . ':' . $pTls;
$ca = $dados . '/caddy-data/pki/authorities/local';
$conf = <<<CADDY
# Gerado por bin/lan-https.php — não edite à mão.
{
	local_certs
	skip_install_trust
	auto_https disable_redirects
	admin off
	storage file_system {$dados}/caddy-data
}
%SITES% {
	tls internal
	# Certificado raiz público para instalar no celular (NUNCA a chave privada)
	handle /rota403-ca.crt {
		root * {$ca}
		rewrite * /root.crt
		header Content-Disposition "attachment; filename=rota403-ca.crt"
		file_server
	}
	handle {
		reverse_proxy 127.0.0.1:{$pApp}
	}
}

CADDY;
$conf = str_replace('%SITES%', implode(', ', $sites), $conf);
@mkdir($dados, 0700, true);
file_put_contents($dados . '/Caddyfile', $conf);
echo "Caddyfile gravado em $dados/Caddyfile\nEndereços no celular:\n"; foreach ($sites as $s) echo "  $s\n";
echo "Certificado raiz para instalar no Android: " . $sites[0] . "/rota403-ca.crt\n";
