<?php
// GET /api/sync/v1/pull?cursor=N&limite=M  — o próprio cursor enviado confirma o recebimento até N.
require_once __DIR__ . '/../../../config/config.php';
[$dev] = SyncServer::autenticar('pull', 'GET');
$cursor = max(0, (int) ($_GET['cursor'] ?? 0)); $lim = min(Sync::MAX_EVENTOS, max(1, (int) ($_GET['limite'] ?? 100)));
$rows = Database::all('SELECT * FROM sync_outbox WHERE seq > ? AND (via IS NULL OR via <> ?) AND estado <> ? ORDER BY seq LIMIT ' . $lim, [$cursor, $dev, 'rejeitado']);
$grupos = [];
foreach ($rows as $r) { $k = $r['tabela'] . '|' . $r['uuid']; $f = ($grupos[$k]['forcar'] ?? 0) | (int) $r['forcar']; unset($grupos[$k]); $grupos[$k] = $r; $grupos[$k]['forcar'] = $f; }
$eventos = [];
foreach ($grupos as $g) {
    try { $e = Sync::construirEvento($g); $e['seq'] = (int) $g['seq']; $eventos[] = $e; }
    catch (Throwable $t) { /* movimento/linha sumiu: ignora */ }
}
$prox = $rows ? (int) end($rows)['seq'] : $cursor;
SyncServer::log($dev, 'pull', 200, count($eventos));
SyncServer::resp(['ok' => true, 'eventos' => $eventos, 'proximo_cursor' => $prox, 'tem_mais' => count($rows) === $lim, 'servidor' => Sync::deviceId()]);
