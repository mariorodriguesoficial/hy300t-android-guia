<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente linha de comando.'); }
// Uso: php bin/sync-admin.php semear | device-add "Nome" | device-revoke <id> | device-list | conflitos | id-inicial <N>
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/includes/SyncServer.php';
$cmd = $argv[1] ?? '';
switch ($cmd) {
    case 'semear': print_r(Sync::semear()); break;
    case 'device-add':
        $d = SyncServer::novoDispositivo($argv[2] ?? 'dispositivo');
        echo "device_id: {$d['device_id']}\nsecret (mostrado UMA vez, guarde no config.local.php do dispositivo): {$d['secret']}\n"; break;
    case 'device-revoke': Database::query('UPDATE sync_devices SET revogado_utc = UTC_TIMESTAMP() WHERE device_id=?', [$argv[2] ?? '']); echo "Revogado.\n"; break;
    case 'device-list': foreach (Database::all('SELECT device_id, nome, criado_utc, revogado_utc, ultimo_acesso_utc FROM sync_devices') as $r) echo implode(' | ', array_map('strval', $r)), "\n"; break;
    case 'conflitos': foreach (Database::all("SELECT id, tabela, tipo, campos, criado_utc FROM sync_conflicts WHERE estado='aberto' ORDER BY id") as $r) echo implode(' | ', array_map('strval', $r)), "\n"; break;
    case 'id-inicial': // evita colisão de nº de comanda entre ambientes (ex.: local começa em 500000)
        $n = max(1, (int) ($argv[2] ?? 0)); $m = (int) Database::val('SELECT COALESCE(MAX(id),0) FROM comandas');
        if ($n <= $m) { fwrite(STDERR, "Já existem comandas até $m.\n"); exit(1); }
        Database::pdo()->exec('ALTER TABLE comandas AUTO_INCREMENT = ' . $n); echo "Próxima comanda: $n\n"; break;
    default: fwrite(STDERR, "Comando inválido.\n"); exit(2);
}
