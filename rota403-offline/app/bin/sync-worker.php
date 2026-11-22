<?php
// Uso: php bin/sync-worker.php [--once] [--lote=N]
// Loop: envia/baixa; sem internet apenas espera (backoff exponencial com jitter). Nunca bloqueia o PDV.
require_once __DIR__ . '/../config/config.php';
require_once BASE_PATH . '/includes/SyncClient.php';
$once = in_array('--once', $argv, true); $lote = Sync::MAX_EVENTOS;
foreach ($argv as $a) if (preg_match('/^--lote=(\d+)$/', $a, $m)) $lote = (int) $m[1];
if (!defined('SYNC_ENABLED') || !SYNC_ENABLED) { fwrite(STDERR, "Sync desativado (SYNC_ENABLED).\n"); exit(0); }
$falhas = 0;
do {
    try { $r = SyncClient::ciclo($lote); } catch (Throwable $e) { $r = ['erro' => $e->getMessage()]; }
    if (!empty($r['erro']) && $r['erro'] !== 'ocupado') { $falhas++; fwrite(STDERR, '[' . date('c') . '] ' . $r['erro'] . "\n"); } else $falhas = 0;
    if ($once) { echo json_encode($r, JSON_UNESCAPED_UNICODE), PHP_EOL; exit(empty($r['erro']) ? 0 : 1); }
    $espera = $falhas ? min(300, (2 ** min($falhas, 8))) : 5;
    sleep($espera + random_int(0, max(1, (int) ($espera / 4))));
} while (true);
