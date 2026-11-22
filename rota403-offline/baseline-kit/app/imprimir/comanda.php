<?php
require __DIR__ . '/_base.php';
$c = Database::one('SELECT * FROM comandas WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$c) erro_central('Comanda não encontrada', 'Não foi possível localizar esta comanda.');
$grupos = [];
foreach (listarProdutos(true) as $p) if ($p['imprimir_na_comanda']) $grupos[$p['categoria']][] = $p;
cupom_inicio('Comanda ' . $c['id']);
?>
<?= cabecalhoCupom(false) ?>
<div class="c big b">COMANDA Nº <?= $c['id'] ?></div>
<div class="c big"><?= e(nomeComanda($c)) ?></div>
<div class="c"><?= dataHora($c['criado_em']) ?></div><hr>
<div class="c"><svg data-code="<?= e($c['codigo']) ?>"></svg></div><hr>
<div>Marque um quadrado a cada item consumido:</div>
<?php foreach ($grupos as $cat => $ps): ?>
  <div class="cat"><?= e($cat) ?></div>
  <?php foreach ($ps as $p): ?>
    <div class="row"><span class="box"></span><span class="box"></span><span class="box"></span><span class="box"></span><span class="box"></span><span class="n"><?= e($p['nome']) ?></span><span><?= brl($p['preco']) ?></span></div>
  <?php endforeach; ?>
<?php endforeach; ?>
<hr><div class="c">Apresente esta comanda no caixa</div>
<?php cupom_fim();
