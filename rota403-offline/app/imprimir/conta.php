<?php
require __DIR__ . '/_base.php';
try { $c = politica_comanda(Auth::user(), (int) ($_GET['id'] ?? 0)); } catch (RuntimeException $e) { erro_central('Acesso negado', $e->getMessage()); }
if (!$c) erro_central('Comanda não encontrada', 'Não foi possível localizar esta comanda.');
$itens = Database::all('SELECT nome, SUM(quantidade) q, SUM(subtotal) t FROM comanda_itens WHERE comanda_id = ? GROUP BY nome, preco_unitario ORDER BY MIN(id)', [$c['id']]);
cupom_inicio('Conta ' . $c['id']);
?>
<?= cabecalhoCupom(true, $c['status'] === 'aberta' ? 'Conferência de Conta' : '') ?>
<div class="b">Comanda <?= $c['id'] ?> — <?= e(nomeComanda($c)) ?></div>
<div><?= date('d/m/Y H:i') ?></div><hr>
<table class="itens"><tr><th>QTD</th><th>DESCRIÇÃO</th><th class="r">VALOR</th></tr><?php foreach ($itens as $i): ?><tr><td class="q"><?= e(qtdFmt($i['q'])) ?><?= abs($i['q'] - round($i['q'])) > 0.0001 ? '' : 'x' ?></td><td class="d"><?= e($i['nome']) ?></td><td class="r v"><?= str_replace(' ', '&nbsp;', brl($i['t'])) ?></td></tr><?php endforeach; ?></table><hr>
<table><tr><td class="big b">TOTAL</td><td class="r big b"><?= str_replace(' ', '&nbsp;', brl($c['total'])) ?></td></tr></table>
<?php garantirPagamentos(); $pgs = Database::all('SELECT forma, valor, recebido FROM comanda_pagamentos WHERE comanda_id = ? ORDER BY id', [$c['id']]);
if (!$pgs && $c['forma_pagamento']) $pgs = [['forma' => $c['forma_pagamento'], 'valor' => $c['total'], 'recebido' => $c['total']]];
if ($pgs): ?><hr><div class="b">PAGAMENTO</div>
<table><?php foreach ($pgs as $pg): ?><tr><td><?= e($pg['forma']) ?></td><td class="r"><?= brl(max((float) $pg['recebido'], (float) $pg['valor'])) ?></td></tr><?php endforeach; ?>
<?php if ((float) ($c['troco'] ?? 0) > 0): ?><tr><td class="b">TROCO</td><td class="r b"><?= brl($c['troco']) ?></td></tr><?php endif; ?></table>
<?php endif; ?>
<hr><?php if (empresa()['rodape'] !== ''): ?><div class="c"><?= e(empresa()['rodape']) ?></div><?php endif; ?><div class="c">Não é documento fiscal</div>
<?php cupom_fim();
