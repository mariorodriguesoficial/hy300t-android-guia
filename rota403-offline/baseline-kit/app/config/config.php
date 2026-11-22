<?php
/**
 * Rota 403 - configuração Windows Offline.
 * O arquivo config.local.php contém as credenciais locais e é criado pelo instalador.
 */
define('EMPRESA_NOME', 'ROTA 403');
define('EMPRESA_SUB', 'Espetinhos • Porções • Bebidas • Direto da Fazenda');

define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', ''); // instalado na raiz do servidor local

$localConfig = __DIR__ . '/config.local.php';
if (!is_file($localConfig)) {
    http_response_code(500);
    exit('Configuração local ausente. Execute o instalador do Rota 403.');
}
$local = require $localConfig;
foreach (['DB_HOST','DB_PORT','DB_USER','DB_PASS','DB_NAME'] as $key) {
    if (!array_key_exists($key, $local)) {
        http_response_code(500);
        exit('Configuração local inválida: ' . $key);
    }
    if (!defined($key)) define($key, $local[$key]);
}

date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/php-error.log');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => false
    ]);
    session_start();
}

require_once BASE_PATH . '/includes/Database.php';
require_once BASE_PATH . '/includes/helpers.php';
require_once BASE_PATH . '/includes/Auth.php';
