<?php
require_once __DIR__ . '/config/config.php';
$u = Auth::exigir(['caixa']);
const FORMAS = ['Dinheiro', 'PIX', 'Cartão de débito', 'Cartão de crédito'];
$caixa = caixaAberto();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'abrir' && !$caixa) {
        Database::query('INSERT INTO caixas (usuario_id, saldo_inicial) VALUES (?,?)', [$u['id'], (float) str_replace(',', '.', $_POST['saldo'] ?? '0')]);
        flash('Caixa aberto.'); redirect('caixa.php');
    }
    if (!$caixa) redirect('caixa.php');
    if ($acao === 'receber') {
        $id = (int) $_POST['comanda_id'];
        $forma = in_array($_POST['forma'] ?? '', FORMAS, true) ? $_POST['forma'] : 'Dinheiro';
        Database::query("UPDATE comandas SET status='fechada', forma_pagamento=?, caixa_id=?, fechado_em=NOW() WHERE id=? AND status='aberta'", [$forma, $caixa['id'], $id]);
        flash("Comanda nº $id recebida ($forma).");
        redirect('caixa.php?imprimir=' . $id);
    }
    if ($acao === 'cancelar') {
        $id = (int) $_POST['comanda_id'];
        foreach (Database::all('SELECT ci.*, p.controlar_estoque FROM comanda_itens ci JOIN produtos p ON p.id=ci.produto_id WHERE comanda_id=?', [$id]) as $i)
            if ($i['controlar_estoque']) Database::query('UPDATE produtos SET estoque = estoque + ? WHERE id = ?', [$i['quantidade'], $i['produto_id']]);
        Database::query("UPDATE comandas SET status='cancelada', fechado_em=NOW() WHERE id=? AND status='aberta'", [$id]);
        flash("Comanda nº $id cancelada.", 'warning'); redirect('caixa.php');
    }
    if ($acao === 'fechar') {
        $din = (float) Database::val("SELECT COALESCE(SUM(total),0) FROM comandas WHERE caixa_id=? AND status='fechada' AND forma_pagamento='Dinheiro'", [$caixa['id']]);
        Database::query("UPDATE caixas SET status='fechado', fechado_em=NOW(), saldo_final=? WHERE id=?", [$caixa['saldo_inicial'] + $din, $caixa['id']]);
        flash('Caixa fechado.'); redirect('caixa.php?fechamento=' . $caixa['id']);
    }
}

$titulo = 'Caixa';
require __DIR__ . '/includes/header.php';

if (!$caixa): ?>
  <form method="post" class="card-r mx-auto" style="max-width:380px">
    <?= csrf_field() ?><input type="hidden" name="acao" value="abrir">
    <h1>Abrir caixa</h1>
    <label class="form-label">Troco inicial</label>
    <input name="saldo" class="form-control mb-3" value="0,00">
    <button class="btn btn-ember w-100"><i class="bi bi-unlock"></i> Abrir caixa</button>
  </form>
<?php else:
  $vendas = Database::all("SELECT forma_pagamento, COUNT(*) n, SUM(total) t FROM comandas WHERE caixa_id=? AND status='fechada' GROUP BY forma_pagamento", [$caixa['id']]);
  $porForma = array_column($vendas, 't', 'forma_pagamento');
  $totalV = array_sum($porForma); $qtdV = array_sum(array_column($vendas, 'n'));
  $abertas = Database::all("SELECT * FROM comandas WHERE status='aberta' ORDER BY id");
  $selId = (int) ($_GET['id'] ?? 0);
  if (!empty($_GET['codigo'])) $selId = (int) Database::val("SELECT id FROM comandas WHERE codigo=? AND status='aberta'", [strtoupper(trim($_GET['codigo']))]);
  $sel = $selId ? Database::one("SELECT * FROM comandas WHERE id=? AND status='aberta'", [$selId]) : null;
?>
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div><h1 class="mb-0">Caixa</h1><div class="muted small">Aberto em <?= dataHora($caixa['aberto_em']) ?> • Troco <?= brl($caixa['saldo_inicial']) ?></div></div>
    <form method="post" data-confirm="Fechar o caixa?"><?= csrf_field() ?><input type="hidden" name="acao" value="fechar"><button class="btn btn-danger"><i class="bi bi-lock"></i> Fechar caixa</button></form>
  </div>
  <div class="row g-2 mb-4">
    <?php foreach (FORMAS as $f): ?><div class="col-6 col-md"><div class="kpi"><div class="small muted"><?= $f ?></div><div class="v"><?= brl($porForma[$f] ?? 0) ?></div></div></div><?php endforeach; ?>
    <div class="col-6 col-md"><div class="kpi total"><div class="small">Total (<?= $qtdV ?>)</div><div class="v"><?= brl($totalV) ?></div></div></div>
  </div>
  <div class="row g-3">
    <div class="col-lg-7">
      <form method="get" class="input-group mb-3"><input name="codigo" class="form-control" placeholder="Ler código de barras da comanda" autofocus autocomplete="off"><button class="btn btn-success"><i class="bi bi-upc-scan"></i> Ler</button></form>
      <div class="grid-cmd">
        <?php foreach ($abertas as $c): ?>
          <a href="<?= url('caixa.php?id=' . $c['id']) ?>" class="cmd-card <?= $sel && $sel['id'] == $c['id'] ? 'sel' : '' ?>"><div class="nome"><?= e(nomeComanda($c)) ?></div><div class="small">Nº <?= $c['id'] ?> • <b class="valor"><?= brl($c['total']) ?></b></div></a>
        <?php endforeach; ?>
        <?php if (!$abertas): ?><p class="muted">Nenhuma comanda aberta.</p><?php endif; ?>
      </div>
    </div>
    <div class="col-lg-5">
    <?php if ($sel): $itens = Database::all('SELECT * FROM comanda_itens WHERE comanda_id=? ORDER BY id', [$sel['id']]); ?>
      <div class="card-r">
        <h2><?= e(nomeComanda($sel)) ?> <small class="muted fs-6">Nº <?= $sel['id'] ?></small></h2>
        <div style="max-height:260px;overflow:auto" class="small">
          <?php foreach ($itens as $i): ?><div class="d-flex justify-content-between"><span><?= $i['quantidade'] ?>x <?= e($i['nome']) ?></span><span><?= brl($i['subtotal']) ?></span></div><?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-between border-top mt-2 pt-2 display fs-2"><span>Total</span><span style="color:var(--brasa)"><?= brl($sel['total']) ?></span></div>
        <form method="post" id="fPag">
          <?= csrf_field() ?><input type="hidden" name="acao" value="receber"><input type="hidden" name="comanda_id" value="<?= $sel['id'] ?>">
          <div class="row g-2 my-2">
            <?php foreach (FORMAS as $k => $f): ?><div class="col-6"><input type="radio" class="btn-check" name="forma" id="f<?= $k ?>" value="<?= $f ?>" <?= $k === 1 ? 'checked' : '' ?>><label class="btn btn-outline-primary w-100 btn-sm" for="f<?= $k ?>"><?= $f ?></label></div><?php endforeach; ?>
          </div>
          <div id="boxDin" class="mb-2" style="display:none"><input id="recebido" class="form-control" placeholder="Valor recebido"><div id="troco" class="fw-bold mt-1"></div></div>
          <button class="btn btn-ember w-100" <?= $itens ? '' : 'disabled' ?>><i class="bi bi-printer"></i> Receber e imprimir</button>
        </form>
        <form method="post" data-confirm="Cancelar esta comanda?" class="mt-2"><?= csrf_field() ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="comanda_id" value="<?= $sel['id'] ?>"><button class="btn btn-link text-danger w-100">Cancelar comanda</button></form>
      </div>
      <?php $scripts = '<script>(function(){var tot=' . (float) $sel['total'] . ';var f=document.getElementById("fPag");function upd(){var d=f.forma.value==="Dinheiro";document.getElementById("boxDin").style.display=d?"block":"none";var r=parseFloat((document.getElementById("recebido").value||"0").replace(",","."));document.getElementById("troco").textContent=r>tot?"Troco: R$ "+(r-tot).toFixed(2).replace(".",","):"";}f.addEventListener("change",upd);f.addEventListener("input",upd);upd();})();</script>'; ?>
    <?php else: ?>
      <div class="card-r text-center muted" style="border-style:dashed">Selecione uma comanda para receber</div>
    <?php endif; ?>
    </div>
  </div>
<?php endif;
$scripts = ($scripts ?? '');
if (!empty($_GET['imprimir'])) $scripts .= "<script>imprimirUrl('imprimir/conta.php?id=" . (int) $_GET['imprimir'] . "');history.replaceState(null,'','caixa.php');</script>";
if (!empty($_GET['fechamento'])) $scripts .= "<script>imprimirUrl('imprimir/fechamento.php?id=" . (int) $_GET['fechamento'] . "');history.replaceState(null,'','caixa.php');</script>";
require __DIR__ . '/includes/footer.php';
