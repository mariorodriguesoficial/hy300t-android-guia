<?php
// POST /api/sync/v1/push  — corpo: {"eventos":[...]}  → resposta por evento (também é o ACK do push)
require_once __DIR__ . '/../../../config/config.php';
[$dev, $body] = SyncServer::autenticar('push', 'POST');
$in = json_decode($body, true);
if (!is_array($in) || !is_array($in['eventos'] ?? null)) SyncServer::falhar($dev, 'push', 400, 'JSON inválido');
$ev = $in['eventos'];
if (count($ev) > Sync::MAX_EVENTOS) SyncServer::falhar($dev, 'push', 413, 'Eventos demais no lote');
$res = [];
foreach ($ev as $e) {
    if (!is_array($e)) { $res[] = ['event_id' => null, 'status' => 'rejeitado', 'detalhe' => 'formato']; continue; }
    $r = Sync::aplicar($e, $dev);   // a origem do evento é o dispositivo AUTENTICADO, nunca o declarado
    $res[] = ['event_id' => $e['event_id'] ?? null] + $r;
}
SyncServer::log($dev, 'push', 200, count($ev));
SyncServer::resp(['ok' => true, 'resultados' => $res, 'servidor' => Sync::deviceId()]);
