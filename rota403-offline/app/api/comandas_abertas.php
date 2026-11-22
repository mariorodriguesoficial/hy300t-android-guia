<?php
// Assinatura das comandas abertas: as telas recarregam sozinhas quando uma comanda é criada, paga ou alterada
require_once __DIR__ . '/../config/config.php';
Auth::exigir(['caixa', 'garcom'], true);
garantirPagamentos();
$rows = Database::all("SELECT c.id, c.total, (SELECT COALESCE(SUM(p.valor),0) FROM comanda_pagamentos p WHERE p.comanda_id = c.id) pago FROM comandas c WHERE c.status = 'aberta' ORDER BY c.id");
json_out(['assinatura' => md5(json_encode($rows)), 'ids' => array_map(fn($r) => (int) $r['id'], $rows)]);
