<?php
// ============================================================
// Rota 403 - Configuração por ambiente.
// Segredos ficam em config.local.php (fora do Git, criado pelo instalador).
// Online (Hostinger): config.local.php pode omitir BASE_URL e usar '/rota403'.
// ============================================================
$__local = __DIR__ . '/config.local.php';
if (!is_file($__local)) {
    http_response_code(500);
    exit('Configuração local ausente (config/config.local.php).');
}
$__cfg = require $__local;
foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $__k) {
    if (!array_key_exists($__k, $__cfg)) { http_response_code(500); exit('Configuração inválida: ' . $__k); }
}
define('APP_ENV', $__cfg['APP_ENV'] ?? 'local');
define('DB_HOST', $__cfg['DB_HOST']);
define('DB_PORT', (string) ($__cfg['DB_PORT'] ?? '3306'));
define('DB_USER', $__cfg['DB_USER']);
define('DB_PASS', $__cfg['DB_PASS']);
define('DB_NAME', $__cfg['DB_NAME']);
define('EMPRESA_NOME', $__cfg['EMPRESA_NOME'] ?? 'ROTA 403');
define('EMPRESA_SUB', $__cfg['EMPRESA_SUB'] ?? 'Espetinhos • Porções • Bebidas • Direto da Fazenda');
define('BASE_URL', $__cfg['BASE_URL'] ?? '');   // sem barra final
define('BASE_PATH', dirname(__DIR__));
define('DATA_DIR', $__cfg['DATA_DIR'] ?? BASE_PATH . '/storage'); // logs/backups fora da pasta do app no Windows
define('COOKIE_SECURE', (bool) ($__cfg['COOKIE_SECURE'] ?? false));
// Sincronização (opcional). Segredo NUNCA no Git.
foreach (['SYNC_ENABLED' => false, 'SYNC_API_URL' => '', 'SYNC_DEVICE_ID' => '', 'SYNC_SECRET' => '', 'SYNC_REQUIRE_HTTPS' => true, 'SYNC_CA_BUNDLE' => '', 'SYNC_CLOCK_SKEW' => 0] as $__k => $__d)
    if (!defined($__k)) define($__k, $__cfg[$__k] ?? $__d);
unset($__local, $__cfg, $__k, $__d);

date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(DATA_DIR) && is_writable(DATA_DIR)) ini_set('error_log', DATA_DIR . '/php-error.log');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['path' => BASE_URL . '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => COOKIE_SECURE || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1')]);   // atrás do Caddy (HTTPS na LAN)
    session_start();
}

require_once BASE_PATH . '/includes/Database.php';
require_once BASE_PATH . '/includes/Migrator.php';
require_once BASE_PATH . '/includes/Sync.php';
require_once BASE_PATH . '/includes/SyncServer.php';
require_once BASE_PATH . '/includes/helpers.php';
require_once BASE_PATH . '/includes/Auth.php';
