<?php
// GET: lista pedidos dos destinos • POST: reserva ou libera impressão
require_once __DIR__ . '/../config/config.php';
Auth::exigir(['caixa'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int) ($in['id'] ?? 0);
    $acao = $in['acao'] ?? 'reservar';
    if ($acao === 'liberar') {
        Database::query("UPDATE pedidos p JOIN comandas c ON c.id=p.comanda_id SET p.impresso=0 WHERE p.id=? AND c.status='aberta'", [$id]);
        json_out(['sucesso' => true]);
    }
    $st = Database::query("UPDATE pedidos p JOIN comandas c ON c.id=p.comanda_id SET p.impresso=1 WHERE p.id=? AND p.impresso=0 AND c.status='aberta'", [$id]);
    if ($st->rowCount() !== 1) json_out(['erro' => 'Pedido já assumido por outro monitor ou comanda fechada.'], 409);
    json_out(['sucesso' => true, 'reservado' => true]);
}

$dest = array_values(array_intersect(explode(',', $_GET['destinos'] ?? ''), ['cozinha', 'balcao']));
if (!$dest) json_out([]);
$ph = implode(',', array_fill(0, count($dest), '?'));
$rows = Database::all("SELECT p.*, c.tipo, c.identificacao FROM pedidos p JOIN comandas c ON c.id = p.comanda_id
    WHERE c.status = 'aberta' AND p.destino IN ($ph) AND (p.impresso = 0 OR p.criado_em >= (NOW() - INTERVAL 12 HOUR))
    ORDER BY p.impresso ASC, CASE WHEN p.impresso=0 THEN p.id END ASC, p.id DESC LIMIT 200", $dest);
json_out(array_map(fn($r) => [
    'id' => (int) $r['id'], 'comanda_id' => (int) $r['comanda_id'], 'destino' => $r['destino'], 'impresso' => (int) $r['impresso'],
    'garcom' => $r['garcom'], 'hora' => dataHora($r['criado_em']), 'comanda' => nomeComanda($r), 'itens' => json_decode($r['itens'], true) ?: [],
], $rows));
