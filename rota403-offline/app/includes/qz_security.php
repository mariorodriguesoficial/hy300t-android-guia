<?php
// Identidade de assinatura exclusiva. Não confiar em X-Forwarded-Host/Proto do cliente.
function qz_url_autorizada(string $url): bool
{
    $p = parse_url($url);
    if (!$p || isset($p['user']) || isset($p['pass'])) return false;
    $path = $p['path'] ?? '/';
    return ($p['scheme'] ?? '') === 'https'
        && strtolower($p['host'] ?? '') === 'mrinformatica.inf.br'
        && (!isset($p['port']) || $p['port'] === 443)
        && ($path === '/rota403' || str_starts_with($path, '/rota403/'))
        && !preg_match('~(?:%2e|%2f|%5c|\\\\|/\.\.?(/|$))~i', $path);
}

function qz_contexto_autorizado(array $s, string $method): bool
{
    // Hostinger (LiteSpeed/proxy) nem sempre preenche HTTPS=on
    $https = in_array(strtolower((string) ($s['HTTPS'] ?? '')), ['on', '1'], true)
        || (string) ($s['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($s['REQUEST_SCHEME'] ?? '')) === 'https'
        || strtolower((string) ($s['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if (!$https) return false;
    if (($s['REQUEST_METHOD'] ?? '') !== $method) return false;
    if (strtolower(preg_replace('/:443$/', '', (string) ($s['HTTP_HOST'] ?? ''))) !== 'mrinformatica.inf.br') return false;
    $ref = (string) ($s['HTTP_REFERER'] ?? '');
    if ($ref !== '' && !qz_url_autorizada($ref)) return false;
    $origin = $s['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && !in_array($origin, ['https://mrinformatica.inf.br', 'https://mrinformatica.inf.br:443'], true)) return false;
    if (isset($s['HTTP_SEC_FETCH_SITE']) && $s['HTTP_SEC_FETCH_SITE'] !== 'same-origin') return false;
    return true;
}

function qz_exigir_contexto(string $method): void
{
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');
    if (!qz_contexto_autorizado($_SERVER, $method)) {
        http_response_code(403);
        exit('Impressão autorizada somente no sistema https://mrinformatica.inf.br/rota403.');
    }
}

function qz_requisicao_valida(string $request): bool
{
    // QZ 2.2 envia o resumo SHA256 hexadecimal, não o JSON da operação.
    return $request !== '' && strlen($request) <= 65536;
}