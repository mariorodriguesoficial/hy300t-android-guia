<?php
require_once __DIR__ . '/config/config.php';
$u = Auth::exigir(['caixa', 'garcom']);
$id = (int) ($_GET['id'] ?? 0);
$c = Database::one('SELECT * FROM comandas WHERE id = ?', [$id]);
if (!$c) { flash('Comanda não encontrada.', 'danger'); redirect('comandas.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remover']) && Auth::pode(['caixa'])) {
    check_csrf();
    $item = Database::one('SELECT ci.*, p.controlar_estoque FROM comanda_itens ci JOIN produtos p ON p.id = ci.produto_id WHERE ci.id = ? AND ci.comanda_id = ?', [(int) $_POST['remover'], $id]);
    if ($item && $c['status'] === 'aberta') {
        Database::query('DELETE FROM comanda_itens WHERE id = ?', [$item['id']]);
        if ($item['controlar_estoque']) Database::query('UPDATE produtos SET estoque = estoque + ? WHERE id = ?', [$item['quantidade'], $item['produto_id']]);
        recalcularTotal($id);
        flash('Item removido.');
    }
    redirect('comanda.php?id=' . $id);
}

$itens = Database::all('SELECT * FROM comanda_itens WHERE comanda_id = ? ORDER BY id', [$id]);
$aberta = $c['status'] === 'aberta';
$titulo = 'Comanda ' . $id;
require __DIR__ . '/includes/header.php';
?>
<a href="<?= url('comandas.php') ?>" class="muted text-decoration-none"><i class="bi bi-arrow-left"></i> Comandas</a>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 my-2">
  <div><div class="muted small">Comanda Nº <?= $c['id'] ?> • <?= e($c['codigo']) ?> • <?= strtoupper($c['status']) ?></div>
    <h1 class="mb-0"><?= e(nomeComanda($c)) ?></h1></div>
  <div class="d-flex gap-2">
    <?php if ($aberta): ?>
    <button class="btn btn-outline-primary" onclick="imprimirUrl('imprimir/comanda.php?id=<?= $id ?>')"><i class="bi bi-printer"></i> Reimprimir comanda</button>
    <button class="btn btn-outline-primary" onclick="imprimirUrl('imprimir/conta.php?id=<?= $id ?>')"><i class="bi bi-receipt"></i> Imprimir conta</button>
    <?php endif; ?>
  </div>
</div>
<div class="card-r p-0 mb-4 table-responsive">
  <table class="table mb-0 align-middle">
    <thead><tr><th>Item</th><th>Qtd</th><th>Garçom</th><th>Hora</th><th class="text-end">Subtotal</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($itens as $i): ?>
      <tr><td><?= e($i['nome']) ?><?php if ($i['observacao']): ?><div class="small muted">» <?= e($i['observacao']) ?></div><?php endif; ?></td>
        <td><?= $i['quantidade'] ?></td><td><?= e($i['garcom'] ?? '-') ?></td><td><?= dataHora($i['adicionado_em']) ?></td>
        <td class="text-end"><?= brl($i['subtotal']) ?></td>
        <td class="text-end"><?php if ($aberta && Auth::pode(['caixa'])): ?>
          <form method="post" data-confirm="Remover este item?"><?= csrf_field() ?><button name="remover" value="<?= $i['id'] ?>" class="btn btn-sm btn-link text-danger"><i class="bi bi-trash"></i></button></form>
        <?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$itens): ?><tr><td colspan="6" class="text-center muted p-4">Sem itens ainda.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td colspan="4" class="display fs-3">Total</td><td class="text-end display fs-3" style="color:var(--brasa)"><?= brl($c['total']) ?></td><td></td></tr></tfoot>
  </table>
</div>
<?php if ($aberta): ?>
  <h2>Adicionar itens</h2>
  <div id="lancador"></div>
<?php endif;
$produtos = array_map(fn($p) => ['id' => (int) $p['id'], 'nome' => $p['nome'], 'preco' => (float) $p['preco'], 'codigo' => $p['codigo_barras'], 'categoria' => $p['categoria'], 'estoque' => (int) $p['estoque'], 'controlar' => (int) $p['controlar_estoque']], listarProdutos(true));
$scripts = '<script>window.PRODUTOS=' . json_encode($produtos, JSON_UNESCAPED_UNICODE) . ';</script><script src="' . url('assets/lancador.js?v=6') . '"></script>';
if ($aberta) $scripts .= '<script>Lancador(document.getElementById("lancador"), ' . $id . ', ' . json_encode($u['nome']) . ', function(){ location.reload(); });</script>';
require __DIR__ . '/includes/footer.php';
