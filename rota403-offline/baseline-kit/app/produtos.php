<?php
require_once __DIR__ . '/config/config.php';
Auth::exigir(['__admin__']);

$num = fn($v) => (float) str_replace(',', '.', (string) $v);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['acao'] ?? '';
    try {
        if ($a === 'salvar') {
            $d = [trim($_POST['nome']), $num($_POST['preco']), trim($_POST['codigo_barras'] ?? '') ?: null, (int) $_POST['estoque'],
                ($_POST['categoria_id'] ?? '') !== '' ? (int) $_POST['categoria_id'] : null, $_POST['destino'] === 'balcao' ? 'balcao' : 'cozinha',
                isset($_POST['imprimir_na_comanda']) ? 1 : 0, isset($_POST['controlar_estoque']) ? 1 : 0, isset($_POST['ativo']) ? 1 : 0];
            if (!empty($_POST['id'])) {
                $d[] = (int) $_POST['id'];
                Database::query('UPDATE produtos SET nome=?, preco=?, codigo_barras=?, estoque=?, categoria_id=?, destino=?, imprimir_na_comanda=?, controlar_estoque=?, ativo=? WHERE id=?', $d);
                flash('Produto atualizado.');
            } else {
                Database::query('INSERT INTO produtos (nome, preco, codigo_barras, estoque, categoria_id, destino, imprimir_na_comanda, controlar_estoque, ativo) VALUES (?,?,?,?,?,?,?,?,?)', $d);
                flash('Produto cadastrado.');
            }
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
        } elseif ($a === 'categoria' && trim($_POST['nome'] ?? '') !== '') {
            Database::query('INSERT INTO categorias (nome, ordem) VALUES (?, (SELECT COALESCE(MAX(c.ordem),0)+1 FROM categorias c))', [trim($_POST['nome'])]);
            flash('Categoria criada.');
        }
    } catch (PDOException $e) {
        flash(str_contains($e->getMessage(), 'Duplicate') ? 'Já existe um produto com esse código.' : 'Erro ao salvar.', 'danger');
    }
    redirect('produtos.php');
}

$produtos = listarProdutos();
$cats = Database::all('SELECT * FROM categorias ORDER BY ordem, nome');
$edit = !empty($_GET['editar']) ? Database::one('SELECT * FROM produtos WHERE id=?', [(int) $_GET['editar']]) : null;
$p = $edit ?: ['id' => '', 'nome' => '', 'preco' => '', 'codigo_barras' => '', 'estoque' => 0, 'categoria_id' => '', 'destino' => 'cozinha', 'imprimir_na_comanda' => 1, 'controlar_estoque' => 1, 'ativo' => 1];
$titulo = 'Estoque';
require __DIR__ . '/includes/header.php';
?>
<h1>Estoque e produtos</h1>
<form method="post" class="card-r mb-3">
  <?= csrf_field() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= e($p['id']) ?>">
  <h2><?= $edit ? 'Editar produto' : 'Novo produto' ?></h2>
  <div class="row g-2">
    <div class="col-md-3"><input name="nome" class="form-control" placeholder="Nome" value="<?= e($p['nome']) ?>" required></div>
    <div class="col-md-1"><input name="preco" class="form-control" placeholder="Preço" value="<?= e($p['preco']) ?>" required></div>
    <div class="col-md-2"><input name="codigo_barras" class="form-control" placeholder="Código" value="<?= e($p['codigo_barras']) ?>"></div>
    <div class="col-md-1"><input name="estoque" type="number" class="form-control" placeholder="Estoque" value="<?= e($p['estoque']) ?>"></div>
    <div class="col-md-2"><select name="categoria_id" class="form-select"><option value="">Categoria</option><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $p['categoria_id'] == $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select name="destino" class="form-select"><option value="cozinha">Preparo: Cozinha</option><option value="balcao" <?= $p['destino'] === 'balcao' ? 'selected' : '' ?>>Preparo: Balcão</option></select></div>
    <div class="col-md-1"><button class="btn btn-ember w-100"><?= $edit ? 'Salvar' : 'Cadastrar' ?></button></div>
  </div>
  <div class="d-flex flex-wrap gap-3 mt-2">
    <label class="form-check"><input type="checkbox" class="form-check-input" name="imprimir_na_comanda" <?= $p['imprimir_na_comanda'] ? 'checked' : '' ?>> Aparece na comanda impressa</label>
    <label class="form-check"><input type="checkbox" class="form-check-input" name="controlar_estoque" <?= $p['controlar_estoque'] ? 'checked' : '' ?>> Controlar estoque</label>
    <label class="form-check"><input type="checkbox" class="form-check-input" name="ativo" <?= $p['ativo'] ? 'checked' : '' ?>> Ativo</label>
    <?php if ($edit): ?><a href="<?= url('produtos.php') ?>">Cancelar edição</a><?php endif; ?>
  </div>
</form>
<form method="post" class="input-group mb-3" style="max-width:420px"><?= csrf_field() ?><input type="hidden" name="acao" value="categoria"><input name="nome" class="form-control" placeholder="Nova categoria"><button class="btn btn-outline-primary">Adicionar categoria</button></form>
<div class="card-r p-0 table-responsive">
  <table class="table mb-0 align-middle">
    <thead><tr><th class="text-center">Na comanda impressa</th><th>Produto</th><th>Categoria</th><th>Código</th><th>Preço</th><th>Estoque</th><th>Preparo</th><th>Ativo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($produtos as $x): ?>
      <tr class="<?= $x['ativo'] ? '' : 'opacity-50' ?>">
        <td class="text-center"><form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="toggle"><input type="hidden" name="campo" value="imprimir"><input type="hidden" name="id" value="<?= $x['id'] ?>"><input type="checkbox" class="form-check-input fs-5" onchange="this.form.submit()" <?= $x['imprimir_na_comanda'] ? 'checked' : '' ?>></form></td>
        <td class="fw-semibold"><?= e($x['nome']) ?></td><td><?= e($x['categoria']) ?></td><td><?= e($x['codigo_barras'] ?? '-') ?></td>
        <td><?= brl($x['preco']) ?></td>
        <td class="<?= $x['controlar_estoque'] && $x['estoque'] <= 5 ? 'text-danger fw-bold' : '' ?>"><?= $x['controlar_estoque'] ? $x['estoque'] : '—' ?></td>
        <td><span class="badge badge-<?= $x['destino'] ?>"><?= $x['destino'] === 'balcao' ? 'Balcão' : 'Cozinha' ?></span></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="toggle"><input type="hidden" name="campo" value="ativo"><input type="hidden" name="id" value="<?= $x['id'] ?>"><input type="checkbox" class="form-check-input" onchange="this.form.submit()" <?= $x['ativo'] ? 'checked' : '' ?>></form></td>
        <td class="text-nowrap"><a class="btn btn-sm btn-link" href="<?= url('produtos.php?editar=' . $x['id']) ?>"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" data-confirm="Excluir este produto?"><?= csrf_field() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-sm btn-link text-danger"><i class="bi bi-trash"></i></button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="small muted mt-2">Somente os produtos marcados em “Na comanda impressa” aparecem no papel entregue à mesa.</p>
<?php require __DIR__ . '/includes/footer.php';
