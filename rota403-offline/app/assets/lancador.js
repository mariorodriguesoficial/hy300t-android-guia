// Seleção de produtos + envio do pedido (cozinha/balcão)
function Lancador(el, comandaId, garcom, onEnviado, opts) {
  opts = opts || {};
  var enviando = false;
  var produtos = window.PRODUTOS || [];
  var carrinho = {}; // id -> {p, q, obs}
  var cat = 'Todos', busca = '';
  var cats = ['Todos'].concat(produtos.map(function (p) { return p.categoria; }).filter(function (v, i, a) { return a.indexOf(v) === i; }));
  var brl = function (v) { return 'R$ ' + Number(v).toFixed(2).replace('.', ','); };
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; };

  el.innerHTML =
    '<div class="row g-3"><div class="col-lg-8">' +
    '<input class="form-control mb-2" data-r="busca" placeholder="Buscar produto, ler código ou código*peso (kg) + Enter" autocomplete="off">' +
    '<div class="chips mb-2" data-r="cats"></div><div class="grid-prod" data-r="grid"></div></div>' +
    '<div class="col-lg-4"><div class="card-r cart"><h2>Pedido</h2><div data-r="itens"></div>' +
    '<div class="d-flex justify-content-between fw-bold my-2"><span>Total</span><span data-r="total">R$ 0,00</span></div>' +
    '<button class="btn btn-ember w-100" data-r="enviar" disabled><i class="bi bi-send"></i> Enviar pedido</button></div></div></div>' +
    '<div class="barra-enviar" data-r="barra" hidden><div><small data-r="bqtd"></small><strong data-r="btotal"></strong></div><button type="button" class="btn btn-light fw-bold" data-r="benviar"><i class="bi bi-send"></i> Enviar pedido</button></div>';
  var $ = function (r) { return el.querySelector('[data-r="' + r + '"]'); };

  var seqKg = 0;
  var fmtKg = function (q) { return Number(q).toFixed(3).replace('.', ',') + ' kg'; };
  // Peso digitado: "0,450" ou "1.2" = kg; número inteiro de 10 para cima = gramas (450 = 0,450 kg)
  function lerPeso(t) {
    t = String(t || '').trim().replace(/\s*kg$/i, '');
    if (!t) return 0;
    var v = /[.,]/.test(t) ? parseFloat(t.replace(',', '.')) : (parseInt(t, 10) >= 10 ? parseInt(t, 10) / 1000 : parseFloat(t));
    return isFinite(v) && v > 0 && v <= 100 ? Math.round(v * 1000) / 1000 : 0;
  }
  function addPeso(p, peso) {
    if (!peso) { mostrarAviso('Peso inválido. Ex.: código*0,450'); return; }
    carrinho['k' + (++seqKg)] = { p: p, q: peso, obs: '', kg: true };
    render();
    mostrarAviso('Item adicionado à comanda\n' + p.nome + ' — ' + fmtKg(peso), { tempo: 1000 });
    setTimeout(function () { var b = $('busca'); if (b) b.focus(); }, 50); // próximo Enter envia
  }
  function add(p, d) {
    if (p.unidade === 'kg') { mostrarAviso('Peso de ' + p.nome + ' (kg)', { campo: true, placeholder: 'Ex.: 0,450', aoConfirmar: function (w) { addPeso(p, lerPeso(w)); } }); return; }
    var q = (carrinho[p.id] ? carrinho[p.id].q : 0) + d;
    if (q <= 0) delete carrinho[p.id];
    else carrinho[p.id] = { p: p, q: q, obs: carrinho[p.id] ? carrinho[p.id].obs : '' };
    render();
  }

  // Toque no produto: pergunta antes de adicionar
  function confirmarAdd(p) {
    if (!p) return;
    if (p.unidade === 'kg') { add(p, 1); return; }
    mostrarAviso('Adicionar item\n' + p.nome, { confirmar: true, textoConfirmar: 'Adicionar item', aoConfirmar: function () {
      add(p, 1); mostrarAviso('Item adicionado à comanda', { tempo: 1000 });
    } });
  }

  function render() {
    $('cats').innerHTML = cats.map(function (c) {
      return '<button type="button" class="btn btn-sm ' + (c === cat ? 'btn-primary' : 'btn-outline-primary') + '" data-cat="' + esc(c) + '">' + esc(c) + '</button>';
    }).join('');
    var b = busca.split('*')[0].trim().toLowerCase();
    $('grid').innerHTML = produtos.filter(function (p) {
      return (cat === 'Todos' || p.categoria === cat) && (p.nome.toLowerCase().indexOf(b) >= 0 || (p.codigo || '').toLowerCase() === b);
    }).map(function (p) {
      var q = carrinho[p.id] ? carrinho[p.id].q : 0;
      if (p.unidade === 'kg') q = Object.keys(carrinho).filter(function (k) { return carrinho[k].kg && carrinho[k].p.id === p.id; }).length;
      return '<button type="button" class="prod-btn ' + (q ? 'on' : '') + '" data-id="' + p.id + '">' + (q ? '<span class="q">' + q + '</span>' : '') +
        (p.foto ? '<img class="produto-miniatura" src="' + esc(p.foto).replace(/"/g, '&quot;') + '" alt="" width="48" height="48" loading="lazy">' : '') + '<div class="produto-info"><div class="n">' + esc(p.nome) + '</div><div class="p">' + brl(p.preco) + (p.unidade === 'kg' ? '/kg' : '') + '</div>' +
        (p.controlar && p.estoque <= 5 ? '<div class="small text-danger">Estoque: ' + p.estoque + '</div>' : '') + '</div></button>';
    }).join('');
    var linhas = Object.keys(carrinho).map(function (k) { carrinho[k].key = k; return carrinho[k]; });
    $('itens').innerHTML = linhas.length ? linhas.map(function (l) {
      if (l.kg) return '<div class="border-bottom pb-2 mb-2"><div class="d-flex align-items-center gap-2"><span class="flex-grow-1 small fw-semibold">' + esc(l.p.nome) + '<br><span class="muted">' + fmtKg(l.q) + ' × ' + brl(l.p.preco) + ' = <b>' + brl(Math.round(l.p.preco * l.q * 100) / 100) + '</b></span></span>' +
        '<button type="button" class="btn btn-sm btn-outline-danger" data-tirar="' + l.key + '">×</button></div>' +
        '<input class="form-control form-control-sm mt-1" placeholder="Observação" data-obs="' + l.key + '" value="' + esc(l.obs) + '"></div>';
      return '<div class="border-bottom pb-2 mb-2"><div class="d-flex align-items-center gap-2"><span class="flex-grow-1 small fw-semibold">' + esc(l.p.nome) + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-menos="' + l.p.id + '">−</button><b>' + l.q + '</b>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-mais="' + l.p.id + '">+</button></div>' +
        '<input class="form-control form-control-sm mt-1" placeholder="Observação (ex: sem cebola)" data-obs="' + l.p.id + '" value="' + esc(l.obs) + '"></div>';
    }).join('') : '<p class="small muted">Toque nos produtos para adicionar.</p>';
    $('total').textContent = brl(linhas.reduce(function (s, l) { return s + Math.round(l.p.preco * l.q * 100) / 100; }, 0));
    $('enviar').disabled = !linhas.length || enviando;
    var be = $('benviar'); if (be) be.disabled = enviando;
    $('barra').hidden = !linhas.length;
    $('bqtd').textContent = linhas.length + (linhas.length === 1 ? ' item no pedido' : ' itens no pedido');
    $('btotal').textContent = $('total').textContent;
  }

  var byId = function (id) { return produtos.find(function (p) { return p.id == id; }); };
  el.addEventListener('click', function (e) {
    var t = e.target.closest('button'); if (!t) return;
    if (t.dataset.r === 'benviar') { if (!$('enviar').disabled) $('enviar').click(); return; }
    if (t.dataset.cat) { cat = t.dataset.cat; render(); }
    else if (t.dataset.id) confirmarAdd(byId(t.dataset.id));
    else if (t.dataset.mais) add(byId(t.dataset.mais), 1);
    else if (t.dataset.menos) add(byId(t.dataset.menos), -1);
    else if (t.dataset.tirar) { delete carrinho[t.dataset.tirar]; render(); }
  });
  el.addEventListener('input', function (e) {
    if (e.target.dataset.obs) carrinho[e.target.dataset.obs].obs = e.target.value;
    if (e.target.dataset.r === 'busca') { busca = e.target.value; render(); e.target.focus(); }
  });
  $('busca').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var txt = busca.trim(), m = txt.match(/^([^*]+)\*(.+)$/);
    if (!txt) { if (Object.keys(carrinho).length && !$('enviar').disabled) $('enviar').click(); return; } // Enter com campo vazio = enviar
    if (m) { // código*peso (somente produtos cadastrados em kg)
      var pk = produtos.find(function (x) { return x.codigo && x.codigo === m[1].trim(); });
      if (!pk) { mostrarAviso('Produto com código ' + m[1].trim() + ' não encontrado.'); return; }
      if (pk.unidade !== 'kg') { mostrarAviso(pk.nome + ' não é vendido por kg.'); return; }
      var peso = lerPeso(m[2]); if (!peso) { mostrarAviso('Peso inválido. Ex.: ' + m[1].trim() + '*0,450'); return; }
      addPeso(pk, peso); busca = ''; e.target.value = ''; render(); e.target.focus(); return;
    }
    var p = produtos.find(function (x) { return x.codigo && x.codigo === txt; });
    if (p && p.unidade === 'kg') { mostrarAviso('Produto por kg: digite ' + txt + '*peso e Enter.'); return; }
    if (p) { add(p, 1); busca = ''; e.target.value = ''; render(); }
  });
  // ===== Resumo antes de enviar (PWA do garçom) =====
  function resumo() {
    var linhas = Object.keys(carrinho).map(function (k) { return carrinho[k]; });
    var total = linhas.reduce(function (t, l) { return t + Math.round(l.p.preco * l.q * 100) / 100; }, 0);
    var f = document.createElement('div'); f.className = 'resumo-fundo';
    f.innerHTML = '<div class="resumo" role="dialog" aria-modal="true" aria-label="Resumo do pedido"><h2>Confirmar pedido</h2>' +
      (opts.rotulo ? '<div class="resumo-cmd">' + esc(opts.rotulo) + '</div>' : '') + '<div class="resumo-lista">' +
      linhas.map(function (l) {
        return '<div class="resumo-item"><span class="rq">' + (l.kg ? fmtKg(l.q) : l.q + '×') + '</span><span class="rn">' + esc(l.p.nome) +
          (l.obs ? '<small>» ' + esc(l.obs) + '</small>' : '') + '</span><span class="rv">' + brl(Math.round(l.p.preco * l.q * 100) / 100) + '</span></div>';
      }).join('') + '</div><div class="resumo-total"><span>Total</span><b>' + brl(total) + '</b></div>' +
      '<div class="resumo-acoes"><button type="button" class="btn btn-outline-secondary" data-x="voltar">Voltar</button>' +
      '<button type="button" class="btn btn-ember" data-x="ok"><i class="bi bi-send"></i> Confirmar e enviar</button></div></div>';
    f.addEventListener('click', function (e) {
      var x = e.target.closest('button'); if (e.target === f) { f.remove(); return; } if (!x) return;
      if (x.dataset.x === 'voltar') f.remove();
      if (x.dataset.x === 'ok') { x.disabled = true; f.remove(); enviar(); }   // desabilita: toque duplo não reenvia
    });
    document.body.appendChild(f);
  }

  function enviar() {
    if (enviando) return;
    if (window.navigator && navigator.onLine === false) { mostrarAviso('Sem conexão com o caixa. O pedido NÃO foi enviado.'); return; }
    enviando = true; render(); $('enviar').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando…';
    var linhas = Object.keys(carrinho).map(function (k) { return { produto_id: carrinho[k].p.id, quantidade: carrinho[k].q, observacao: carrinho[k].obs }; });
    // Chave idempotente: mesma chave em reenvio do MESMO carrinho (retry/timeout); carrinho diferente = chave nova
    var assinatura = JSON.stringify([comandaId, linhas]);
    if (!window.__envio || window.__envio.assinatura !== assinatura) {
      window.__envio = { assinatura: assinatura, chave: (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : ('k' + Date.now() + Math.random().toString(16).slice(2)) };
    }
    var ctl = window.AbortController ? new AbortController() : null, timer = setTimeout(function () { if (ctl) ctl.abort(); }, 15000);
    fetch(BASE_URL + '/api/lancar.php', {
      method: 'POST', credentials: 'same-origin', signal: ctl ? ctl.signal : undefined, headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
      body: JSON.stringify({ comanda_id: comandaId, garcom: garcom, itens: linhas, chave: window.__envio.chave })
    }).then(function (resp) {
      clearTimeout(timer);
      if (resp.status === 401 || resp.status === 419) throw { sessao: true };
      return resp.json().then(function (j) { return j; }, function () { throw { rede: true }; });
    }).then(function (r) {
      if (r && r.sucesso) {             // SÓ aqui o pedido conta como enviado (confirmação do servidor)
        carrinho = {}; window.__envio = null; enviando = false; render();
        if (onEnviado) onEnviado(); else mostrarAviso('Pedido enviado!');
      } else mostrarAviso((r && r.erro) || 'O servidor não confirmou o pedido. Tente novamente.');
    }).catch(function (e) {
      clearTimeout(timer);
      if (e && e.sessao) { mostrarAviso('Sua sessão expirou. O pedido NÃO foi enviado. Entre novamente.'); setTimeout(function () { location.href = BASE_URL + '/login'; }, 1800); }
      else mostrarAviso('Sem confirmação do caixa. O pedido pode NÃO ter sido enviado. Toque em Enviar para tentar de novo — não duplica.');
    }).then(function () {
      enviando = false; render(); $('enviar').innerHTML = '<i class="bi bi-send"></i> Enviar pedido';
    });
  }
  $('enviar').addEventListener('click', function () { if (enviando) return; if (opts.resumo) resumo(); else enviar(); });
  render();
}
