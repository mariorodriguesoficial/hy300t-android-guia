<?php
// Assina cada impressão enviada ao QZ Tray com a chave privada do sistema
require_once __DIR__ . '/../includes/qz_security.php';
qz_exigir_contexto('POST');
require_once __DIR__ . '/../config/config.php';
Auth::exigir(['caixa'], true);
check_csrf();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$req = (string) ($in['request'] ?? '');
if (!qz_requisicao_valida($req)) { http_response_code(400); exit('Requisição de impressão inválida ou expirada.'); }
if (!function_exists('openssl_sign')) { http_response_code(500); exit('Extensão OpenSSL do PHP desativada na hospedagem'); }
$chave = openssl_pkey_get_private(@file_get_contents(__DIR__ . '/../config/qz-private-key.pem'));
if (!$chave) { http_response_code(500); exit('Chave não encontrada em config/qz-private-key.pem'); }
if (!openssl_sign($req, $assinatura, $chave, OPENSSL_ALGO_SHA512)) { http_response_code(500); exit('Falha ao assinar'); }
echo base64_encode($assinatura);
