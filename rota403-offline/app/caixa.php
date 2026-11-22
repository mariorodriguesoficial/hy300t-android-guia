<?php
require_once __DIR__ . '/config/config.php';
$u = Auth::exigir(['caixa']);
$FORMAS = formasPagamento();
$caixa = caixaAberto();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'abrir' && !$caixa) {
        Database::query('INSERT INTO caixas (usuario_id, saldo_inicial) VALUES (?,?)', [$u['id'], (float) str_replace(',', '.', $_POST['saldo'] ?? '0')]);
        flash('Caixa aberto.'); redirect('caixa.php');
    }
    if (!$caixa) redirect('caixa.php');
    if ($acao === 'criar_comanda') {
        $ident = trim((string) ($_POST['identificacao'] ?? ''));
        $tipo = ($_POST['tipo'] ?? 'cliente') === 'mesa' ? 'mesa' : 'cliente';
        if ($ident === '') { flash('Informe a mesa ou o nome do cliente.', 'danger'); redirect('caixa.php'); }
        garantirColunaImpressa();
        $nid = Database::insert('INSERT INTO comandas (identificacao, tipo, impressa) VALUES (?,?,1)', [mb_substr($ident, 0, 60), $tipo]);
        Database::query('UPDATE comandas SET codigo = ? WHERE id = ?', [codigoComanda($nid), $nid]);
        flash("Comanda nº $nid criada. Digite o código do produto (ou código*peso) e Enter.");
        redirect('caixa.php?id=' . $nid . '&nova=1');
    }
    if ($acao === 'venda_direta') {
        garantirColunaImpressa();
        $nid = Database::insert("INSERT INTO comandas (identificacao, tipo, impressa) VALUES ('VENDA DIRETA','cliente',1)");
        Database::query('UPDATE comandas SET codigo = ?, identificacao = ? WHERE id = ?', [codigoComanda($nid), 'VENDA DIRETA ' . $nid, $nid]);
        flash("Venda direta nº $nid iniciada. Adicione os produtos e receba.");
        redirect('caixa.php?id=' . $nid . '&nova=1');
    }
    if ($acao === 'remover_item') {
        $id = (int) $_POST['comanda_id'];
        if (($u['nivel'] ?? '') !== 'admin') { flash('Somente o administrador pode excluir itens da comanda.', 'danger'); redirect('caixa.php?id=' . $id); }
        $it = Database::one("SELECT ci.*, p.controlar_estoque FROM comanda_itens ci JOIN comandas c ON c.id=ci.comanda_id AND c.status='aberta' LEFT JOIN produtos p ON p.id=ci.produto_id WHERE ci.id=? AND ci.comanda_id=?", [(int) $_POST['item_id'], $id]);
        if (!$it) { flash('Item não encontrado.', 'danger'); redirect('caixa.php?id=' . $id); }
        garantirPagamentos();
        $tot = (float) Database::val('SELECT total FROM comandas WHERE id=?', [$id]);
        if ($tot - (float) $it['subtotal'] < valorPagoParcial($id) - 0.004) { flash('Não é possível excluir: o total ficaria menor que o valor já pago parcialmente.', 'danger'); redirect('caixa.php?id=' . $id); }
        Database::query('DELETE FROM comanda_itens WHERE id=?', [$it['id']]);
        if (!empty($it['controlar_estoque'])) Database::query('UPDATE produtos SET estoque = estoque + ? WHERE id = ?', [$it['quantidade'], $it['produto_id']]);
        recalcularTotal($id);
        flash($it['nome'] . ' excluído da comanda.', 'warning');
        redirect('caixa.php?id=' . $id);
    }
    if ($acao === 'ajustar_item') {
        $id = (int) $_POST['comanda_id'];
        $d = (int) ($_POST['delta'] ?? 1);
        if (!in_array($d, [-1, 1], true)) $d = 1;
        $it = Database::one("SELECT ci.*, p.controlar_estoque, p.unidade FROM comanda_itens ci JOIN comandas c ON c.id=ci.comanda_id AND c.status='aberta' LEFT JOIN produtos p ON p.id=ci.produto_id WHERE ci.id=? AND ci.comanda_id=?", [(int) $_POST['item_id'], $id]);
        if (!$it) { flash('Item não encontrado.', 'danger'); redirect('caixa.php?id=' . $id); }
        if (($it['unidade'] ?? '') === 'kg') { flash('Produto por kg não usa + ou -.', 'danger'); redirect('caixa.php?id=' . $id); }
        $nq = (float) $it['quantidade'] + $d;
        if ($nq < 1) { flash('Quantidade mínima é 1. Para retirar o item use o X.', 'danger'); redirect('caixa.php?id=' . $id); }
        if ($d < 0 && (float) $it['quantidade'] <= 1) { flash('Quantidade mínima é 1. Para retirar o item use o X.', 'danger'); redirect('caixa.php?id=' . $id); }
        if ($d < 0) {
            garantirPagamentos();
            $pago = valorPagoParcial($id);
            if ($pago > 0) {
                $tot = (float) Database::val('SELECT total FROM comandas WHERE id=?', [$id]);
                if ($tot - (float) $it['preco_unitario'] < $pago - 0.004) { flash('Não é possível diminuir: o total ficaria menor que o valor já pago parcialmente.', 'danger'); redirect('caixa.php?id=' . $id); }
            }
        }
        Database::query('UPDATE comanda_itens SET quantidade=?, subtotal=? WHERE id=?', [$nq, round($nq * (float) $it['preco_unitario'], 2), $it['id']]);
        if (!empty($it['controlar_estoque'])) Database::query('UPDATE produtos SET estoque = estoque - ? WHERE id = ?', [$d, $it['produto_id']]);
        recalcularTotal($id);
        redirect('caixa.php?id=' . $id . '&qs=' . $it['id']);
    }
    if ($acao === 'imprimir_comanda') {
        $id = (int) $_POST['comanda_id'];
        if (!Database::one("SELECT id FROM comandas WHERE id=? AND status='aberta'", [$id])) { flash('Comanda fechada ou paga não pode ser impressa.', 'danger'); redirect('caixa.php'); }
        garantirColunaImpressa();
        Database::query('UPDATE comandas SET impressa = 0 WHERE id = ?', [$id]);
        flash("Comanda nº $id enviada para a impressora térmica.");
        redirect('caixa.php?id=' . $id);
    }
    if ($acao === 'pagamento_parcial') {
        garantirPagamentos();
        $id = (int) $_POST['comanda_id'];
        $cmd = Database::one("SELECT * FROM comandas WHERE id=? AND status='aberta'", [$id]);
        if (!$cmd) { flash('Comanda não encontrada ou já fechada.', 'danger'); redirect('caixa.php'); }
        $f = (string) ($_POST['forma'] ?? '');
        $v = round((float) str_replace(',', '.', str_replace('.', '', (string) ($_POST['valor'] ?? '0'))), 2);
        if (strpos((string) ($_POST['valor'] ?? ''), ',') === false) $v = round((float) ($_POST['valor'] ?? 0), 2);
        $falta = round((float) $cmd['total'] - valorPagoParcial($id), 2);
        if (!in_array($f, $FORMAS, true)) { flash('Escolha a forma de pagamento.', 'danger'); redirect('caixa.php?id=' . $id); }
        if ($v <= 0) { flash('Digite o valor do pagamento parcial.', 'danger'); redirect('caixa.php?id=' . $id); }
        if ($v >= $falta - 0.004) { flash('O pagamento parcial deve ser menor que o restante (' . brl($falta) . '). Para pagar tudo, use "Receber e imprimir".', 'danger'); redirect('caixa.php?id=' . $id); }
        Database::query('INSERT INTO comanda_pagamentos (comanda_id, forma, valor, recebido) VALUES (?,?,?,?)', [$id, $f, $v, $v]);
        flash('Pagamento parcial de ' . brl($v) . " em $f registrado. Falta pagar " . brl($falta - $v) . '.');
        redirect('caixa.php?id=' . $id);
    }
    if ($acao === 'lancar_codigo') {
        // Código do produto (ex.: 789123) ou código*peso para produtos em kg (ex.: 1001*0,450)
        // ajax=1: usado pela janela "Adicionar produtos do estoque", que continua aberta após cada item
        $ajax = !empty($_POST['ajax']);
        $id = (int) $_POST['comanda_id'];
        $resp = function (bool $ok, string $msg) use ($ajax, $id) {
            if ($ajax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE); exit; }
            flash($msg, $ok ? 'success' : 'danger'); redirect('caixa.php?id=' . $id);
        };
        $txt = trim((string) ($_POST['codigo'] ?? ''));
        $partes = array_map('trim', explode('*', $txt, 2));
        garantirUnidade();
        if (preg_match('/^#(\d+)$/', $partes[0], $mm)) $p = Database::one('SELECT * FROM produtos WHERE id = ? AND ativo = 1', [(int) $mm[1]]);
        else $p = $partes[0] !== '' ? Database::one('SELECT * FROM produtos WHERE codigo_barras = ? AND ativo = 1', [$partes[0]]) : null;
        if (!$p) $resp(false, 'Produto com código ' . $partes[0] . ' não encontrado.');
        if (($p['unidade'] ?? 'un') === 'kg') {
            $t = $partes[1] ?? '';
            $peso = preg_match('/[.,]/', $t) ? (float) str_replace(',', '.', $t) : ((int) $t >= 10 ? (int) $t / 1000 : (float) $t);
            if ($peso <= 0) $resp(false, 'Produto por kg: digite ' . $partes[0] . '*peso (ex.: ' . $partes[0] . '*0,450) e Enter.');
            $qtd = $peso;
        } else {
            if (isset($partes[1])) $resp(false, $p['nome'] . ' não é vendido por kg.');
            $qtd = 1;
        }
        try {
            lancarItens($id, [['produto_id' => $p['id'], 'quantidade' => $qtd]], mb_substr($u['nome'], 0, 100), false);
            $resp(true, $p['nome'] . ' adicionado: ' . qtdFmt($qtd, $p['unidade'] ?? null) . ' = ' . brl(round($p['preco'] * $qtd, 2)));
        } catch (RuntimeException $e) { $resp(false, $e->getMessage()); }
    }
    if ($acao === 'venda_direta_finalizar') {
        // Venda direta: os produtos ficam só na tela do caixa (sem comanda aberta); ao receber, a venda é gravada já paga
        garantirPagamentos(); garantirUnidade(); garantirColunaImpressa();
        $linhas = []; $total = 0.0;
        foreach ((json_decode((string) ($_POST['itens'] ?? '[]'), true) ?: []) as $l) {
            $p = Database::one('SELECT * FROM produtos WHERE id=? AND ativo=1', [(int) ($l['id'] ?? 0)]);
            if (!$p) continue;
            $q = ($p['unidade'] ?? 'un') === 'kg' ? round((float) ($l['q'] ?? 0), 3) : max(1, (int) ($l['q'] ?? 1));
            if ($q <= 0) continue;
            $linhas[] = ['produto_id' => $p['id'], 'quantidade' => $q];
            $total += round($p['preco'] * $q, 2);
        }
        $total = round($total, 2);
        if (!$linhas) { flash('Adicione produtos na venda direta.', 'danger'); redirect('caixa.php?venda=1'); }
        $formasP = $_POST['pg_forma'] ?? []; $valoresP = $_POST['pg_valor'] ?? [];
        $pags = []; $falta = $total; $troco = 0.0;
        foreach ($formasP as $k => $f) {
            if (!in_array($f, $FORMAS, true)) continue;
            $v = round((float) str_replace(',', '.', $valoresP[$k] ?? '0'), 2);
            if ($v <= 0 || $falta <= 0.004) continue;
            $aplicado = min($v, $falta);
            if ($v > $falta) {
                if ($f !== 'Dinheiro') { flash("O valor em $f não pode ser maior que o restante da venda.", 'danger'); redirect('caixa.php?venda=1'); }
                $troco = round($v - $falta, 2);
            }
            $pags[] = [$f, round($aplicado, 2), $v];
            $falta = round($falta - $aplicado, 2);
        }
        if ($falta > 0.004 || !$pags) { flash('Pagamento incompleto: ainda faltam ' . brl($falta) . '.', 'danger'); redirect('caixa.php?venda=1'); }
        $nid = Database::insert("INSERT INTO comandas (identificacao, tipo, impressa) VALUES ('VENDA DIRETA','cliente',1)");
        Database::query('UPDATE comandas SET codigo = ?, identificacao = ? WHERE id = ?', [codigoComanda($nid), 'VENDA DIRETA ' . $nid, $nid]);
        try { lancarItens($nid, $linhas, mb_substr($u['nome'], 0, 100), false); }
        catch (RuntimeException $e) {
            Database::query("UPDATE comandas SET status='cancelada', fechado_em=NOW() WHERE id=?", [$nid]);
            flash($e->getMessage(), 'danger'); redirect('caixa.php?venda=1');
        }
        $nomes = implode(' + ', array_unique(array_column($pags, 0)));
        Database::query("UPDATE comandas SET status='fechada', forma_pagamento=?, troco=?, caixa_id=?, fechado_em=NOW() WHERE id=?", [mb_substr($nomes, 0, 100), $troco, $caixa['id'], $nid]);
        foreach ($pags as $pg) Database::query('INSERT INTO comanda_pagamentos (comanda_id, forma, valor, recebido) VALUES (?,?,?,?)', [$nid, $pg[0], $pg[1], $pg[2]]);
        flash('Venda direta recebida (' . $nomes . ')' . ($troco > 0 ? ' • Troco ' . brl($troco) : '') . '. Comprovante enviado para a impressora.');
        $job = enfileirarImpressao('conta', $nid);
        redirect('caixa.php?comprovante=' . $job . '&vdok=1');
    }
    if ($acao === 'receber') {
        garantirPagamentos();
        $id = (int) $_POST['comanda_id'];
        $cmd = Database::one("SELECT * FROM comandas WHERE id=? AND status='aberta'", [$id]);
        if (!$cmd) { flash('Comanda não encontrada ou já fechada.', 'danger'); redirect('caixa.php'); }
        $pagoAntes = valorPagoParcial($id);
        $total = round((float) $cmd['total'] - $pagoAntes, 2);
        $formasP = $_POST['pg_forma'] ?? []; $valoresP = $_POST['pg_valor'] ?? [];
        $pags = []; $falta = $total; $troco = 0.0;
        foreach ($formasP as $k => $f) {
            if (!in_array($f, $FORMAS, true)) continue;
            $v = round((float) str_replace(',', '.', $valoresP[$k] ?? '0'), 2);
            if ($v <= 0 || $falta <= 0.004) continue;
            $aplicado = min($v, $falta);
            if ($v > $falta) {
                if ($f !== 'Dinheiro') { flash("O valor em $f não pode ser maior que o restante da comanda.", 'danger'); redirect('caixa.php?id=' . $id); }
                $troco = round($v - $falta, 2);
            }
            $pags[] = [$f, round($aplicado, 2), $v];
            $falta = round($falta - $aplicado, 2);
        }
        if ($falta > 0.004 || (!$pags && $total > 0.004)) { flash('Pagamento incompleto: ainda faltam ' . brl($falta) . ' para fechar a comanda.', 'danger'); redirect('caixa.php?id=' . $id); }
        $anteriores = Database::all('SELECT forma FROM comanda_pagamentos WHERE comanda_id=?', [$id]);
        $nomes = implode(' + ', array_unique(array_merge(array_column($anteriores, 'forma'), array_column($pags, 0))));
        Database::query("UPDATE comandas SET status='fechada', forma_pagamento=?, troco=?, caixa_id=?, fechado_em=NOW() WHERE id=? AND status='aberta'", [mb_substr($nomes, 0, 100), $troco, $caixa['id'], $id]);
        foreach ($pags as $p) Database::query('INSERT INTO comanda_pagamentos (comanda_id, forma, valor, recebido) VALUES (?,?,?,?)', [$id, $p[0], $p[1], $p[2]]);
        flash("Comanda nº $id recebida ($nomes)" . ($troco > 0 ? ' • Troco ' . brl($troco) : '') . '. Comprovante enviado para a impressora.');
        $job = enfileirarImpressao('conta', $id);
        redirect('caixa.php?comprovante=' . $job);
    }
    if ($acao === 'cancelar') {
        $id = (int) $_POST['comanda_id'];
        if (valorPagoParcial($id) > 0) { flash('Esta comanda tem pagamento parcial e não pode ser cancelada.', 'danger'); redirect('caixa.php?id=' . $id); }
        foreach (Database::all('SELECT ci.*, p.controlar_estoque FROM comanda_itens ci JOIN produtos p ON p.id=ci.produto_id WHERE comanda_id=?', [$id]) as $i)
            if ($i['controlar_estoque']) Database::query('UPDATE produtos SET estoque = estoque + ? WHERE id = ?', [$i['quantidade'], $i['produto_id']]);
        Database::query("UPDATE comandas SET status='cancelada', fechado_em=NOW() WHERE id=? AND status='aberta'", [$id]);
        flash("Comanda nº $id cancelada.", 'warning'); redirect('caixa.php');
    }
    if ($acao === 'fechar') {
        $din = 0.0; foreach (vendasPorForma('c.caixa_id=?', [$caixa['id']]) as $vf) if ($vf['f'] === 'Dinheiro') $din = (float) $vf['t'];
        Database::query("UPDATE caixas SET status='fechado', fechado_em=NOW(), saldo_final=? WHERE id=?", [$caixa['saldo_inicial'] + $din, $caixa['id']]);
        flash('Caixa fechado.'); redirect('caixa.php?fechamento=' . $caixa['id']);
    }
}

$titulo = 'Caixa';
$telaFixa = true;
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
  $porForma = array_column(vendasPorForma('c.caixa_id=?', [$caixa['id']]), 't', 'f');
  $totalV = array_sum($porForma); $qtdV = (int) Database::val("SELECT COUNT(*) FROM comandas WHERE caixa_id=? AND status='fechada'", [$caixa['id']]);
  $abertas = Database::all("SELECT * FROM comandas WHERE status='aberta' ORDER BY id");
  garantirPagamentos();
  $parciais = array_map('floatval', array_column(Database::all("SELECT p.comanda_id, SUM(p.valor) t FROM comanda_pagamentos p JOIN comandas c ON c.id=p.comanda_id WHERE c.status='aberta' GROUP BY p.comanda_id"), 't', 'comanda_id'));
  $selId = (int) ($_GET['id'] ?? 0);
  if (!empty($_GET['codigo'])) $selId = (int) Database::val("SELECT id FROM comandas WHERE codigo=? AND status='aberta'", [strtoupper(trim($_GET['codigo']))]);
  $sel = $selId ? Database::one("SELECT * FROM comandas WHERE id=? AND status='aberta'", [$selId]) : null;
  $venda = !$sel && !empty($_GET['venda']);
?>
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
    <div><h1 class="mb-0">Caixa</h1><div class="muted small">Aberto em <?= dataHora($caixa['aberto_em']) ?> • Troco <?= brl($caixa['saldo_inicial']) ?></div></div>
    <div class="d-flex gap-2 align-items-center ms-auto">
      <button type="button" class="btn btn-ember text-nowrap" id="btNovaCmd"><i class="bi bi-plus-lg"></i> Criar comanda <kbd class="atalho">F7</kbd></button>
      <?php if ($sel || $venda): ?><button type="button" class="btn btn-outline-primary text-nowrap" id="btEstoque" title="Escolher produtos do estoque"><i class="bi bi-box-seam"></i> Produtos <kbd class="atalho">F5</kbd></button><?php endif; ?>
      <a href="<?= url('caixa.php?venda=1') ?>" class="btn btn-success text-nowrap <?= $venda ? 'active' : '' ?>" id="btVendaDireta"><i class="bi bi-cart-plus"></i> Venda direta <kbd class="atalho">F11</kbd></a>
      <form method="post" data-confirm="Fechar o caixa?"><?= csrf_field() ?><input type="hidden" name="acao" value="fechar"><button class="btn btn-danger text-nowrap"><i class="bi bi-lock"></i> Fechar caixa</button></form>
    </div>
  </div>
  <div class="row g-3 tela-caixa-corpo">
    <div class="col-lg-7">
      <div class="d-flex gap-2 mb-2">
        <?php if ($sel || $venda): ?>
        <form method="post" id="fAddProd" class="input-group flex-grow-1 position-relative" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="acao" value="lancar_codigo"><input type="hidden" name="comanda_id" value="<?= $sel ? $sel['id'] : 0 ?>">
          <input name="codigo" id="codProd" class="form-control" placeholder="Adicionar produto: código de barras, código ou nome (kg: código*peso)" autofocus required>
          <button class="btn btn-success"><i class="bi bi-plus-lg"></i> Adicionar</button>
          <div id="sugProd" class="sug-prod" style="display:none"></div>
        </form>
        <?php endif; ?>
      </div>
      <form method="post" id="fNovaCmd" class="card-r mb-2 p-2" style="display:none"><?= csrf_field() ?><input type="hidden" name="acao" value="criar_comanda">
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <div class="btn-group btn-group-sm" role="group">
            <input type="radio" class="btn-check" name="tipo" id="ncCli" value="cliente" checked><label class="btn btn-outline-primary" for="ncCli">Cliente</label>
            <input type="radio" class="btn-check" name="tipo" id="ncMesa" value="mesa"><label class="btn btn-outline-primary" for="ncMesa">Mesa</label>
          </div>
          <input name="identificacao" id="ncIdent" class="form-control form-control-sm flex-grow-1" style="width:auto" placeholder="Nome do cliente ou número da mesa" autocomplete="off" required maxlength="60">
          <button class="btn btn-ember btn-sm"><i class="bi bi-check-lg"></i> Criar</button>
          <button type="button" class="btn btn-link btn-sm" id="ncCancel">Cancelar</button>
        </div>
      </form>
      <div class="grid-cmd">
        <?php foreach ($abertas as $c): ?>
          <a href="<?= url('caixa.php?id=' . $c['id']) ?>" class="cmd-card <?= $sel && $sel['id'] == $c['id'] ? 'sel' : '' ?>"><div class="nome"><?= e(nomeComanda($c)) ?></div><div class="small">Nº <?= $c['id'] ?> • <b class="valor"><?= brl($c['total']) ?></b></div><?php $pp = $parciais[$c['id']] ?? 0; if ($pp > 0): ?><div class="small text-success fw-bold">Falta <?= brl($c['total'] - $pp) ?></div><?php endif; ?></a>
        <?php endforeach; ?>
        <?php if (!$abertas): ?><p class="muted">Nenhuma comanda aberta.</p><?php endif; ?>
      </div>
    </div>
    <div class="col-lg-5">
    <?php if ($sel || $venda):
      $blocoPag = function (bool $parcial, int $temItens) use ($FORMAS) { ob_start(); ?>
          <div class="small muted mt-2">Forma de pagamento</div>
          <div class="row g-2 mb-2">
            <?php foreach ($FORMAS as $k => $f): ?><div class="col-6"><input type="radio" class="btn-check" name="forma_sel" id="f<?= $k ?>" value="<?= e($f) ?>" <?= $k === min(1, count($FORMAS) - 1) ? 'checked' : '' ?>><label class="btn btn-outline-primary w-100 btn-sm" for="f<?= $k ?>"><?php if ($k < 4): ?><kbd class="atalho">F<?= $k + 1 ?></kbd> <?php endif; ?><?= e($f) ?></label></div><?php endforeach; ?>
          </div>
          <div class="input-group mb-2"><input id="pgValor" class="form-control" inputmode="decimal" placeholder="Valor"><button type="button" id="pgAdd" class="btn btn-success"><i class="bi bi-plus-lg"></i> Adicionar</button></div>
          <div id="pgLista" class="small mb-2"></div>
          <div id="pgResumo" class="mb-2"></div>
          <div id="pgHidden"></div>
          <div class="d-flex gap-2"><?php if ($parcial): ?><button type="button" id="pgParcial" class="btn btn-outline-success flex-fill"><i class="bi bi-cash-coin"></i> Pagamento Parcial <kbd class="atalho">F8</kbd></button><?php endif; ?>
          <button id="pgFinal" class="btn btn-ember flex-fill" disabled data-itens="<?= $temItens ?>"><i class="bi bi-printer"></i> Receber e imprimir <kbd class="atalho">F9</kbd></button></div>
      <?php return ob_get_clean(); };
    endif; ?>
    <?php if ($sel): $itens = Database::all('SELECT ci.*, p.unidade AS unidade_cx FROM comanda_itens ci LEFT JOIN produtos p ON p.id=ci.produto_id WHERE ci.comanda_id=? ORDER BY ci.id', [$sel['id']]);
      $pgParc = Database::all('SELECT forma, valor FROM comanda_pagamentos WHERE comanda_id=? ORDER BY id', [$sel['id']]);
      $credito = round(array_sum(array_column($pgParc, 'valor')), 2); $restante = round((float) $sel['total'] - $credito, 2); ?>
      <div class="card-r painel-receber">
        <h2><?= e(nomeComanda($sel)) ?> <small class="muted fs-6">Nº <?= $sel['id'] ?></small></h2>
        <div class="faixa-comanda"><?= e(nomeComanda($sel)) ?> • COMANDA Nº <?= $sel['id'] ?></div>
        <div class="small itens-receber">
          <?php $isAdm = ($u['nivel'] ?? '') === 'admin'; foreach ($itens as $i): ?><div class="d-flex justify-content-between align-items-center item-cx"><span class="d-flex align-items-center"><?php $kgcx = ($i['unidade_cx'] ?? '') === 'kg'; if (!$kgcx): ?><button type="button" class="btn-plus-item" data-tipo="cmd" data-item="<?= $i['id'] ?>" data-cmd="<?= $sel['id'] ?>" title="Adicionar Item" style="border:0;background:none;color:#198754;font-size:1rem;padding:0 .5rem 0 0;line-height:1;cursor:pointer"><i class="bi bi-plus-lg"></i></button><?php endif; ?><span><?= e($kgcx ? qtdFmt($i['quantidade']) : str_pad((string) (int) $i['quantidade'], 2, '0', STR_PAD_LEFT)) ?><b class="sep-cx">-</b><?= e($i['nome']) ?></span></span><span class="text-nowrap"><?= brl($i['subtotal']) ?><?php if ($isAdm): ?><form method="post" class="d-inline" onsubmit="return confirmarForm(this, <?= e(json_encode('Excluir ' . qtdFmt($i['quantidade']) . ' ' . $i['nome'] . ' desta comanda?', JSON_UNESCAPED_UNICODE)) ?>)"><?= csrf_field() ?><input type="hidden" name="acao" value="remover_item"><input type="hidden" name="comanda_id" value="<?= $sel['id'] ?>"><input type="hidden" name="item_id" value="<?= $i['id'] ?>"><button class="btn-x-item" title="Excluir item (administrador)"><i class="bi bi-x-lg"></i></button></form><?php endif; ?><?php if (!$kgcx): ?><button type="button" class="btn-minus-item" data-tipo="cmd" data-item="<?= $i['id'] ?>" data-cmd="<?= $sel['id'] ?>" data-delta="-1" title="Diminuir Item"<?= (int) $i['quantidade'] <= 1 ? ' disabled' : '' ?>><i class="bi bi-dash-lg"></i></button><?php endif; ?></span></div><?php endforeach; ?>
        </div>
        <div class="total-linha"><span>Total</span><span class="total-valor"><?= brl($sel['total']) ?></span></div>
        <?php if ($credito > 0): ?><div class="credito-parcial small">
          <?php foreach ($pgParc as $pg): ?><div class="d-flex justify-content-between"><span>Pago parcial (<?= e($pg['forma']) ?>)</span><span>- <?= brl($pg['valor']) ?></span></div><?php endforeach; ?>
          <div class="d-flex justify-content-between fw-bold fs-5"><span>Falta pagar</span><span><?= brl($restante) ?></span></div>
        </div><?php endif; ?>
        <form method="post" id="fPag">
          <?= csrf_field() ?><input type="hidden" name="acao" value="receber"><input type="hidden" name="comanda_id" value="<?= $sel['id'] ?>">
          <?= $blocoPag(true, $itens ? 1 : 0) ?>
        </form>
        <div class="atalhos-dica small muted mt-1">F1–F4 forma • F5 estoque • F6 produto • F11 venda direta • Enter adiciona valor • ↑↓ (PgUp/PgDn) troca comanda • Esc fecha</div>
        <form method="post" id="fParcial"><?= csrf_field() ?><input type="hidden" name="acao" value="pagamento_parcial"><input type="hidden" name="comanda_id" value="<?= $sel['id'] ?>"><input type="hidden" name="forma" id="ppForma"><input type="hidden" name="valor" id="ppValor"></form>
        <form method="post" onsubmit="return confirmarForm(this, 'Deseja realmente imprimir a comanda?')" class="mt-2"><?= csrf_field() ?><input type="hidden" name="acao" value="imprimir_comanda"><input type="hidden" name="comanda_id" value="<?= $sel['id'] ?>"><button class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-printer"></i> Imprimir comanda <kbd class="atalho">F10</kbd></button></form>
        <form method="post" data-confirm="Cancelar esta comanda?" class="mt-1"><?= csrf_field() ?><input type="hidden" name="acao" value="cancelar"><input type="hidden" name="comanda_id" value="<?= $sel['id'] ?>"><button class="btn btn-link text-danger w-100" id="btCancelarCmd">Cancelar comanda <kbd class="atalho">Ctrl+Del</kbd></button></form>
      </div>
      <?php $totJs = 'function(){return ' . max(0, $restante) . ';}'; ?>
    <?php elseif ($venda): ?>
      <div class="card-r painel-receber">
        <h2>Venda direta</h2>
        <div class="faixa-comanda">VENDA DIRETA • SEM COMANDA</div>
        <div class="small itens-receber" id="vdItens"></div>
        <div class="total-linha"><span>Total</span><span class="total-valor" id="vdTotal">R$ 0,00</span></div>
        <form method="post" id="fPag">
          <?= csrf_field() ?><input type="hidden" name="acao" value="venda_direta_finalizar"><input type="hidden" name="itens" id="vdItensJson" value="[]">
          <?= $blocoPag(false, 0) ?>
        </form>
        <div class="atalhos-dica small muted mt-1">F1–F4 forma • F5 estoque • F6 produto • Enter adiciona valor • F9 receber • Esc fecha</div>
        <button type="button" class="btn btn-link text-danger w-100 mt-1" id="btCancelarCmd">Cancelar venda <kbd class="atalho">Ctrl+Del</kbd></button>
      </div>
      <?php $totJs = 'function(){return window.vdSoma?window.vdSoma():0;}'; ?>
    <?php else: ?>
      <div class="card-r text-center muted" style="border-style:dashed">Selecione uma comanda para receber ou clique em Venda direta</div>
    <?php endif; ?>
    <?php if ($sel || $venda) $scripts = '<script>(function(){var TOTF=' . $totJs . ',VENDA=' . ($venda ? 'true' : 'false') . ';' . <<<'JS'
var f=document.getElementById("fPag"),val=document.getElementById("pgValor"),lista=document.getElementById("pgLista"),res=document.getElementById("pgResumo"),hid=document.getElementById("pgHidden"),btn=document.getElementById("pgFinal"),pags=[];
function r2(n){return Math.round(n*100)/100;}
function fmt(n){return "R$ "+n.toFixed(2).replace(".",",").replace(/\B(?=(\d{3})+(?!\d))/g,".");}
function num(s){s=(s||"").trim();if(s.indexOf(",")>=0)s=s.replace(/\./g,"").replace(",",".");return parseFloat(s)||0;}
function pago(){return r2(pags.reduce(function(s,p){return s+p.v;},0));}
function falta(){return r2(Math.max(0,TOTF()-pago()));}
function forma(){var x=f.querySelector("input[name=forma_sel]:checked");return x?x.value:"Dinheiro";}
function render(){var fl=falta(),pg=pago(),troco=r2(Math.max(0,pg-TOTF()));
lista.innerHTML="";hid.innerHTML="";
pags.forEach(function(p,i){var d=document.createElement("div");d.className="d-flex justify-content-between align-items-center border-bottom py-1";d.innerHTML="<span>"+p.f+"</span><span><b>"+fmt(p.v)+"</b> <button type='button' class='btn btn-sm btn-link text-danger p-0 ms-2' data-i='"+i+"'>remover</button></span>";lista.appendChild(d);
hid.insertAdjacentHTML("beforeend","<input type='hidden' name='pg_forma[]' value='"+p.f+"'><input type='hidden' name='pg_valor[]' value='"+p.v.toFixed(2)+"'>");});
res.innerHTML=TOTF()<=0?"":(fl>0?"<div class='fw-bold' style='color:var(--brasa)'>Falta receber: "+fmt(fl)+"</div>":"<div class='fw-bold text-success'>Valor total recebido"+(troco>0?" • Troco: "+fmt(troco):"")+"</div>");
btn.disabled=!(fl<=0&&pags.length&&btn.dataset.itens==="1");
if(document.activeElement!==val)val.value=fl>0?fl.toFixed(2).replace(".",","):"";}
window.pgRender=function(){if(pago()>TOTF()+0.004&&pags.length){pags=[];mostrarAviso("O total mudou: adicione o pagamento novamente.");}render();};
function add(){var v=r2(num(val.value)),fm=forma(),fl=falta();
if(TOTF()<=0){mostrarAviso("Adicione produtos antes de receber.");return;}
if(fl<=0){mostrarAviso("O valor total já foi recebido.");return;}
if(v<=0){mostrarAviso("Digite o valor recebido.");return;}
if(v>fl&&fm!=="Dinheiro"){mostrarAviso("Em "+fm+" o valor não pode passar do restante ("+fmt(fl)+"). Troco só em dinheiro.");return;}
pags.push({f:fm,v:v});val.value="";render();if(falta()>0){mostrarAviso("Recebido "+fmt(v)+" em "+fm+". Ainda faltam "+fmt(falta())+": escolha outra forma de pagamento.");}}
var bp=document.getElementById("pgParcial");if(bp)bp.addEventListener("click",function(){var tot=TOTF(),v=r2(num(val.value)),fm=forma();if(pags.length){mostrarAviso("Remova os valores adicionados antes de lançar um pagamento parcial.");return;}if(v<=0){mostrarAviso("Digite o valor do pagamento parcial.");val.focus();return;}if(v>=tot){mostrarAviso("O pagamento parcial deve ser menor que o restante ("+fmt(tot)+"). Para pagar tudo use Receber e imprimir.");return;}
var fp=document.getElementById("fParcial");document.getElementById("ppForma").value=fm;document.getElementById("ppValor").value=v.toFixed(2);confirmarForm(fp,"Confirmar pagamento parcial de "+fmt(v)+" em "+fm+"? Faltará pagar "+fmt(r2(tot-v))+".");});
document.getElementById("pgAdd").addEventListener("click",add);
val.addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();add();}});
lista.addEventListener("click",function(e){var i=e.target.getAttribute("data-i");if(i!==null){pags.splice(+i,1);render();}});
f.addEventListener("submit",function(e){e.preventDefault();if(falta()>0||!pags.length){mostrarAviso("Pagamento incompleto: ainda faltam "+fmt(falta())+". Receba o restante por outra forma de pagamento.");return;}confirmarForm(f,VENDA?"Deseja realmente receber esta venda direta?":"Deseja realmente receber esta comanda?");});
render();})();</script>
JS; ?>
    </div>
  </div>
<?php endif;
$scripts = ($scripts ?? '');
if ($caixa && ($sel || $venda)) $scripts .= '<script>var VENDA_CX=' . ($venda ? 'true' : 'false') . ',PRODS_CX=' . json_encode(array_map(fn($p) => ['id' => $p['id'], 'n' => $p['nome'], 'c' => (string) $p['codigo'], 'g' => (string) $p['categoria'], 'p' => $p['preco'], 'u' => $p['unidade'], 'f' => $p['foto']], produtosLancador()), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';' . <<<'JS'
(function(){var f=document.getElementById("fAddProd"),i=document.getElementById("codProd"),box=document.getElementById("sugProd");if(!f)return;var lista=[],at=-1;
function norm(t){return (t||"").toString().normalize("NFD").replace(/[\u0300-\u036f]/g,"").toLowerCase();}
function brl(v){return "R$ "+Number(v).toFixed(2).replace(".",",");}
function esc(t){return String(t).replace(/[&<>"]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;"}[c];});}
function lerPeso(t){t=(t||"").trim();if(/[.,]/.test(t))return parseFloat(t.replace(",","."))||0;var n=parseInt(t,10)||0;return n>=10?n/1000:n;}
function qfmt(q,u){return u==="kg"?q.toFixed(3).replace(".",",")+" kg":String(q);}
function confirmar(msg,cb){mostrarAviso(msg,{confirmar:true,textoConfirmar:"Sim",textoCancelar:"Não",aoConfirmar:cb});}
function porRef(r){var m=/^#(\d+)$/.exec(r);return PRODS_CX.filter(function(p){return m?String(p.id)===m[1]:(p.c&&p.c===r);})[0];}
/* ---------- Venda direta: carrinho só na tela do caixa (sem comanda) ---------- */
var CK="vdCarrinho",cart=[];
if(VENDA_CX){try{cart=JSON.parse(localStorage.getItem(CK)||"[]")||[];}catch(e){cart=[];}
 window.vdSoma=function(){return Math.round(cart.reduce(function(s,l){return s+Math.round(l.p*l.q*100)/100;},0)*100)/100;};
 var vi=document.getElementById("vdItens"),vt=document.getElementById("vdTotal"),vj=document.getElementById("vdItensJson"),bf=document.getElementById("pgFinal");
 window.vdRender=function(){localStorage.setItem(CK,JSON.stringify(cart));
  vi.innerHTML=cart.length?cart.map(function(l,k){return '<div class="d-flex justify-content-between align-items-center item-cx"><span class="d-flex align-items-center">'+(l.u==="kg"?'':'<button type="button" class="btn-plus-item" data-tipo="vd" data-k="'+k+'" title="Adicionar Item" style="border:0;background:none;color:#198754;font-size:1rem;padding:0 .5rem 0 0;line-height:1;cursor:pointer"><i class="bi bi-plus-lg"></i></button>')+'<span>'+esc(l.u==="kg"?qfmt(l.q,l.u):String(l.q).padStart(2,"0"))+'<b class="sep-cx">-</b>'+esc(l.n)+'</span></span><span class="text-nowrap">'+brl(Math.round(l.p*l.q*100)/100)+'<button type="button" class="btn-x-item" data-k="'+k+'" title="Remover item"><i class="bi bi-x-lg"></i></button>'+(l.u!=="kg"?'<button type="button" class="btn-minus-item" data-tipo="vd" data-k="'+k+'" title="Diminuir Item"'+(l.q<=1?' disabled':'')+'><i class="bi bi-dash-lg"></i></button>':'')+'</span></div>';}).join(""):'<p class="muted mb-0">Adicione produtos pelo campo de busca ou pelo botão Produtos (F5).</p>';
  vt.textContent=brl(window.vdSoma());vj.value=JSON.stringify(cart.map(function(l){return {id:l.id,q:l.q};}));bf.dataset.itens=cart.length?"1":"0";if(window.pgRender)window.pgRender();};
 vi.addEventListener("click",function(e){var b=e.target.closest(".btn-x-item");if(!b)return;var l=cart[+b.dataset.k];confirmar("Remover "+qfmt(l.q,l.u)+" "+l.n+" da venda?",function(){cart.splice(+b.dataset.k,1);window.vdRender();});});
 document.getElementById("btCancelarCmd").addEventListener("click",function(){if(!cart.length){location.href=BASE_URL+"/caixa";return;}confirmar("Cancelar esta venda direta?",function(){cart=[];localStorage.removeItem(CK);location.href=BASE_URL+"/caixa";});});
 setTimeout(window.vdRender,0);}

/* ---------- + verde / - vermelho: soma ou diminui 1 unidade (exceto kg) ---------- */
document.addEventListener("click",function(e){var b=e.target.closest(".btn-plus-item,.btn-minus-item");if(!b)return;e.preventDefault();
 if(b.dataset.tipo==="vd"){var ln=cart[+b.dataset.k];if(!ln||ln.u==="kg")return;
  if(b.classList.contains("btn-minus-item")){if(ln.q<=1)return;ln.q-=1;}else ln.q+=1;
  window.vdRender();var nb=document.querySelector('.btn-'+(b.classList.contains("btn-minus-item")?"minus":"plus")+'-item[data-tipo="vd"][data-k="'+b.dataset.k+'"]');if(nb)nb.focus();return;}
 var tk=document.querySelector('input[name="csrf"]');var fm=document.createElement("form");fm.method="post";fm.style.display="none";
 fm.innerHTML=(tk?'<input name="csrf" value="'+tk.value+'">':'')+'<input name="acao" value="ajustar_item"><input name="comanda_id" value="'+b.dataset.cmd+'"><input name="item_id" value="'+b.dataset.item+'"><input name="delta" value="'+(b.classList.contains("btn-minus-item")?-1:1)+'">';document.body.appendChild(fm);fm.submit();});
(function(){var qs=new URLSearchParams(location.search).get("qs");if(qs){var x=document.querySelector('.btn-plus-item[data-item="'+qs+'"]')||document.querySelector('.btn-minus-item[data-item="'+qs+'"]');if(x)x.focus();}})();
/* Adiciona produto: venda direta vai para o carrinho; comanda é gravada no servidor sem recarregar */
function avisar(msg,ok,sil){if(sil&&ok&&window.msgEstoque)window.msgEstoque(msg);else mostrarAviso(msg,{tempo:ok?1000:3000});}
function adicionar(p,q,sil){if(VENDA_CX){var l=p.u!=="kg"&&cart.filter(function(x){return x.id===p.id;})[0];if(l)l.q+=q;else cart.push({id:p.id,n:p.n,p:Number(p.p),u:p.u,q:q});window.vdRender();avisar(p.n+" adicionado: "+qfmt(q,p.u),true,sil);return Promise.resolve(true);}
 var fd=new FormData(f);fd.set("ajax","1");fd.set("codigo","#"+p.id+(p.u==="kg"?"*"+String(q).replace(".",","):""));
 return fetch(f.action||location.href,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(r){avisar(r.msg,r.ok,sil);return r.ok;}).catch(function(){mostrarAviso("Falha ao adicionar. Verifique a conexão.");return false;});}
function addTexto(t){var pr=t.split("*"),p=porRef(pr[0].trim());if(!p){mostrarAviso("Produto com código "+pr[0]+" não encontrado.");return;}
 if(p.u==="kg"){var w=lerPeso(pr[1]);if(w<=0){mostrarAviso("Produto por kg: digite "+pr[0]+"*peso (ex.: "+pr[0]+"*0,450) e Enter.");return;}adicionar(p,w);}else{if(pr.length>1){mostrarAviso(p.n+" não é vendido por kg.");return;}adicionar(p,1);}i.value="";}
function fechar(){box.style.display="none";lista=[];at=-1;}
function render(){if(!lista.length){fechar();return;}box.innerHTML=lista.map(function(p,k){return '<div class="sug-item'+(k===at?' ativo':'')+'" data-k="'+k+'"><span><b>'+esc(p.c||"-")+'</b> '+esc(p.n)+(p.u==="kg"?' <small>(kg)</small>':'')+'</span><span>'+brl(p.p)+(p.u==="kg"?'/kg':'')+'</span></div>';}).join("");box.style.display="";var el=box.querySelector(".ativo");if(el)el.scrollIntoView({block:"nearest"});}
function buscar(){var t=i.value.trim();if(!t||t.indexOf("*")>=0){fechar();return;}var q=norm(t);
lista=PRODS_CX.filter(function(p){return p.c===t||norm(p.c).indexOf(q)===0||norm(p.n).indexOf(q)>=0;}).sort(function(a,b){return (b.c===t)-(a.c===t)||(norm(b.n).indexOf(q)===0)-(norm(a.n).indexOf(q)===0)||a.n.localeCompare(b.n);}).slice(0,12);at=lista.length?0:-1;render();}
function escolher(p){fechar();var ref=p.c?p.c:"#"+p.id;if(p.u==="kg"){i.value=ref+"*";i.focus();mostrarAviso&&mostrarAviso("Digite o peso de "+p.n+" (ex.: 0,450) e Enter");return;}if(VENDA_CX){adicionar(p,1);i.value="";return;}i.value=ref;f.submit();}
if(VENDA_CX)f.addEventListener("submit",function(e){e.preventDefault();var t=i.value.trim();if(t)addTexto(t);});
i.addEventListener("input",buscar);
i.addEventListener("keydown",function(e){if(e.key==="ArrowDown"&&lista.length){e.preventDefault();at=(at+1)%lista.length;render();}
else if(e.key==="ArrowUp"&&lista.length){e.preventDefault();at=(at-1+lista.length)%lista.length;render();}
else if(e.key==="Escape"){fechar();}
else if(e.key==="Enter"){var t=i.value.trim();if(!t){e.preventDefault();return;}if(t.indexOf("*")>=0)return;var ex=PRODS_CX.filter(function(p){return p.c===t;})[0];if(ex){e.preventDefault();escolher(ex);return;}if(lista.length&&at>=0){e.preventDefault();escolher(lista[at]);}}});
box.addEventListener("mousedown",function(e){var d=e.target.closest(".sug-item");if(d){e.preventDefault();escolher(lista[+d.dataset.k]);}});
i.addEventListener("blur",function(){setTimeout(fechar,150);});
/* ---------- Janela "Adicionar produtos do estoque": fotos + categorias; só fecha no X ou Esc ---------- */
var bt=document.getElementById("btEstoque");if(bt){var m=document.createElement("div");m.className="modal-estoque";m.style.display="none";
m.innerHTML='<div class="me-caixa"><div class="me-topo"><b>Adicionar produtos do estoque</b><span class="me-msg"></span><button type="button" class="me-x" title="Fechar (Esc)">&times;</button></div><input class="form-control mb-2 me-busca" placeholder="Buscar por nome, código ou categoria"><div class="me-cats"></div><div class="me-peso" style="display:none"><span class="me-peso-nome"></span><input class="form-control me-peso-in" inputmode="decimal" placeholder="Peso (ex.: 0,450)"><button type="button" class="btn btn-success me-peso-ok">Adicionar</button><button type="button" class="btn btn-outline-secondary me-peso-cancel">Cancelar</button></div><div class="me-grade"></div></div>';document.body.appendChild(m);
var g=m.querySelector(".me-grade"),bq=m.querySelector(".me-busca"),cs=m.querySelector(".me-cats"),pz=m.querySelector(".me-peso"),pzIn=m.querySelector(".me-peso-in"),cat="",pesoP=null,mudou=false;
var CATS=[];PRODS_CX.forEach(function(p){if(p.g&&CATS.indexOf(p.g)<0)CATS.push(p.g);});
function rCats(){cs.innerHTML=['<button type="button" class="me-cat'+(cat===""?" ativo":"")+'" data-c="">Todos</button>'].concat(CATS.map(function(c){return '<button type="button" class="me-cat'+(cat===c?" ativo":"")+'" data-c="'+esc(c)+'">'+esc(c)+'</button>';})).join("");}
function lst(){var q=norm(bq.value.trim());g.innerHTML=PRODS_CX.filter(function(p){return (!cat||p.g===cat)&&(!q||norm(p.n).indexOf(q)>=0||norm(p.c).indexOf(q)===0||norm(p.g).indexOf(q)>=0);}).map(function(p){return '<button type="button" class="me-prod" data-id="'+p.id+'">'+(p.f?'<img src="'+esc(p.f)+'" alt="" loading="lazy">':'')+'<span>'+esc(p.n)+'</span><small>'+esc(p.g)+'</small><b>'+brl(p.p)+(p.u==="kg"?'/kg':'')+'</b></button>';}).join("")||'<p class="muted">Nenhum produto.</p>';}
function abrir(){m.style.display="";bq.value="";cat="";mudou=false;pz.style.display="none";rCats();lst();bq.focus();}
function fch(){m.style.display="none";pz.style.display="none";if(mudou&&!VENDA_CX)location.reload();}
var mm=m.querySelector(".me-msg"),mt;window.msgEstoque=function(t){mm.textContent="✔ "+t;mm.classList.add("on");clearTimeout(mt);mt=setTimeout(function(){mm.classList.remove("on");},2500);};
window.estoqueAberto=function(){return m.style.display!=="none";};
bt.addEventListener("click",abrir);m.querySelector(".me-x").addEventListener("click",fch);
document.addEventListener("keydown",function(e){if(e.key==="Escape"&&window.estoqueAberto()&&!document.querySelector(".aviso-confirmacao")){e.preventDefault();e.stopImmediatePropagation();if(pz.style.display!=="none"){pz.style.display="none";bq.focus();}else fch();}},true);
bq.addEventListener("input",lst);cs.addEventListener("click",function(e){var b=e.target.closest(".me-cat");if(!b)return;cat=b.dataset.c;rCats();lst();});
function addM(p,q){adicionar(p,q,true).then(function(ok){if(ok)mudou=true;});}
g.addEventListener("click",function(e){var b=e.target.closest(".me-prod");if(!b)return;var p=PRODS_CX.filter(function(x){return x.id==b.dataset.id;})[0];
 if(p.u==="kg"){pesoP=p;m.querySelector(".me-peso-nome").textContent="Peso de "+p.n+" ("+brl(p.p)+"/kg):";pzIn.value="";pz.style.display="";pzIn.focus();return;}
 addM(p,1);b.classList.add("ok");setTimeout(function(){b.classList.remove("ok");},400);});
function okPeso(){var w=lerPeso(pzIn.value);if(w<=0){mostrarAviso("Digite o peso (ex.: 0,450).");pzIn.focus();return;}addM(pesoP,w);pz.style.display="none";bq.focus();}
m.querySelector(".me-peso-ok").addEventListener("click",okPeso);m.querySelector(".me-peso-cancel").addEventListener("click",function(){pz.style.display="none";});
pzIn.addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();okPeso();}});
window.abrirEstoqueCx=abrir;}
})();
JS . '</script>';
if ($caixa && !empty($_GET['vdok'])) $scripts .= '<script>localStorage.removeItem("vdCarrinho");</script>';
if ($caixa) $scripts .= '<script>' . <<<'JS'
(function(){
function clicar(id){var b=document.getElementById(id);if(b&&!b.disabled){b.click();return true;}return false;}
function forma(n){var r=document.querySelectorAll("#fPag input[name=forma_sel]")[n];if(!r)return;r.checked=true;r.dispatchEvent(new Event("change",{bubbles:true}));var v=document.getElementById("pgValor");if(v){v.focus();v.select();}}
function trocar(d){var cs=[].slice.call(document.querySelectorAll(".grid-cmd .cmd-card"));if(!cs.length)return;var i=cs.findIndex(function(c){return c.classList.contains("sel");});i=i<0?0:(i+d+cs.length)%cs.length;location.href=cs[i].href;}
document.addEventListener("keydown",function(e){
 if(document.querySelector(".aviso-confirmacao")||(window.estoqueAberto&&window.estoqueAberto()))return;
 var k=e.key,ok=true,tag=(document.activeElement||{}).tagName,emCampo=tag==="INPUT"||tag==="TEXTAREA";
 if(/^F[1-4]$/.test(k))forma(+k.slice(1)-1);
 else if(k==="F6"){var c=document.getElementById("codProd");if(c){c.focus();c.select();}}
 else if(k==="F7")clicar("btNovaCmd");
 else if(k==="F5"&&window.abrirEstoqueCx)window.abrirEstoqueCx();
 else if(k==="F11")clicar("btVendaDireta");
 else if(k==="F8")clicar("pgParcial");
 else if(k==="F9"){var b=document.getElementById("pgFinal");if(b&&b.disabled)mostrarAviso("Adicione o pagamento até completar o valor antes de receber (F9).");else if(b)b.click();}
 else if(k==="F10"){var p=document.querySelector("button .bi-printer")&&[].slice.call(document.querySelectorAll("form button")).filter(function(x){return /Imprimir comanda/.test(x.textContent);})[0];if(p)p.click();}
 else if(k==="Delete"&&e.ctrlKey)clicar("btCancelarCmd");
 else if(k==="PageDown"||(k==="ArrowDown"&&e.altKey))trocar(1);
 else if(k==="PageUp"||(k==="ArrowUp"&&e.altKey))trocar(-1);
 else if((k==="ArrowDown"||k==="ArrowUp")&&!emCampo)trocar(k==="ArrowDown"?1:-1);
 else ok=false;
 if(ok)e.preventDefault();
});
})();
JS . '</script>';
if ($caixa) $scripts .= '<script>(function(){var b=document.getElementById("btNovaCmd"),f=document.getElementById("fNovaCmd");if(!b)return;b.addEventListener("click",function(){f.style.display="";document.getElementById("ncIdent").focus();});document.getElementById("ncCancel").addEventListener("click",function(){f.style.display="none";f.reset();});})();</script>';
if ($caixa) $scripts .= '<script src="' . url('assets/atualizar.js?v=2') . '"></script>';
// Comprovante vai direto para a impressora do caixa pelo Monitor de impressão; avisa se o Monitor não imprimir
if (!empty($_GET['comprovante'])) $scripts .= "<script>(function(){var job=" . (int) $_GET['comprovante'] . ",n=0;history.replaceState(null,'','caixa.php');var t=setInterval(function(){n++;fetch(BASE_URL+'/api/fila_impressao.php?job='+job,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.ok?r.json():null;}).then(function(r){if(r&&r.impresso){clearInterval(t);}else if(n>=10){clearInterval(t);mostrarAviso('Comprovante aguardando impressão. Abra o Monitor de impressão no computador da impressora do caixa.',{tempo:5000});}}).catch(function(){});},1000);})();</script>";
if (!empty($_GET['fechamento'])) $scripts .= "<script>imprimirUrl('imprimir/fechamento.php?id=" . (int) $_GET['fechamento'] . "');history.replaceState(null,'','caixa.php');</script>";
require __DIR__ . '/includes/footer.php';
