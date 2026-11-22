<?php
require_once __DIR__ . '/config/config.php';
Auth::exigir(['__admin__']);
garantirPreparos();
$aba = ($_GET['aba'] ?? $_POST['aba'] ?? '') === 'preparo' ? 'preparo' : 'categorias';
$volta = 'categorias.php' . ($aba === 'preparo' ? '?aba=preparo' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $acao = $_POST['acao'] ?? '';
    $nome = trim((string) ($_POST['nome'] ?? ''));
    if ($aba === 'categorias') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($acao === 'criar' || $acao === 'renomear') {
            if ($nome === '' || mb_strlen($nome) > 60) { flash('Informe o nome da categoria (até 60 caracteres).', 'danger'); redirect($volta); }
            if (Database::one('SELECT id FROM categorias WHERE nome = ? AND id <> ? LIMIT 1', [$nome, $id])) { flash('Já existe uma categoria com esse nome.', 'danger'); redirect($volta); }
            if ($acao === 'criar') {
                $ordem = (int) Database::val('SELECT COALESCE(MAX(ordem), 0) + 1 FROM categorias');
                Database::query('INSERT INTO categorias (nome, ordem) VALUES (?, ?)', [$nome, $ordem]);
                flash("Categoria \"$nome\" cadastrada.");
            } else {
                Database::query('UPDATE categorias SET nome = ? WHERE id = ?', [$nome, $id]);
                flash("Categoria renomeada para \"$nome\".");
            }
        }
        if ($acao === 'excluir') {
            $emUso = (int) Database::val('SELECT COUNT(*) FROM produtos WHERE categoria_id = ?', [$id]);
            if ($emUso > 0) flash("Esta categoria tem $emUso produto(s). Mova os produtos para outra categoria antes de excluir.", 'danger');
            else { Database::query('DELETE FROM categorias WHERE id = ?', [$id]); flash('Categoria excluída.'); }
        }
    } else {
        $slug = (string) ($_POST['slug'] ?? '');
        if ($acao === 'criar' || $acao === 'renomear') {
            if ($nome === '' || mb_strlen($nome) > 40) { flash('Informe o nome do preparo (até 40 caracteres).', 'danger'); redirect($volta); }
            if (Database::one('SELECT slug FROM preparos WHERE nome = ? AND slug <> ? LIMIT 1', [$nome, $slug])) { flash('Já existe um preparo com esse nome.', 'danger'); redirect($volta); }
            if ($acao === 'criar') {
                $base = slugPreparo($nome); $novo = $base; $n = 2;
                while (Database::one('SELECT slug FROM preparos WHERE slug = ?', [$novo])) $novo = substr($base, 0, 26) . '_' . $n++;
                $ordem = (int) Database::val('SELECT COALESCE(MAX(ordem), 0) + 1 FROM preparos');
                Database::query('INSERT INTO preparos (slug, nome, ordem) VALUES (?, ?, ?)', [$novo, $nome, $ordem]);
                flash("Preparo \"$nome\" cadastrado. Marque-o no Monitor de Impressão do PC que vai imprimir.");
            } else {
                Database::query('UPDATE preparos SET nome = ? WHERE slug = ?', [$nome, $slug]);
                flash("Preparo renomeado para \"$nome\".");
            }
        }
        if ($acao === 'excluir') {
            $emUso = (int) Database::val('SELECT COUNT(*) FROM produtos WHERE destino = ?', [$slug]);
            $total = (int) Database::val('SELECT COUNT(*) FROM preparos');
            if ($emUso > 0) flash("Este preparo tem $emUso produto(s). Mude o preparo desses produtos antes de excluir.", 'danger');
            elseif ($total <= 1) flash('É preciso manter pelo menos um preparo.', 'danger');
            else { Database::query('DELETE FROM preparos WHERE slug = ?', [$slug]); flash('Preparo excluído.'); }
        }
    }
    redirect($volta);
}

$cats = Database::all('SELECT c.id, c.nome, COUNT(p.id) qtd FROM categorias c LEFT JOIN produtos p ON p.categoria_id = c.id GROUP BY c.id, c.nome, c.ordem ORDER BY c.ordem, c.nome');
$preps = Database::all('SELECT pr.slug, pr.nome, COUNT(p.id) qtd FROM preparos pr LEFT JOIN produtos p ON p.destino = pr.slug GROUP BY pr.slug, pr.nome, pr.ordem ORDER BY pr.ordem, pr.nome');
$titulo = 'Categorias';
require __DIR__ . '/includes/header.php';
$linhas = $aba === 'preparo' ? $preps : $cats;
$rotulo = $aba === 'preparo' ? 'preparo' : 'categoria';
?>
<h1>Categorias</h1>
<div class="d-flex flex-wrap gap-2 mb-3" role="tablist">
  <a role="tab" aria-selected="<?= $aba === 'categorias' ? 'true' : 'false' ?>" class="btn btn-outline-primary <?= $aba === 'categorias' ? 'active' : '' ?>" href="<?= url('categorias.php') ?>"><i class="bi bi-tags"></i> Categorias</a>
  <a role="tab" aria-selected="<?= $aba === 'preparo' ? 'true' : 'false' ?>" class="btn btn-outline-primary <?= $aba === 'preparo' ? 'active' : '' ?>" href="<?= url('categorias.php?aba=preparo') ?>"><i class="bi bi-printer"></i> Preparo</a>
</div>
<?php if ($aba === 'preparo'): ?><p class="muted small">O preparo define onde o pedido é impresso (ex.: Cozinha, Balcão, Bar). No PC de cada impressora, marque no Monitor de Impressão os preparos que ela deve imprimir.</p><?php endif; ?>
<div class="card-r mb-3">
  <form method="post" class="d-flex flex-wrap gap-2"><?= csrf_field() ?><input type="hidden" name="aba" value="<?= $aba ?>"><input type="hidden" name="acao" value="criar">
    <input name="nome" class="form-control flex-grow-1 w-auto" placeholder="Nome do novo <?= $rotulo ?>" maxlength="<?= $aba === 'preparo' ? 40 : 60 ?>" required>
    <button class="btn btn-ember"><i class="bi bi-plus-lg"></i> Adicionar <?= $rotulo ?></button>
  </form>
</div>
<div class="card-r p-0 table-responsive">
  <table class="table mb-0 align-middle">
    <thead><tr><th><?= ucfirst($rotulo) ?></th><th>Produtos</th><th class="text-end">Ações</th></tr></thead>
    <tbody>
    <?php foreach ($linhas as $k => $c): $chave = $aba === 'preparo' ? 'slug' : 'id'; ?>
      <tr>
        <td><form method="post" class="d-flex gap-2" id="ren<?= $k ?>" data-edicao-nome><?= csrf_field() ?><input type="hidden" name="aba" value="<?= $aba ?>"><input type="hidden" name="acao" value="renomear"><input type="hidden" name="<?= $chave ?>" value="<?= e($c[$chave]) ?>">
          <input name="nome" class="form-control form-control-sm" value="<?= e($c['nome']) ?>" maxlength="<?= $aba === 'preparo' ? 40 : 60 ?>" required readonly aria-label="Nome do <?= $rotulo ?>"></form></td>
        <td><?= (int) $c['qtd'] ?></td>
        <td class="text-end text-nowrap">
          <button type="button" data-editar="ren<?= $k ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</button>
          <button form="ren<?= $k ?>" data-salvar="ren<?= $k ?>" class="btn btn-sm btn-ember" hidden><i class="bi bi-check-lg"></i> Salvar</button>
          <button type="button" data-cancelar="ren<?= $k ?>" class="btn btn-sm btn-outline-secondary" hidden>Cancelar</button>
          <form method="post" class="d-inline" data-confirm="<?= e('Excluir ' . $rotulo . ' "' . $c['nome'] . '"?') ?>"><?= csrf_field() ?><input type="hidden" name="aba" value="<?= $aba ?>"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="<?= $chave ?>" value="<?= e($c[$chave]) ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Excluir</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$linhas): ?><tr><td colspan="3" class="text-center muted p-3">Nada cadastrado.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php
$scripts = '<script src="' . e(url('assets/categorias.js?v=11')) . '"></script>';
require __DIR__ . '/includes/footer.php';
