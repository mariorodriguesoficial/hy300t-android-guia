<?php
// Comandas criadas por garçons aguardando impressão automática no monitor
require_once __DIR__ . '/../config/config.php';
Auth::exigir(['caixa'], true);
garantirColunaImpressa();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($in['id'] ?? 0);
    if (($in['acao'] ?? 'reservar') === 'liberar') {
        Database::query("UPDATE comandas SET impressa=0 WHERE id=? AND status='aberta'", [$id]);
        json_out(['sucesso' => true]);
    }
    $st = Database::query("UPDATE comandas SET impressa=1 WHERE id=? AND impressa=0 AND status='aberta'", [$id]);
    if ($st->rowCount() !== 1) json_out(['erro' => 'Comanda já impressa por outro monitor.'], 409);
    json_out(['sucesso' => true]);
}

$rows = Database::all("SELECT id FROM comandas WHERE impressa = 0 AND status = 'aberta' ORDER BY id LIMIT 20");
json_out(array_map(fn($r) => (int) $r['id'], $rows));
