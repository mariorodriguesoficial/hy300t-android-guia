<?php
require __DIR__ . '/_base.php';
$p = Database::one("SELECT p.*, c.tipo, c.identificacao FROM pedidos p JOIN comandas c ON c.id = p.comanda_id WHERE p.id = ? AND c.status = 'aberta'", [(int) ($_GET['id'] ?? 0)]);
if (!$p) {
    erro_central('Impressão bloqueada', 'A comanda já foi fechada ou paga.', 409);
}
$itens = json_decode($p['itens'], true) ?: [];
cupom_inicio('Pedido ' . $p['id']);
?>
<h1><?= e(mb_strtoupper(nomePreparo($p['destino']))) ?></h1><hr>
<div class="big b">Comanda <?= $p['comanda_id'] ?> — <?= e(nomeComanda($p)) ?></div>
<div><?= dataHora($p['criado_em']) ?><?= $p['garcom'] ? ' • Garçom: ' . e($p['garcom']) : '' ?></div><hr>
<table>
<?php foreach ($itens as $i): ?>
  <tr><td class="big b" style="width:70px"><?= e(qtdFmt($i['quantidade'], $i['unidade'] ?? null)) ?><?= ($i['unidade'] ?? '') === 'kg' ? '' : 'x' ?></td><td class="big"><?= e($i['nome']) ?><?php if (!empty($i['observacao'])): ?><br><small>» <?= e($i['observacao']) ?></small><?php endif; ?></td></tr>
<?php endforeach; ?>
</table><hr>
<?php cupom_fim();
