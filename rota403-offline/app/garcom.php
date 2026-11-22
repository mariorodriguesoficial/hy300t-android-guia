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
if ($c && isset($_GET['enviado'])) flash('Pedido enviado para impressão na preparo!');
$comandas = $c ? [] : Database::all("SELECT * FROM comandas WHERE status = 'aberta' ORDER BY tipo = 'cliente', CASE WHEN identificacao REGEXP '^[0-9]+$' THEN CAST(identificacao AS UNSIGNED) ELSE 999999 END, identificacao, id");
$titulo = 'Pedidos';
require __DIR__ . '/includes/header.php';
?>
<h1>Pedidos</h1>
<button type="button" id="btnInstalarApp" class="btn btn-outline-primary w-100 mb-3" hidden><i class="bi bi-download"></i> Instalar o app na tela inicial</button>
<?php if (!$c): ?>
  <button type="button" id="btnCamera" class="btn btn-warning btn-lg w-100 mb-2"><i class="bi bi-camera"></i> Ler comanda com a câmera</button>
  <div id="cameraBox" class="card-r p-2 mb-3" style="display:none">
    <div id="cameraLeitor" style="width:100%"></div>
    <button type="button" id="btnFecharCamera" class="btn btn-outline-secondary w-100 mt-2"><i class="bi bi-x-lg"></i> Fechar câmera</button>
  </div>
  <p class="muted mb-1">Escolha a comanda:</p>
  <input type="search" class="form-control form-control-lg mb-3 busca-cmd" placeholder="Procurar mesa, cliente ou nº" autocomplete="off" oninput="filtrarComandas(this)">
  <div class="grid-cmd">
    <?php foreach ($comandas as $x): ?>
      <div class="cmd-item" data-busca="<?= e(mb_strtolower(nomeComanda($x) . ' ' . $x['id'])) ?>"><a class="cmd-card" href="<?= url('garcom.php?id=' . $x['id']) ?>">
        <div class="cmd-topo"><span class="cmd-tipo"><?= $x['tipo'] === 'mesa' ? 'MESA' : 'CLIENTE' ?></span><span class="small muted">Nº <?= $x['id'] ?></span></div>
        <div class="nome"><?= e($x['identificacao']) ?></div>
        <div class="cmd-rodape"><span class="valor"><?= brl($x['total']) ?></span><span class="small muted"><?= date('H:i', strtotime($x['criado_em'])) ?></span></div></a></div>
    <?php endforeach; ?>
    <?php if (!$comandas): ?><p class="muted">Nenhuma comanda aberta.</p><?php endif; ?>
  </div>
<?php else: ?>
  <div class="barra-comanda mb-2">
    <div><div class="small opacity-75">Comanda Nº <?= $c['id'] ?> • <?= brl($c['total']) ?></div><div class="display fs-2"><?= e(nomeComanda($c)) ?></div></div>
    <a href="<?= url('garcom.php') ?>" class="btn btn-sair-comanda" aria-label="Sair da comanda"><i class="bi bi-x-lg"></i></a>
  </div>
  <div class="passo-titulo"><span class="passo-n">1</span> Escolha os produtos e toque em <b>Enviar pedido</b></div>
  <div id="lancador" class="mb-4"></div>
  <?php if ($u['nivel'] === 'admin'): ?>
  <details class="itens-pedidos mb-3">
  <summary><span><i class="bi bi-card-checklist"></i> Já pedido nesta comanda (<?= count($itensComanda) ?> itens)</span><strong><?= brl($c['total']) ?></strong></summary>
  <div class="card-r p-0 overflow-hidden mt-2">
    <?php if ($itensComanda): ?>
      <div class="lista-itens-comanda">
        <?php foreach ($itensComanda as $i): ?>
          <div class="item-comanda-mobile">
            <div class="item-quantidade"><?= e(qtdFmt($i['quantidade'])) ?></div>
            <div class="item-detalhes"><strong><?= e($i['nome']) ?></strong><?php if ($i['observacao']): ?><span>» <?= e($i['observacao']) ?></span><?php endif; ?><small><?= dataHora($i['adicionado_em']) ?><?= $i['garcom'] ? ' • ' . e($i['garcom']) : '' ?></small></div>
            <div class="item-valor"><?= brl($i['subtotal']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="total-comanda-mobile"><span>Total da comanda</span><strong><?= brl($c['total']) ?></strong></div>
    <?php else: ?><p class="muted text-center p-4 mb-0">Nenhum item pedido ainda.</p><?php endif; ?>
  </div>
  </details>
  <?php endif; ?>
<?php endif;
$scripts = '';
if (!$c) {   // v23 desativava a câmera; reativada: só funciona em contexto seguro (HTTPS/localhost) e avisa quando não for
    $scripts = '<script>(function(){if(!window.isSecureContext){var b=document.getElementById("btnCamera");if(b){b.disabled=true;b.innerHTML=\'<i class="bi bi-camera-video-off"></i> Câmera indisponível (precisa de HTTPS)\';}}})();</script><script src="' . url('assets/vendor/html5-qrcode/html5-qrcode.min.js') . '"></script><script>(function(){var leitor=null,box=document.getElementById("cameraBox");function parar(){if(leitor){leitor.stop().catch(function(){}).then(function(){leitor.clear();leitor=null;});}box.style.display="none";}document.getElementById("btnFecharCamera").onclick=parar;document.getElementById("btnCamera").onclick=function(){if(typeof Html5Qrcode==="undefined"){mostrarAviso("Leitor da câmera não carregou. Verifique a internet.");return;}if(!window.isSecureContext){mostrarAviso("A câmera só funciona com o site em https://");return;}box.style.display="block";leitor=new Html5Qrcode("cameraLeitor",{formatsToSupport:[Html5QrcodeSupportedFormats.CODE_128,Html5QrcodeSupportedFormats.QR_CODE],experimentalFeatures:{useBarCodeDetectorIfSupported:true}});leitor.start({facingMode:"environment"},{fps:12,qrbox:function(w,h){return {width:Math.floor(w*0.9),height:Math.floor(Math.min(h,w)*0.45)};}},function(txt){txt=(txt||"").trim().toUpperCase();if(!/^CMD\\d+$/.test(txt))return;leitor.stop().then(function(){location.href=BASE_URL+"/garcom.php?codigo="+encodeURIComponent(txt);});},function(){}).catch(function(){box.style.display="none";leitor=null;mostrarAviso("Não foi possível abrir a câmera. Permita o acesso à câmera no navegador.");});};})();</script>';
}
if ($c) {
    $produtos = produtosLancador();
    $scripts = '<script>window.PRODUTOS=' . json_encode($produtos, JSON_UNESCAPED_UNICODE) . ';</script><script src="' . url('assets/lancador.js?v=pwa1') . '"></script>' .
        '<script>(function(){Lancador(document.getElementById("lancador"), ' . (int) $c['id'] . ', ' . json_encode($u['nome']) . ', function(){ location.href = BASE_URL + "/garcom.php?id=' . (int) $c['id'] . '&enviado=1"; }, {resumo: true, rotulo: ' . json_encode(nomeComanda($c)) . '});})();</script>';
}
$scripts .= ($c ? '<script>window.ATUALIZAR_COMANDAS={comandaId:' . (int) $c['id'] . '};</script>' : '') . '<script src="' . url('assets/atualizar.js?v=2') . '"></script>';
require __DIR__ . '/includes/footer.php';
