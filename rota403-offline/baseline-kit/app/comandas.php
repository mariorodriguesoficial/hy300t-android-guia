<?php
require_once __DIR__ . '/config/config.php';
Auth::exigir(['caixa', 'garcom']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['acao'] ?? '') === 'imprimir') {
        $id = (int) ($_POST['id'] ?? 0);
        $c = Database::one("SELECT id, status FROM comandas WHERE id = ?", [$id]);
        if (!$c || $c['status'] !== 'aberta') { flash('Comanda fechada ou paga não pode ser impressa.', 'danger'); redirect('comandas.php'); }
        if (Auth::user()['nivel'] === 'garcom') {
            garantirColunaImpressa();
            Database::query('UPDATE comandas SET impressa = 0 WHERE id = ?', [$id]);
            flash("Comanda nº $id enviada para a impressora térmica.");
            redirect('comandas.php');
        }
        redirect('comandas.php?imprimir=' . $id);
    }
    $ident = trim($_POST['identificacao'] ?? '');
    $tipo = ($_POST['tipo'] ?? 'mesa') === 'cliente' ? 'cliente' : 'mesa';
    if ($ident === '') { flash('Informe a mesa ou o nome do cliente.', 'danger'); redirect('comandas.php'); }
    garantirColunaImpressa();
    // A comanda só é impressa se o usuário clicar em "Imprimir comanda"
    $id = Database::insert('INSERT INTO comandas (identificacao, tipo, impressa) VALUES (?,?,1)', [$ident, $tipo]);
    Database::query('UPDATE comandas SET codigo = ? WHERE id = ?', [codigoComanda($id), $id]);
    flash("Comanda nº $id criada. Para imprimir, clique em \"Imprimir comanda\".");
    redirect('comandas.php?nova=' . $id);
}

if (isset($_GET['codigo'])) {
    $c = Database::one("SELECT id FROM comandas WHERE codigo = ? AND status = 'aberta'", [strtoupper(trim($_GET['codigo']))]);
    if ($c) redirect('comanda.php?id=' . $c['id']);
    flash('Comanda não encontrada ou já fechada.', 'danger');
    redirect('comandas.php');
}

$comandas = Database::all("SELECT * FROM comandas WHERE status = 'aberta' ORDER BY id");
$titulo = 'Comandas';
require __DIR__ . '/includes/header.php';
?>
<h1>Comandas</h1>
<div class="row g-3 mb-4">
  <div class="col-md-6"><form method="post" class="card-r h-100">
    <?= csrf_field() ?>
    <h2>Nova comanda</h2>
    <div class="btn-group mb-2" role="group">
      <input type="radio" class="btn-check" name="tipo" id="tMesa" value="mesa" checked><label class="btn btn-outline-primary" for="tMesa">Mesa</label>
      <input type="radio" class="btn-check" name="tipo" id="tCli" value="cliente"><label class="btn btn-outline-primary" for="tCli">Nome do cliente</label>
    </div>
    <div class="input-group">
      <input name="identificacao" class="form-control" placeholder="Número da mesa ou nome do cliente" required>
      <button class="btn btn-ember"><i class="bi bi-plus-lg"></i> Criar comanda</button>
    </div>
  </form></div>
  <div class="col-md-6"><form method="get" class="card-r h-100">
    <h2>Ler código da comanda</h2>
    <div class="input-group">
      <input name="codigo" class="form-control" placeholder="Passe o leitor no código de barras" autofocus autocomplete="off">
      <button class="btn btn-success"><i class="bi bi-upc-scan"></i> Abrir</button>
    </div>
  </form></div>
</div>
<?php $nova = (int) ($_GET['nova'] ?? 0); ?>
<div class="grid-cmd">
  <?php foreach ($comandas as $c): ?>
    <div class="d-flex flex-column gap-1">
    <a class="cmd-card<?= $nova === (int) $c['id'] ? ' sel' : '' ?>" href="<?= url('comanda.php?id=' . $c['id']) ?>">
      <div class="small muted">Nº <?= $c['id'] ?></div>
      <div class="nome"><?= e(nomeComanda($c)) ?></div>
      <div class="valor"><?= brl($c['total']) ?></div>
      <div class="small muted"><?= dataHora($c['criado_em']) ?></div>
    </a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="imprimir"><input type="hidden" name="id" value="<?= $c['id'] ?>">
      <button class="btn btn-sm <?= $nova === (int) $c['id'] ? 'btn-ember' : 'btn-outline-primary' ?> w-100"><i class="bi bi-printer"></i> Imprimir comanda</button></form>
    </div>
  <?php endforeach; ?>
  <?php if (!$comandas): ?><p class="muted">Nenhuma comanda aberta.</p><?php endif; ?>
</div>
<?php
$imp = (int) ($_GET['imprimir'] ?? 0);
$scripts = '<script>setTimeout(function(){location.reload()},30000);</script>';
if ($imp) $scripts .= "<script>imprimirUrl('imprimir/comanda.php?id=$imp');history.replaceState(null,'','comandas.php');</script>";
if (!empty($_GET['nova'])) $scripts .= "<script>history.replaceState(null,'','comandas.php');</script>";
require __DIR__ . '/includes/footer.php';
