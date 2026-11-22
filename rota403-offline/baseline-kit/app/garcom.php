<?php

require_once __DIR__ . '/config/config.php';
$u = Auth::exigir(['caixa', 'garcom']);

$c = null;
if (!empty($_GET['codigo'])) {
    $c = Database::one("SELECT * FROM comandas WHERE codigo = ? AND status = 'aberta'", [strtoupper(trim($_GET['codigo']))]);
    if (!$c) { flash('Comanda não encontrada ou já fechada.', 'danger'); redirect('garcom.php'); }
} elseif (!empty($_GET['id'])) {
    $c = Database::one("SELECT * FROM comandas WHERE id = ? AND status = 'aberta'", [(int) $_GET['id']]);
}
$itensComanda = $c ? Database::all('SELECT * FROM comanda_itens WHERE comanda_id = ? ORDER BY id DESC', [$c['id']]) : [];
if ($c && isset($_GET['enviado'])) flash('Pedido enviado para impressão na cozinha/balcão!');
$comandas = $c ? [] : Database::all("SELECT * FROM comandas WHERE status = 'aberta' ORDER BY id");
$titulo = 'Pedidos';
require __DIR__ . '/includes/header.php';
?>
<h1>Pedidos</h1>
<?php if (!$c): ?>
  <button type="button" id="btnCamera" class="btn btn-warning btn-lg w-100 mb-3"><i class="bi bi-camera"></i> Ler comanda com a câmera</button>
  <div id="cameraBox" class="card-r p-2 mb-3" style="display:none">
    <div id="cameraLeitor" style="width:100%"></div>
    <button type="button" id="btnFecharCamera" class="btn btn-outline-secondary w-100 mt-2"><i class="bi bi-x-lg"></i> Fechar câmera</button>
  </div>
  <p class="muted">Ou escolha a comanda:</p>
  <div class="grid-cmd">
    <?php foreach ($comandas as $x): ?>
      <a class="cmd-card" href="<?= url('garcom.php?id=' . $x['id']) ?>"><div class="nome"><?= e(nomeComanda($x)) ?></div><div class="small muted">Nº <?= $x['id'] ?> • <?= brl($x['total']) ?></div></a>
    <?php endforeach; ?>
    <?php if (!$comandas): ?><p class="muted">Nenhuma comanda aberta.</p><?php endif; ?>
  </div>
<?php else: ?>
  <div class="barra-comanda mb-2">
    <div><div class="small opacity-75">Comanda Nº <?= $c['id'] ?> • <?= brl($c['total']) ?></div><div class="display fs-2"><?= e(nomeComanda($c)) ?></div></div>
    <a href="<?= url('garcom.php') ?>" class="btn btn-outline-light"><i class="bi bi-x-lg"></i></a>
  </div>
  <p class="small muted">Lembre de marcar os quadrados na comanda de papel da mesa.</p>
  <?php if ($u['nivel'] === 'admin'): ?>
  <h5 class="mb-2"><i class="bi bi-card-checklist"></i> Itens já pedidos (<?= count($itensComanda) ?>)</h5>
  <div class="card-r p-0 overflow-hidden mb-3">
    <?php if ($itensComanda): ?>
      <div class="lista-itens-comanda">
        <?php foreach ($itensComanda as $i): ?>
          <div class="item-comanda-mobile">
            <div class="item-quantidade"><?= (int) $i['quantidade'] ?>x</div>
            <div class="item-detalhes"><strong><?= e($i['nome']) ?></strong><?php if ($i['observacao']): ?><span>» <?= e($i['observacao']) ?></span><?php endif; ?><small><?= dataHora($i['adicionado_em']) ?><?= $i['garcom'] ? ' • ' . e($i['garcom']) : '' ?></small></div>
            <div class="item-valor"><?= brl($i['subtotal']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="total-comanda-mobile"><span>Total da comanda</span><strong><?= brl($c['total']) ?></strong></div>
    <?php else: ?><p class="muted text-center p-4 mb-0">Nenhum item pedido ainda.</p><?php endif; ?>
  </div>
  <?php endif; ?>
  <h5 class="mb-2"><i class="bi bi-plus-circle"></i> Adicionar itens</h5>
  <div id="lancador"></div>
<?php endif;
$scripts = '';
if (!$c) {
    $scripts = '<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script><script>(function(){var leitor=null,box=document.getElementById("cameraBox");function parar(){if(leitor){leitor.stop().catch(function(){}).then(function(){leitor.clear();leitor=null;});}box.style.display="none";}document.getElementById("btnFecharCamera").onclick=parar;document.getElementById("btnCamera").onclick=function(){if(typeof Html5Qrcode==="undefined"){mostrarAviso("Leitor da câmera não carregou. Verifique a internet.");return;}if(!window.isSecureContext){mostrarAviso("A câmera só funciona com o site em https://");return;}box.style.display="block";leitor=new Html5Qrcode("cameraLeitor",{formatsToSupport:[Html5QrcodeSupportedFormats.CODE_128,Html5QrcodeSupportedFormats.QR_CODE],experimentalFeatures:{useBarCodeDetectorIfSupported:true}});leitor.start({facingMode:"environment"},{fps:12,qrbox:function(w,h){return {width:Math.floor(w*0.9),height:Math.floor(Math.min(h,w)*0.45)};}},function(txt){txt=(txt||"").trim().toUpperCase();if(!/^CMD\\d+$/.test(txt))return;leitor.stop().then(function(){location.href=BASE_URL+"/garcom.php?codigo="+encodeURIComponent(txt);});},function(){}).catch(function(){box.style.display="none";leitor=null;mostrarAviso("Não foi possível abrir a câmera. Permita o acesso à câmera no navegador.");});};})();</script>';
}
if ($c) {
    $produtos = array_map(fn($p) => ['id' => (int) $p['id'], 'nome' => $p['nome'], 'preco' => (float) $p['preco'], 'codigo' => $p['codigo_barras'], 'categoria' => $p['categoria'], 'estoque' => (int) $p['estoque'], 'controlar' => (int) $p['controlar_estoque']], listarProdutos(true));
    $scripts = '<script>window.PRODUTOS=' . json_encode($produtos, JSON_UNESCAPED_UNICODE) . ';</script><script src="' . url('assets/lancador.js?v=6') . '"></script>' .
        '<script>(function(){Lancador(document.getElementById("lancador"), ' . (int) $c['id'] . ', ' . json_encode($u['nome']) . ', function(){ location.href = BASE_URL + "/garcom.php?id=' . (int) $c['id'] . '&enviado=1"; });})();</script>';
}
require __DIR__ . '/includes/footer.php';
