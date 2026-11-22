<?php
require_once __DIR__ . '/config/config.php';
Auth::exigir(['caixa']);
$titulo = 'Impressão';
require __DIR__ . '/includes/header.php';
?>
<?php if (isset($_GET['popup'])): ?><script>document.body.classList.add('monitor-popup');</script><?php endif; ?>
<h1>Monitor de impressão</h1>
<div class="card-r mb-3">
  <p class="muted">Mantenha esta janela aberta, mesmo minimizada. Os pedidos são impressos automaticamente.</p>
  <div class="d-flex flex-wrap gap-3 align-items-center">
    <label class="form-check"><input type="checkbox" class="form-check-input" id="dCoz" checked> Cozinha</label>
    <label class="form-check"><input type="checkbox" class="form-check-input" id="dBal" checked> Balcão</label>
    <button class="btn btn-danger" id="btnAuto"><i class="bi bi-stop-circle"></i> Parar impressão automática</button>
    <span class="d-flex gap-2 align-items-center">Impressora térmica:
      <select id="qzImp" class="form-select form-select-sm" style="width:auto"><option value="">(janela do navegador)</option></select></span>
    <span id="qzStatus" class="small fw-semibold"></span>
    <span id="status" class="fw-semibold" style="color:var(--campo)"></span>
  </div>
</div>
<div id="lista"></div>
<?php
$scripts = <<<'JS'
<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js"></script>
<script>
(function () {
  var ativo = true, ocupado = false, falhas = 0;
  var dCoz = document.getElementById('dCoz'), dBal = document.getElementById('dBal');
  var saved = JSON.parse(localStorage.getItem('destinos') || '["cozinha","balcao"]');
  dCoz.checked = saved.indexOf('cozinha') >= 0; dBal.checked = saved.indexOf('balcao') >= 0;
  function destinos() { var d = []; if (dCoz.checked) d.push('cozinha'); if (dBal.checked) d.push('balcao'); localStorage.setItem('destinos', JSON.stringify(d)); return d; }
  function esc(s) { var x = document.createElement('div'); x.textContent = s == null ? '' : s; return x.innerHTML; }
  function requisicao(url, opcoes) {
    var controle = new AbortController();
    var timer = setTimeout(function () { controle.abort(); }, 10000);
    opcoes = opcoes || {}; opcoes.signal = controle.signal; opcoes.cache = 'no-store';
    return fetch(url, opcoes).finally(function () { clearTimeout(timer); });
  }
  function acaoPedido(id, acao) {
    return requisicao(BASE_URL + '/api/pedidos.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify({ id: id, acao: acao }) });
  }
  function acaoComanda(id, acao) {
    return requisicao(BASE_URL + '/api/comandas_pendentes.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify({ id: id, acao: acao }) });
  }
  // ===== QZ Tray: impressão direta sem janela (cai na janela do navegador se falhar) =====
  var qzSel = document.getElementById('qzImp'), qzSt = document.getElementById('qzStatus');
  function qzOk() { return typeof qz !== 'undefined'; }
  if (qzOk()) {
    // Certificado + assinatura: o QZ Tray reconhece o sistema como site confiável
    // e permite "Lembrar esta decisão" (Remember this decision).
    function textoOuErro(r) { return r.text().then(function (t) { if (!r.ok) throw new Error(t || ('Erro ' + r.status)); return t; }); }
    qz.security.setCertificatePromise(function (resolve, reject) {
      fetch(BASE_URL + '/api/qz_certificado.php', { cache: 'no-store' }).then(textoOuErro).then(resolve, reject);
    });
    qz.security.setSignatureAlgorithm('SHA512');
    qz.security.setSignaturePromise(function (paraAssinar) {
      return function (resolve, reject) {
        fetch(BASE_URL + '/api/qz_assinar.php', { method: 'POST', cache: 'no-store',
          headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify({ request: paraAssinar }) })
          .then(textoOuErro).then(resolve, reject);
      };
    });
  }
  function qzConectar() {
    if (!qzOk()) return Promise.reject(new Error('Biblioteca QZ não carregou'));
    if (qz.websocket.isActive()) return Promise.resolve();
    return qz.websocket.connect({ retries: 2, delay: 1 });
  }
  function qzListar() {
    qzConectar().then(function () { return qz.printers.find(); }).then(function (lista) {
      var salva = localStorage.getItem('qzImpressora') || '';
      qzSel.innerHTML = '<option value="">(janela do navegador)</option>' + lista.map(function (n) {
        return '<option' + (n === salva ? ' selected' : '') + '>' + esc(n) + '</option>'; }).join('');
      qzSt.style.color = 'green'; qzSt.textContent = '● QZ Tray conectado';
    }).catch(function () { qzSt.style.color = '#d9530f'; qzSt.textContent = '● QZ Tray não encontrado (usando janela do navegador)'; });
  }
  qzSel.onchange = function () { localStorage.setItem('qzImpressora', qzSel.value); };
  function htmlDaPagina(path) {
    return new Promise(function (resolve, reject) {
      var f = document.createElement('iframe');
      f.style.cssText = 'position:fixed;right:0;bottom:0;width:400px;height:10px;border:0;opacity:0';
      f.src = BASE_URL + '/' + path + (path.indexOf('?') >= 0 ? '&' : '?') + 'qz=1';
      var timer = setTimeout(function () { fim(new Error('Página de impressão não respondeu.')); }, 12000);
      function fim(erro, html) { clearTimeout(timer); window.removeEventListener('message', ok); f.remove(); erro ? reject(erro) : resolve(html); }
      function ok(e) { if (e.source === f.contentWindow && e.data && e.data.tipo === 'ROTA403_QZ_HTML') fim(null, e.data.html); }
      window.addEventListener('message', ok);
      document.body.appendChild(f);
    });
  }
  function imprimir(path) {
    var nome = qzSel.value;
    if (!nome || !qzOk()) return imprimirUrl(path);
    return qzConectar().then(function () { return htmlDaPagina(path); }).then(function (html) {
      return qz.print(qz.configs.create(nome, { units: 'mm', size: { width: 80 }, margins: 0, colorType: 'blackwhite' }),
        [{ type: 'pixel', format: 'html', flavor: 'plain', data: html, options: { pageWidth: 80 } }]);
    }).catch(function (erro) {
      console.warn('QZ falhou, usando janela do navegador:', erro);
      qzSt.style.color = '#d9530f'; qzSt.textContent = '● Falha no QZ Tray: ' + (erro && erro.message ? erro.message : erro);
      return imprimirUrl(path);
    });
  }
  qzListar();
  window.reimprimir = function (id) {
    imprimir('imprimir/pedido.php?id=' + id).then(function () {
      mostrarAviso('Pedido enviado novamente para a impressora.'); return carregar();
    }).catch(function (erro) { mostrarAviso(erro.message || 'Não foi possível imprimir.'); carregar(); });
  };
  function carregar() {
    return requisicao(BASE_URL + '/api/pedidos.php?destinos=' + destinos().join(',')).then(function (r) { if (!r.ok) throw new Error('Falha ao consultar pedidos.'); return r.json(); }).then(function (lista) {
      document.getElementById('lista').innerHTML = lista.map(function (p) {
        return '<div class="card-r d-flex gap-3 align-items-start mb-2 py-2"><span class="badge badge-' + p.destino + ' text-uppercase">' + p.destino + '</span>' +
          '<div class="flex-grow-1"><div class="fw-semibold">Comanda ' + p.comanda_id + ' — ' + esc(p.comanda) + '</div><div class="small">' +
          p.itens.map(function (i) { return i.quantidade + 'x ' + esc(i.nome) + (i.observacao ? ' (' + esc(i.observacao) + ')' : ''); }).join(', ') +
          '</div><div class="small muted">' + p.hora + (p.garcom ? ' • ' + esc(p.garcom) : '') + ' • ' + (p.impresso == 1 ? 'Impresso' : '<b class="text-danger">Pendente</b>') + '</div></div>' +
          (p.impresso == 1 ? '<button class="btn btn-sm btn-outline-primary" aria-label="Reimprimir pedido" onclick="reimprimir(' + p.id + ')"><i class="bi bi-printer"></i></button>' : '') + '</div>';
      }).join('') || '<p class="muted">Nenhum pedido ainda.</p>';
      falhas = 0; document.getElementById('status').textContent = ativo ? '● Aguardando pedidos…' : ''; return lista;
    });
  }
  function ciclo() {
    if (!ativo || ocupado) return;
    ocupado = true;
    requisicao(BASE_URL + '/api/comandas_pendentes.php').then(function (r) { return r.ok ? r.json() : []; }).then(function (ids) {
      return (ids || []).reduce(function (pr, id) {
        return pr.then(function () { return acaoComanda(id, 'reservar'); }).then(function (r) {
          if (!r.ok) return;
          return imprimir('imprimir/comanda.php?id=' + id).catch(function (erro) {
            return acaoComanda(id, 'liberar').then(function () { throw erro; });
          });
        });
      }, Promise.resolve());
    }).then(carregar).then(function (lista) {
      var pend = lista.filter(function (p) { return p.impresso == 0; });
      return pend.reduce(function (pr, p) {
        return pr.then(function () { return acaoPedido(p.id, 'reservar'); }).then(function (r) {
          if (!r.ok) return;
          return imprimir('imprimir/pedido.php?id=' + p.id).catch(function (erro) {
            return acaoPedido(p.id, 'liberar').then(function () { throw erro; });
          });
        });
      }, Promise.resolve());
    }).then(function () { ocupado = false; if (ativo) carregar(); }, function () {
      ocupado = false; falhas++; document.getElementById('status').textContent = 'Sem conexão — tentando novamente…';
    });
  }
  document.getElementById('btnAuto').addEventListener('click', function () {
    ativo = !ativo;
    this.className = ativo ? 'btn btn-danger' : 'btn btn-ember';
    this.innerHTML = ativo ? '<i class="bi bi-stop-circle"></i> Parar impressão automática' : '<i class="bi bi-printer"></i> Iniciar impressão automática';
    document.getElementById('status').textContent = ativo ? '● Aguardando pedidos…' : '';
    ciclo();
  });
  dCoz.onchange = dBal.onchange = carregar;
  setInterval(ciclo, 3000);
  setInterval(function () { if (!ativo) carregar(); }, 15000);
  document.getElementById('status').textContent = '● Aguardando pedidos…';
  carregar().then(ciclo);
})();
</script>
JS;
require __DIR__ . '/includes/footer.php';
