<?php
require_once __DIR__ . '/config/config.php';
Auth::exigir(['__admin__']);

$num = fn($v) => (float) str_replace(',', '.', (string) $v);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['acao'] ?? '';
    garantirUnidade();
    try {
        if ($a === 'salvar') {
            $d = [trim($_POST['nome']), $num($_POST['preco']), trim($_POST['codigo_barras'] ?? '') ?: null, round($num($_POST['estoque'] ?? 0), 3),
                ($_POST['categoria_id'] ?? '') !== '' ? (int) $_POST['categoria_id'] : null, (array_key_exists($_POST['destino'] ?? '', listarPreparos()) ? $_POST['destino'] : array_key_first(listarPreparos())),
                isset($_POST['imprimir_na_comanda']) ? 1 : 0, isset($_POST['controlar_estoque']) ? 1 : 0, isset($_POST['ativo']) ? 1 : 0, (($_POST['unidade'] ?? 'un') === 'kg' ? 'kg' : 'un')];
            if (!empty($_POST['id'])) {
                $idFoto = (int) $_POST['id'];
                $d[] = $idFoto;
                Database::query('UPDATE produtos SET nome=?, preco=?, codigo_barras=?, estoque=?, categoria_id=?, destino=?, imprimir_na_comanda=?, controlar_estoque=?, ativo=?, unidade=? WHERE id=?', $d);
                flash('Produto atualizado.');
            } else {
                $idFoto = Database::insert('INSERT INTO produtos (nome, preco, codigo_barras, estoque, categoria_id, destino, imprimir_na_comanda, controlar_estoque, ativo, unidade) VALUES (?,?,?,?,?,?,?,?,?,?)', $d);
                flash('Produto cadastrado.');
            }
            salvarFotoProduto($idFoto);
        } elseif ($a === 'toggle') {
            $campo = $_POST['campo'] === 'ativo' ? 'ativo' : 'imprimir_na_comanda';
            Database::query("UPDATE produtos SET $campo = 1 - $campo WHERE id = ?", [(int) $_POST['id']]);
            redirect('produtos.php');
        } elseif ($a === 'excluir') {
            $id = (int) $_POST['id'];
            if (Database::val('SELECT COUNT(*) FROM comanda_itens WHERE produto_id=?', [$id])) {
                Database::query('UPDATE produtos SET ativo = 0 WHERE id=?', [$id]);
                flash('Produto já vendido — foi desativado.', 'warning');
            } else { Database::query('DELETE FROM produtos WHERE id=?', [$id]); flash('Produto excluído.'); }
        }
    } catch (PDOException $e) {
        flash(str_contains($e->getMessage(), 'Duplicate') ? 'Já existe um produto com esse código.' : 'Erro ao salvar.', 'danger');
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('produtos.php');
}

garantirUnidade();
$produtos = listarProdutos();
$categoriasEstoque = Database::all('SELECT id, nome FROM categorias ORDER BY ordem, nome');
$titulo = 'Estoque';
require __DIR__ . '/includes/header.php';
?>
<h1>Estoque e produtos</h1>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a class="btn btn-ember" href="<?= url('produto_form.php') ?>">Novo produto</a>
  <div class="flex-grow-1" style="min-width:220px;max-width:420px">
    <input type="search" id="buscaProduto" class="form-control" placeholder="Buscar por nome, código ou grupo..." aria-label="Buscar produto por nome, código ou grupo">
  </div>
</div>
<div class="abas-estoque mb-3" role="tablist" aria-label="Categorias do estoque">
  <button type="button" class="btn btn-outline-primary active" role="tab" id="categoria-todos" aria-controls="painel-estoque" aria-selected="true" data-categoria="todos">Todos os produtos</button>
  <?php foreach ($categoriasEstoque as $categoria): ?>
    <button type="button" class="btn btn-outline-primary" role="tab" id="categoria-<?= (int) $categoria['id'] ?>" aria-controls="painel-estoque" aria-selected="false" tabindex="-1" data-categoria="<?= (int) $categoria['id'] ?>"><?= e($categoria['nome']) ?></button>
  <?php endforeach; ?>
</div>
<div class="card-r p-0 table-responsive" id="painel-estoque" role="tabpanel" aria-labelledby="categoria-todos">
  <table class="table mb-0 align-middle">
    <thead><tr><th class="text-center">Na comanda impressa</th><th>Produto</th><th>Categoria</th><th>Código</th><th>Preço</th><th>Estoque</th><th>Preparo</th><th>Ativo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($produtos as $x): ?>
      <tr data-produto-categoria="<?= (int) ($x['categoria_id'] ?? 0) ?>" data-busca="<?= e(mb_strtolower($x['nome'] . ' ' . ($x['codigo_barras'] ?? '') . ' ' . ($x['categoria'] ?? ''))) ?>" class="<?= $x['ativo'] ? '' : 'opacity-50' ?>">
        <td class="text-center"><form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="toggle"><input type="hidden" name="campo" value="imprimir"><input type="hidden" name="id" value="<?= $x['id'] ?>"><input type="checkbox" class="form-check-input fs-5" data-confirmar-toggle="imprimir" data-nome="<?= e($x['nome']) ?>" <?= $x['imprimir_na_comanda'] ? 'checked' : '' ?>></form></td>
        <td><div class="produto-cadastro"><img class="produto-miniatura" src="<?= e(fotoProduto($x)) ?>" alt="<?= e($x['nome']) ?>" width="56" height="56" loading="lazy"><span class="fw-semibold"><?= e($x['nome']) ?></span></div></td><td><?= e($x['categoria']) ?></td><td><?= e($x['codigo_barras'] ?? '-') ?></td>
        <td><?= brl($x['preco']) ?><?= ($x['unidade'] ?? 'un') === 'kg' ? ' /kg' : '' ?></td>
        <td class="<?= $x['controlar_estoque'] && $x['estoque'] <= 5 ? 'text-danger fw-bold' : '' ?>"><?= $x['controlar_estoque'] ? e(qtdFmt($x['estoque'], $x['unidade'] ?? null)) : '—' ?></td>
        <td><span class="badge badge-preparo badge-<?= e($x['destino']) ?>"><?= e(nomePreparo($x['destino'])) ?></span></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="toggle"><input type="hidden" name="campo" value="ativo"><input type="hidden" name="id" value="<?= $x['id'] ?>"><input type="checkbox" class="form-check-input" data-confirmar-toggle="ativo" data-nome="<?= e($x['nome']) ?>" <?= $x['ativo'] ? 'checked' : '' ?>></form></td>
        <td class="text-nowrap"><a class="btn btn-sm btn-link" href="<?= url('produto_form.php?editar=' . $x['id']) ?>"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" data-confirm="Deseja realmente excluir o produto <?= e($x['nome']) ?>?"><?= csrf_field() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-sm btn-link text-danger"><i class="bi bi-trash"></i></button></form></td>
      </tr>
    <?php endforeach; ?>
    <tr id="estoque-vazio" hidden><td colspan="9" class="text-center muted py-4">Nenhum produto encontrado.</td></tr>
    </tbody>
  </table>
</div>
<p class="small muted mt-2">Somente os produtos marcados em “Na comanda impressa” aparecem no papel entregue à mesa.</p>
<?php $scripts = '<script src="' . e(url('assets/estoque.js?v=8')) . '"></script>';
require __DIR__ . '/includes/footer.php';
