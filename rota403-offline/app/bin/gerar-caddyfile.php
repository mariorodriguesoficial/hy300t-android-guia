<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente linha de comando.'); }
/**
 * Gera o Caddyfile do servidor web do Rota 403 (Windows e Linux).
 *   - HTTP em 127.0.0.1:<porta-http>  (uso no próprio PC)
 *   - opcional: HTTPS na LAN (--lan=1) com CA local, só IPs de rede privada
 *   - PHP via FastCGI em um POOL de php-cgi (cada php-cgi atende 1 requisição por vez; o pool dá concorrência)
 * Uso: php bin/gerar-caddyfile.php --raiz=DIR_DO_APP --dados=DIR_DE_DADOS [--porta-http=8080] [--cgi=9001,9002,9003,9004]
 *        [--lan=1 --porta-https=8443 --ips=a,b --nome=host.local] [--saida=ARQUIVO]
 */
$o = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $o[$m[1]] = $m[2];
$norm = fn(string $p) => rtrim(str_replace('\\', '/', $p), '/');
$raiz = $norm($o['raiz'] ?? ''); $dados = $norm($o['dados'] ?? '');
if ($raiz === '' || $dados === '') { fwrite(STDERR, "Informe --raiz e --dados\n"); exit(2); }
$pHttp = (int) ($o['porta-http'] ?? 8080); $pTls = (int) ($o['porta-https'] ?? 8443);
$cgi = array_map('intval', explode(',', $o['cgi'] ?? '9001,9002,9003,9004'));
foreach ([$pHttp, $pTls, ...$cgi] as $p) if ($p < 1024 || $p > 65535) { fwrite(STDERR, "Porta inválida: $p\n"); exit(2); }
$fcgi = implode(' ', array_map(fn($p) => "127.0.0.1:$p", $cgi));

function ipsPrivados(): array
{
    $r = [];
    foreach (net_get_interfaces() as $i) foreach (($i['unicast'] ?? []) as $u) {
        $ip = $u['address'] ?? ''; if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
        if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) $r[] = $ip;   // nunca IP público
    }
    return array_values(array_unique($r));
}
$lan = ($o['lan'] ?? '0') === '1'; $sites = [];
if ($lan) {
    $ips = isset($o['ips']) ? array_filter(array_map('trim', explode(',', $o['ips']))) : ipsPrivados();
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|127\.)/', $ip)) { fwrite(STDERR, "IP não permitido (só rede privada): $ip\n"); exit(2); }
    if (!$ips) { fwrite(STDERR, "Nenhum IP de rede privada encontrado.\n"); exit(1); }
    foreach ($ips as $ip) $sites[] = "https://$ip:$pTls";
    if (!empty($o['nome']) && preg_match('/^[a-z0-9.-]+$/i', $o['nome'])) $sites[] = 'https://' . $o['nome'] . ':' . $pTls;
}
$ca = $dados . '/caddy-data/pki/authorities/local';
$global = "\tadmin off\n\tauto_https disable_redirects\n\tstorage file_system \"{$dados}/caddy-data\"\n" . ($lan ? "\tlocal_certs\n\tskip_install_trust\n" : '');
$app = <<<APP
(app) {
	root * "{$raiz}"
	encode gzip
	# Nada de configuração, código interno, migrações, dados ou scripts acessível pela web
	@bloqueado path /config/* /includes/* /storage/* /migrations/* /bin/* /tests/*
	respond @bloqueado "Acesso negado" 403
	@sensivel path_regexp \.(sql|md|txt|log|pem|key|ini|bak|bat|ps1)\$
	respond @sensivel "Acesso negado" 403
	@phpemupload path /uploads/*.php /assets/*.php
	respond @phpemupload "Acesso negado" 403
	php_fastcgi {$fcgi} {
		try_files {path} {path}.php index.php
	}
	file_server
}
APP;
// bind: sem isto o Caddy escuta em TODAS as interfaces (o endereço do site só casa o host, não limita o listener)
$blocos = "http://127.0.0.1:{$pHttp} {\n\tbind 127.0.0.1\n\timport app\n}\n";
if ($lan) $blocos .= implode(', ', $sites) . " {\n\ttls internal\n\t# Só o certificado PÚBLICO da CA local, para instalar no celular\n\thandle /rota403-ca.crt {\n\t\troot * {$ca}\n\t\trewrite * /root.crt\n\t\theader Content-Disposition \"attachment; filename=rota403-ca.crt\"\n\t\tfile_server\n\t}\n\thandle {\n\t\timport app\n\t}\n}\n";
$conf = "# Gerado por bin/gerar-caddyfile.php — não edite à mão.\n{\n{$global}}\n\n{$app}\n\n{$blocos}";
$saida = $o['saida'] ?? ($dados . '/Caddyfile'); @mkdir(dirname($saida), 0700, true);
file_put_contents($saida, $conf);
echo "Caddyfile: $saida\nHTTP local: http://127.0.0.1:$pHttp\n"; foreach ($sites as $s) echo "LAN: $s\n";
