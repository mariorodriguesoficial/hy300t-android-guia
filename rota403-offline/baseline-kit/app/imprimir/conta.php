<?php
require __DIR__ . '/_base.php';
$c = Database::one('SELECT * FROM comandas WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$c) erro_central('Comanda não encontrada', 'Não foi possível localizar esta comanda.');
$itens = Database::all('SELECT nome, SUM(quantidade) q, SUM(subtotal) t FROM comanda_itens WHERE comanda_id = ? GROUP BY nome, preco_unitario ORDER BY MIN(id)', [$c['id']]);
cupom_inicio('Conta ' . $c['id']);
?>
<?= cabecalhoCupom() ?>
<div class="b">Comanda <?= $c['id'] ?> — <?= e(nomeComanda($c)) ?></div>
<div><?= date('d/m/Y H:i') ?></div><hr>
<table><?php foreach ($itens as $i): ?><tr><td><?= $i['q'] ?>x <?= e($i['nome']) ?></td><td class="r"><?= brl($i['t']) ?></td></tr><?php endforeach; ?></table><hr>
<table><tr><td class="big b">TOTAL</td><td class="r big b"><?= brl($c['total']) ?></td></tr></table>
<?php if ($c['forma_pagamento']): ?><div>Pagamento: <?= e($c['forma_pagamento']) ?></div><?php endif; ?>
<hr><?php if (empresa()['rodape'] !== ''): ?><div class="c"><?= e(empresa()['rodape']) ?></div><?php endif; ?><div class="c">Não é documento fiscal</div>
<?php cupom_fim();
