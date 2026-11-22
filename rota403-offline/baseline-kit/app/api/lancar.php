<?php
require_once __DIR__ . '/../config/config.php';
Auth::exigir(['caixa', 'garcom'], true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'Método inválido'], 405);
check_csrf();
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$itens = is_array($in['itens'] ?? null) ? $in['itens'] : [];
if (!$itens) json_out(['erro' => 'Nenhum item informado.'], 400);
try {
    lancarItens((int) ($in['comanda_id'] ?? 0), $itens, mb_substr(trim((string) ($in['garcom'] ?? '')), 0, 100));
    json_out(['sucesso' => true]);
} catch (RuntimeException $e) {
    json_out(['erro' => $e->getMessage()], 400);
} catch (Throwable $e) {
    json_out(['erro' => 'Erro ao lançar itens.'], 500);
}
