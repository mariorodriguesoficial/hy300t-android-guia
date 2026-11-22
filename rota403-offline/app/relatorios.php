<?php
require_once __DIR__ . '/config/config.php';
Auth::exigir(['__admin__']);
$ini = $_GET['ini'] ?? date('Y-m-01');
$fim = $_GET['fim'] ?? date('Y-m-d');
$p = [$ini . ' 00:00:00', $fim . ' 23:59:59'];

$resumo = Database::one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM comandas WHERE status='fechada' AND fechado_em BETWEEN ? AND ?", $p);
$formas = vendasPorForma('c.fechado_em BETWEEN ? AND ?', $p);
$top = Database::all("SELECT ci.nome, SUM(ci.quantidade) q, SUM(ci.subtotal) t FROM comanda_itens ci JOIN comandas c ON c.id=ci.comanda_id WHERE c.status='fechada' AND c.fechado_em BETWEEN ? AND ? GROUP BY ci.nome ORDER BY q DESC LIMIT 20", $p);
$caixas = Database::all("SELECT cx.*, u.nome usuario FROM caixas cx LEFT JOIN usuarios u ON u.id=cx.usuario_id WHERE cx.aberto_em BETWEEN ? AND ? ORDER BY cx.id DESC", $p);
foreach ($caixas as &$cxr) $cxr['usuario'] = decifrar($cxr['usuario']);
unset($cxr);
$pagas = Database::all("SELECT id, tipo, identificacao, total, fechado_em FROM comandas WHERE status='fechada' AND fechado_em BETWEEN ? AND ? ORDER BY fechado_em DESC", $p);
$itensPorComanda = [];
if ($pagas) {
    $ids = array_column($pagas, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (Database::all("SELECT comanda_id, nome, quantidade, preco_unitario, subtotal FROM comanda_itens WHERE comanda_id IN ($ph) ORDER BY id", $ids) as $it) {
        $itensPorComanda[$it['comanda_id']][] = $it;
    }
}
$rel = $_GET['rel'] ?? '';
$prodLista = Database::all('SELECT id, nome FROM produtos ORDER BY nome');
$prodSels = array_values(array_filter(array_map('intval', (array) ($_GET['produtos'] ?? (isset($_GET['produto']) ? [$_GET['produto']] : [])))));
$prodSel = count($prodSels);
$avulsos = [];
if ($rel === 'avulsos' && isset($_GET['ini'])) {
    $sqlA = "SELECT ci.produto_id, ci.nome, SUM(ci.quantidade) q, SUM(ci.subtotal) t, COUNT(DISTINCT ci.comanda_id) n FROM comanda_itens ci JOIN comandas c ON c.id=ci.comanda_id WHERE c.status='fechada' AND c.fechado_em BETWEEN ? AND ?";
    $pa = $p;
    if ($prodSels) { $sqlA .= ' AND ci.produto_id IN (' . implode(',', array_fill(0, count($prodSels), '?')) . ')'; $pa = array_merge($pa, $prodSels); }
    $avulsos = Database::all($sqlA . ' GROUP BY ci.produto_id, ci.nome ORDER BY ci.nome', $pa);
}
$titulo = 'Relatórios';
require __DIR__ . '/includes/header.php';
?>
<h1>Relatórios</h1>
<div class="row g-2 mb-3 no-print">
  <div class="col-md-3"><div class="kpi total"><div class="small">Faturamento</div><div class="v"><?= brl($resumo['t']) ?></div></div></div>
  <div class="col-md-3"><div class="kpi"><div class="small muted">Comandas pagas</div><div class="v"><?= $resumo['n'] ?></div></div></div>
  <div class="col-md-3"><div class="kpi"><div class="small muted">Ticket médio</div><div class="v"><?= brl($resumo['n'] ? $resumo['t'] / $resumo['n'] : 0) ?></div></div></div>
</div>
<?php $RELS = ['mais_vendidos' => ['Mais vendidos', 'bi-trophy'], 'formas' => ['Por forma de pagamento', 'bi-credit-card'], 'pagas' => ['Comandas pagas', 'bi-receipt'], 'avulsos' => ['Produtos avulsos', 'bi-box-seam'], 'caixas' => ['Caixas', 'bi-cash-stack']]; ?>
<div class="d-flex flex-wrap gap-2 mb-3 rel-menu no-print">
  <?php foreach ($RELS as $k => $r): ?><a class="btn <?= $rel === $k ? 'btn-ember' : 'btn-outline-primary' ?>" href="<?= url('relatorios.php?rel=' . $k . '&ini=' . urlencode($ini) . '&fim=' . urlencode($fim)) ?>"><i class="bi <?= $r[1] ?>"></i> <?= $r[0] ?></a><?php endforeach; ?>
  <?php if ($rel): ?><a class="btn btn-link" href="<?= url('relatorios.php') ?>">Fechar relatório</a><?php endif; ?>
</div>
<form class="d-flex flex-wrap gap-2 mb-3 align-items-end no-print">
  <?php if ($rel): ?><input type="hidden" name="rel" value="<?= e($rel) ?>"><?php endif; ?>
  <div><label class="form-label small">Data inicial</label><input type="date" name="ini" value="<?= e($ini) ?>" class="form-control"></div>
  <div><label class="form-label small">Data final</label><input type="date" name="fim" value="<?= e($fim) ?>" class="form-control"></div>
  <?php if ($rel === 'avulsos'): ?><div class="position-relative"><label class="form-label small">Produtos</label>
    <button type="button" class="form-select text-start" id="btProdSel" style="min-width:260px" onclick="var b=document.getElementById('boxProdSel');b.style.display=b.style.display==='none'?'block':'none'"><?= $prodSels ? count($prodSels) . ' produto(s) marcado(s)' : 'Todos os produtos' ?></button>
    <div id="boxProdSel" class="card-r p-2 shadow" style="display:none;position:absolute;z-index:50;background:#fff;width:320px;max-height:360px;overflow:auto">
      <input type="text" class="form-control form-control-sm mb-2" placeholder="Buscar produto..." oninput="var t=this.value.toLowerCase();document.querySelectorAll('#boxProdSel .pchk').forEach(function(l){l.style.display=l.textContent.toLowerCase().indexOf(t)>=0?'':'none'})">
      <label class="d-block fw-bold border-bottom pb-1 mb-1"><input type="checkbox" class="form-check-input me-1" id="chkTodos" <?= $prodSels ? '' : 'checked' ?> onchange="if(this.checked)document.querySelectorAll('#boxProdSel .pchk input').forEach(function(c){c.checked=false});document.getElementById('btProdSel').textContent='Todos os produtos'"> Todos os produtos</label>
      <?php foreach ($prodLista as $pl): ?><label class="d-block pchk"><input type="checkbox" class="form-check-input me-1" name="produtos[]" value="<?= $pl['id'] ?>" <?= in_array((int) $pl['id'], $prodSels, true) ? 'checked' : '' ?> onchange="var n=document.querySelectorAll('#boxProdSel .pchk input:checked').length;document.getElementById('chkTodos').checked=!n;document.getElementById('btProdSel').textContent=n?n+' produto(s) marcado(s)':'Todos os produtos'"> <?= e($pl['nome']) ?></label><?php endforeach; ?>
    </div></div><?php endif; ?>
  <button class="btn btn-primary"><?= $rel === 'avulsos' ? 'Gerar relatório' : 'Filtrar' ?></button>
</form>
<?php if ($rel === 'avulsos' && isset($_GET['ini'])): ?>
<div class="card-r mb-3" id="relImprimir">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h2 class="mb-0">Relatório de produtos avulsos</h2>
    <button type="button" class="btn btn-ember no-print" onclick="imprimirRelatorio()"><i class="bi bi-file-earmark-pdf"></i> Imprimir / Salvar PDF</button></div>
  <div class="small muted mb-2">Período: <?= date('d/m/Y', strtotime($ini)) ?> a <?= date('d/m/Y', strtotime($fim)) ?> • Produto(s): <?= $prodSels ? e(implode(', ', array_column(Database::all('SELECT nome FROM produtos WHERE id IN (' . implode(',', array_fill(0, count($prodSels), '?')) . ') ORDER BY nome', $prodSels), 'nome'))) : 'Todos' ?> • Gerado em <?= date('d/m/Y H:i') ?></div>
  <table class="table table-sm"><thead><tr><th>Produto</th><th class="text-end">Qtd vendida</th><th class="text-end">Comandas</th><th class="text-end">Total</th></tr></thead><tbody>
  <?php foreach ($avulsos as $a): ?><tr><td><?= e($a['nome']) ?></td><td class="text-end"><?= e(qtdFmt($a['q'])) ?></td><td class="text-end"><?= (int) $a['n'] ?></td><td class="text-end"><?= brl($a['t']) ?></td></tr><?php endforeach; ?>
  <?php if (!$avulsos): ?><tr><td colspan="4" class="muted">Nenhuma venda dos produtos selecionados no período.</td></tr><?php endif; ?>
  </tbody><?php if ($avulsos): ?><tfoot><tr class="fw-bold"><td>Total</td><td class="text-end"><?= e(qtdFmt(array_sum(array_column($avulsos, 'q')))) ?></td><td></td><td class="text-end"><?= brl(array_sum(array_column($avulsos, 't'))) ?></td></tr></tfoot><?php endif; ?></table>
</div>
<?php endif; ?>
<?php if ($rel === 'mais_vendidos'): ?><div class="card-r mb-3"><h2>Mais vendidos</h2><table class="table table-sm"><thead><tr><th>Produto</th><th>Qtd</th><th class="text-end">Total</th></tr></thead><tbody>
    <?php foreach ($top as $t): ?><tr><td><?= e($t['nome']) ?></td><td><?= $t['q'] ?></td><td class="text-end"><?= brl($t['t']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if ($rel === 'formas'): ?><div class="card-r mb-3"><h2>Por forma de pagamento</h2><table class="table table-sm"><tbody>
    <?php foreach ($formas as $f): ?><tr><td><?= e($f['f']) ?></td><td><?= $f['n'] ?></td><td class="text-end"><?= brl($f['t']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php if ($rel === 'pagas'): ?><div class="card-r mt-3"><h2>Comandas pagas</h2>
  <?php if (!$pagas): ?><p class="muted mb-0">Nenhuma comanda paga no período.</p><?php endif; ?>
  <?php foreach ($pagas as $cp): ?>
  <details class="mb-2 border rounded">
    <summary class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-2" style="cursor:pointer">
      <span><strong>Comanda nº <?= (int) $cp['id'] ?></strong> — <?= e(nomeComanda($cp)) ?></span>
      <span class="muted small"><?= dataHora($cp['fechado_em']) ?></span>
      <strong><?= brl($cp['total']) ?></strong>
    </summary>
    <table class="table table-sm mb-0"><thead><tr><th>Item</th><th>Qtd</th><th class="text-end">Unitário</th><th class="text-end">Subtotal</th></tr></thead><tbody>
      <?php foreach ($itensPorComanda[$cp['id']] ?? [] as $it): ?>
      <tr><td><?= e($it['nome']) ?></td><td><?= e(qtdFmt($it['quantidade'])) ?></td><td class="text-end"><?= brl($it['preco_unitario']) ?></td><td class="text-end"><?= brl($it['subtotal']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($rel === 'caixas'): ?>
    <div class="card-r mb-3 no-print"><h2>Caixas</h2><table class="table table-sm"><thead><tr><th>Abertura</th><th>Fechamento</th><th>Usuário</th><th class="text-end">Saldo final</th><th></th></tr></thead><tbody>
    <?php foreach ($caixas as $c): ?><tr><td><?= dataHora($c['aberto_em']) ?></td><td><?= dataHora($c['fechado_em']) ?></td><td><?= e($c['usuario']) ?></td><td class="text-end"><?= $c['saldo_final'] !== null ? brl($c['saldo_final']) : 'Aberto' ?></td>
      <td><button class="btn btn-sm btn-link" onclick="imprimirUrl('imprimir/fechamento.php?id=<?= $c['id'] ?>')"><i class="bi bi-printer"></i></button></td></tr><?php endforeach; ?><?php if (!$caixas): ?><tr><td colspan="5" class="muted">Nenhum caixa no período.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
<script>
// Abre o relatório numa janela própria (só o relatório) para imprimir ou salvar como PDF
function imprimirRelatorio(){var el=document.getElementById('relImprimir');if(!el)return;var c=el.cloneNode(true);c.querySelectorAll('.no-print').forEach(function(x){x.remove();});
var w=window.open('','_blank','width=900,height=700');if(!w){mostrarAviso('Permita janelas pop-up deste site para gerar o PDF.');return;}
w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Relatório de produtos avulsos</title><style>body{font-family:Arial,sans-serif;color:#000;margin:24px}h2{margin:0 0 6px;font-size:22px}.small{font-size:12px;color:#444;margin-bottom:12px}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #ccc;padding:6px 4px;text-align:left}th{background:#eee}.text-end{text-align:right}tfoot td{font-weight:bold;border-top:2px solid #000}@page{margin:14mm}</style></head><body>'+c.innerHTML+'</body></html>');
w.document.close();w.focus();setTimeout(function(){w.print();},300);}
</script>
<?php require __DIR__ . '/includes/footer.php';
