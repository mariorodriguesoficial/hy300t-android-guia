<?php
// Fila de impressão direta: conta (comanda.php) e comprovante de pagamento (caixa.php).
// Caixa, administrador e garçom enviam; o Monitor de impressão (PC da térmica) imprime sem janela.
require_once __DIR__ . '/../config/config.php';
garantirFilaImpressao();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $acao = $in['acao'] ?? 'reservar';
    $id = (int) ($in['id'] ?? 0);
    if ($acao === 'enviar') {
        Auth::exigir(['caixa', 'garcom'], true);
        check_csrf();
        try { politica_comanda(Auth::user(), $id); } catch (RuntimeException $e) { json_out(['erro' => $e->getMessage()], 403); }
        // Evita duplicar se clicarem várias vezes seguidas
        if (Database::one("SELECT id FROM fila_impressao WHERE tipo='conta' AND ref_id=? AND impresso=0", [$id])) json_out(['sucesso' => true]);
        enfileirarImpressao('conta', $id);
        json_out(['sucesso' => true]);
    }
    Auth::exigir(['caixa'], true);
    check_csrf();
    if ($acao === 'liberar') {
        Database::query('UPDATE fila_impressao SET impresso=0 WHERE id=?', [$id]);
        json_out(['sucesso' => true]);
    }
    $st = Database::query('UPDATE fila_impressao SET impresso=1 WHERE id=? AND impresso=0', [$id]);
    if ($st->rowCount() !== 1) json_out(['erro' => 'Já impresso por outro monitor.'], 409);
    json_out(['sucesso' => true]);
}

Auth::exigir(['caixa'], true);
if (isset($_GET['job'])) {
    $r = Database::one('SELECT impresso FROM fila_impressao WHERE id=?', [(int) $_GET['job']]);
    json_out(['impresso' => $r ? (int) $r['impresso'] === 1 : true]);
}
// Só imprime o que foi enviado nas últimas 12 horas (evita imprimir coisas antigas ao abrir o monitor)
$rows = Database::all("SELECT id, tipo, ref_id FROM fila_impressao WHERE impresso = 0 AND criado_em >= (NOW() - INTERVAL 12 HOUR) ORDER BY id LIMIT 20");
json_out(array_map(fn($r) => ['id' => (int) $r['id'], 'tipo' => $r['tipo'], 'ref_id' => (int) $r['ref_id']], $rows));
