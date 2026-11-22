<?php
require __DIR__ . '/_base.php';
$cx = Database::one('SELECT cx.*, u.nome usuario FROM caixas cx LEFT JOIN usuarios u ON u.id = cx.usuario_id WHERE cx.id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$cx) erro_central('Caixa não encontrado', 'Não foi possível localizar este fechamento.');
$formas = Database::all("SELECT forma_pagamento f, COUNT(*) n, SUM(total) t FROM comandas WHERE caixa_id = ? AND status = 'fechada' GROUP BY forma_pagamento", [$cx['id']]);
$total = array_sum(array_column($formas, 't'));
$qtd = array_sum(array_column($formas, 'n'));
$din = 0; foreach ($formas as $f) if ($f['f'] === 'Dinheiro') $din = $f['t'];
cupom_inicio('Fechamento de caixa');
?>
<?= cabecalhoCupom() ?><div class="c big b">FECHAMENTO DE CAIXA</div><hr>
<div>Operador: <?= e($cx['usuario']) ?></div>
<div>Abertura: <?= dataHora($cx['aberto_em']) ?></div>
<div>Fechamento: <?= dataHora($cx['fechado_em']) ?></div><hr>
<table><?php foreach ($formas as $f): ?><tr><td><?= e($f['f']) ?> (<?= $f['n'] ?>)</td><td class="r"><?= brl($f['t']) ?></td></tr><?php endforeach; ?></table><hr>
<table>
  <tr><td>Comandas</td><td class="r"><?= $qtd ?></td></tr>
  <tr><td>Troco inicial</td><td class="r"><?= brl($cx['saldo_inicial']) ?></td></tr>
  <tr><td class="b">Total vendas</td><td class="r b"><?= brl($total) ?></td></tr>
  <tr><td class="b">Dinheiro em caixa</td><td class="r b"><?= brl($cx['saldo_inicial'] + $din) ?></td></tr>
</table><hr><br><div class="c">_______________________<br>Assinatura</div>
<?php cupom_fim();
