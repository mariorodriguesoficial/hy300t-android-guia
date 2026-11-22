<?php
require_once __DIR__ . '/../config/config.php';
Auth::exigir(['__admin__'], true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'Método inválido'], 405);
check_csrf();
$acao = (string) ($_POST['acao'] ?? 'criar');
$nome = trim((string) ($_POST['nome'] ?? ''));
$id = (int) ($_POST['id'] ?? 0);
try {
    if ($acao === 'renomear') {
        if ($id <= 0) json_out(['erro' => 'Selecione a categoria que deseja editar.'], 400);
        if ($nome === '' || mb_strlen($nome) > 60) json_out(['erro' => 'Informe o novo nome da categoria (até 60 caracteres).'], 400);
        $categoria = Database::one('SELECT id, nome FROM categorias WHERE id = ? LIMIT 1', [$id]);
        if (!$categoria) json_out(['erro' => 'Categoria não encontrada.'], 404);
        $igual = Database::one('SELECT id FROM categorias WHERE nome = ? AND id <> ? LIMIT 1', [$nome, $id]);
        if ($igual) json_out(['erro' => 'Já existe outra categoria com esse nome.'], 400);
        Database::query('UPDATE categorias SET nome = ? WHERE id = ?', [$nome, $id]);
        json_out(['categoria' => ['id' => $id, 'nome' => $nome]]);
    }
    if ($acao === 'excluir') {
        if ($id <= 0) json_out(['erro' => 'Selecione a categoria que deseja excluir.'], 400);
        $categoria = Database::one('SELECT id, nome FROM categorias WHERE id = ? LIMIT 1', [$id]);
        if (!$categoria) json_out(['erro' => 'Categoria não encontrada.'], 404);
        $emUso = (int) Database::val('SELECT COUNT(*) FROM produtos WHERE categoria_id = ?', [$id]);
        if ($emUso > 0) json_out(['erro' => 'Esta categoria tem ' . $emUso . ' produto(s). Mova os produtos para outra categoria antes de excluir.'], 400);
        Database::query('DELETE FROM categorias WHERE id = ?', [$id]);
        json_out(['excluida' => $id]);
    }
    if ($nome === '' || mb_strlen($nome) > 60) json_out(['erro' => 'Informe uma categoria com até 60 caracteres.'], 400);
    $categoria = Database::one('SELECT id, nome FROM categorias WHERE nome = ? LIMIT 1', [$nome]);
    if (!$categoria) {
        $id = Database::insert('INSERT INTO categorias (nome, ordem) VALUES (?, (SELECT COALESCE(MAX(c.ordem),0)+1 FROM categorias c))', [$nome]);
        $categoria = ['id' => $id, 'nome' => $nome];
    }
    json_out(['categoria' => $categoria]);
} catch (Throwable $e) {
    json_out(['erro' => 'Não foi possível concluir. Tente novamente.'], 500);
}
