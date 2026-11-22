<?php
require_once __DIR__ . '/../config/config.php';
Auth::exigir(['caixa', 'garcom'], true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'Método inválido'], 405);
check_csrf();
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$itens = is_array($in['itens'] ?? null) ? $in['itens'] : [];
if (!$itens) json_out(['erro' => 'Nenhum item informado.'], 400);
try {
    $eu = Auth::user();
    politica_comanda($eu, (int) ($in['comanda_id'] ?? 0), true);
    if (count($itens) > 200) json_out(['erro' => 'Itens demais em um único pedido.'], 400);
    // O nome de quem lançou vem da sessão, nunca do navegador (evita falsificação)
    $chave = isset($in['chave']) && is_string($in['chave']) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $in['chave']) ? $in['chave'] : null;
    lancarItens((int) ($in['comanda_id'] ?? 0), $itens, mb_substr($eu['nome'], 0, 100), true, $chave);
    json_out(['sucesso' => true]);
} catch (RuntimeException $e) {
    json_out(['erro' => $e->getMessage()], 400);
} catch (Throwable $e) {
    json_out(['erro' => 'Erro ao lançar itens.'], 500);
}
