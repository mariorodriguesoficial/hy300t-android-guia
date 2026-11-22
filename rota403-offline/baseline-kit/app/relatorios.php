<?php
require_once __DIR__ . '/config/config.php';
Auth::exigir(['__admin__']);
$ini = $_GET['ini'] ?? date('Y-m-01');
$fim = $_GET['fim'] ?? date('Y-m-d');
$p = [$ini . ' 00:00:00', $fim . ' 23:59:59'];

$resumo = Database::one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM comandas WHERE status='fechada' AND fechado_em BETWEEN ? AND ?", $p);
$formas = Database::all("SELECT forma_pagamento f, COUNT(*) n, SUM(total) t FROM comandas WHERE status='fechada' AND fechado_em BETWEEN ? AND ? GROUP BY forma_pagamento ORDER BY t DESC", $p);
$top = Database::all("SELECT ci.nome, SUM(ci.quantidade) q, SUM(ci.subtotal) t FROM comanda_itens ci JOIN comandas c ON c.id=ci.comanda_id WHERE c.status='fechada' AND c.fechado_em BETWEEN ? AND ? GROUP BY ci.nome ORDER BY q DESC LIMIT 20", $p);
$caixas = Database::all("SELECT cx.*, u.nome usuario FROM caixas cx LEFT JOIN usuarios u ON u.id=cx.usuario_id WHERE cx.aberto_em BETWEEN ? AND ? ORDER BY cx.id DESC", $p);
$titulo = 'Relatórios';
require __DIR__ . '/includes/header.php';
?>
<h1>Relatórios</h1>
<form class="d-flex flex-wrap gap-2 mb-3 align-items-end">
  <div><label class="form-label small">De</label><input type="date" name="ini" value="<?= e($ini) ?>" class="form-control"></div>
  <div><label class="form-label small">Até</label><input type="date" name="fim" value="<?= e($fim) ?>" class="form-control"></div>
  <button class="btn btn-primary">Filtrar</button>
</form>
<div class="row g-2 mb-3">
  <div class="col-md-3"><div class="kpi total"><div class="small">Faturamento</div><div class="v"><?= brl($resumo['t']) ?></div></div></div>
  <div class="col-md-3"><div class="kpi"><div class="small muted">Comandas pagas</div><div class="v"><?= $resumo['n'] ?></div></div></div>
  <div class="col-md-3"><div class="kpi"><div class="small muted">Ticket médio</div><div class="v"><?= brl($resumo['n'] ? $resumo['t'] / $resumo['n'] : 0) ?></div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card-r"><h2>Mais vendidos</h2><table class="table table-sm"><thead><tr><th>Produto</th><th>Qtd</th><th class="text-end">Total</th></tr></thead><tbody>
    <?php foreach ($top as $t): ?><tr><td><?= e($t['nome']) ?></td><td><?= $t['q'] ?></td><td class="text-end"><?= brl($t['t']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="col-lg-6"><div class="card-r mb-3"><h2>Por forma de pagamento</h2><table class="table table-sm"><tbody>
    <?php foreach ($formas as $f): ?><tr><td><?= e($f['f']) ?></td><td><?= $f['n'] ?></td><td class="text-end"><?= brl($f['t']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="card-r"><h2>Caixas</h2><table class="table table-sm"><thead><tr><th>Abertura</th><th>Fechamento</th><th>Usuário</th><th class="text-end">Saldo final</th><th></th></tr></thead><tbody>
    <?php foreach ($caixas as $c): ?><tr><td><?= dataHora($c['aberto_em']) ?></td><td><?= dataHora($c['fechado_em']) ?></td><td><?= e($c['usuario']) ?></td><td class="text-end"><?= $c['saldo_final'] !== null ? brl($c['saldo_final']) : 'Aberto' ?></td>
      <td><button class="btn btn-sm btn-link" onclick="imprimirUrl('imprimir/fechamento.php?id=<?= $c['id'] ?>')"><i class="bi bi-printer"></i></button></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php require __DIR__ . '/includes/footer.php';
