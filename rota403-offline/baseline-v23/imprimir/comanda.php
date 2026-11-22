<?php
require __DIR__ . '/_base.php';
try { $c = politica_comanda(Auth::user(), (int) ($_GET['id'] ?? 0)); } catch (RuntimeException $e) { erro_central('Acesso negado', $e->getMessage()); }
if (!$c) erro_central('Comanda não encontrada', 'Não foi possível localizar esta comanda.');
$grupos = [];
garantirUnidade();
// Itens já lançados: produtos por unidade saem com "X" nos quadros; produtos por kg vão em Anotações (peso e valor)
$marcas = []; $obsKg = [];
foreach (Database::all('SELECT ci.produto_id, ci.nome, ci.quantidade, ci.subtotal, p.unidade FROM comanda_itens ci LEFT JOIN produtos p ON p.id = ci.produto_id WHERE ci.comanda_id = ? ORDER BY ci.id', [$c['id']]) as $it) {
    if (($it['unidade'] ?? 'un') === 'kg') $obsKg[] = $it['nome'] . ': ' . qtdFmt($it['quantidade'], 'kg') . ' = ' . brl($it['subtotal']);
    else $marcas[(int) $it['produto_id']] = ($marcas[(int) $it['produto_id']] ?? 0) + (int) round((float) $it['quantidade']);
}
foreach (listarProdutos(true) as $p) if ($p['imprimir_na_comanda']) $grupos[$p['categoria']][] = ['nome' => $p['nome'], 'x' => min(12, $marcas[(int) $p['id']] ?? 0)];
// Linhas OUTROS no fim do último grupo
$cats = array_keys($grupos);
$ultima = end($cats);
foreach ($grupos as $cat => &$ns) {
    if ($cat === $ultima) { array_push($ns, ['nome' => 'OUTROS', 'x' => 0], ['nome' => 'OUTROS', 'x' => 0]); }
}
unset($ns);
cupom_inicio('Comanda ' . $c['id']);
?>
<style>
  .ficha { width: 100%; border-collapse: collapse; table-layout: fixed; border: 2px solid #000; margin-top: 4px; }
  .ficha td { border: 1px solid #000; padding: 0; height: 16px; font-size: 8px; font-weight: 800; text-align: center; vertical-align: middle; }
  .ficha td.nm { width: 26mm; text-align: left; padding: 0 1px; font-size: 7.5px; letter-spacing: -.3px; white-space: nowrap; overflow: hidden; text-transform: uppercase; }
  .ficha tr.tit td { background: #000 !important; color: #fff !important; font-size: 11px; height: 16px; letter-spacing: .5px; }
  .anot { border: 2px solid #000; border-top: 0; }
  .anot .t { text-align: center; font-weight: 800; font-size: 11px; border-bottom: 1px solid #000; }
  .anot .l { height: 15px; border-bottom: 1px solid #000; }
  .anot .l.ok { height: auto; min-height: 15px; font-size: 11px; font-weight: 900; padding: 1px 3px; }
  .ficha td.mx { font-size: 11px; font-weight: 900; }
  .anot .l:last-child { border-bottom: 0; }
  .tot { display: flex; justify-content: flex-end; align-items: center; gap: 6px; margin-top: 8px; font-size: 15px; font-weight: 900; }
  .tot span.cx { width: 30mm; height: 10mm; border: 2px solid #000; border-radius: 4px; display: inline-block; }
  .frase { text-align: center; font-style: italic; font-weight: 800; font-size: 10px; margin-top: 6px; }
</style>
<?= cabecalhoCupom(false, '', true) ?>
<div class="c big b">COMANDA Nº <?= $c['id'] ?></div>
<div class="c big"><?= e(nomeComanda($c)) ?></div>
<div class="c"><?= dataHora($c['criado_em']) ?></div><hr>
<table class="ficha"><colgroup><col style="width:26mm"><?php for ($i = 1; $i <= 12; $i++): ?><col><?php endfor; ?></colgroup>
<?php foreach ($grupos as $cat => $ns): ?>
  <tr class="tit"><td colspan="13"><?= e(mb_strtoupper($cat)) ?></td></tr>
  <?php foreach ($ns as $n): ?>
    <tr><td class="nm"><?= e($n['nome']) ?></td><?php for ($i = 1; $i <= 12; $i++): ?><?php if ($i <= $n['x']): ?><td class="mx">X</td><?php else: ?><td><?= $i ?></td><?php endif; ?><?php endfor; ?></tr>
  <?php endforeach; ?>
<?php endforeach; ?>
</table>
<div class="anot"><div class="t">ANOTAÇÕES</div><?php foreach ($obsKg as $o): ?><div class="l ok"><?= e(mb_strtoupper($o)) ?></div><?php endforeach; ?><?php for ($i = count($obsKg); $i < 6; $i++): ?><div class="l"></div><?php endfor; ?></div>
<div class="tot">TOTAL R$: <span class="cx"></span></div>
<?php cupom_fim();
