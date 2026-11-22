// Seleção de produtos + envio do pedido (cozinha/balcão)
function Lancador(el, comandaId, garcom, onEnviado) {
  var produtos = window.PRODUTOS || [];
  var carrinho = {}; // id -> {p, q, obs}
  var cat = 'Todos', busca = '';
  var cats = ['Todos'].concat(produtos.map(function (p) { return p.categoria; }).filter(function (v, i, a) { return a.indexOf(v) === i; }));
  var brl = function (v) { return 'R$ ' + Number(v).toFixed(2).replace('.', ','); };
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; };

  el.innerHTML =
    '<div class="row g-3"><div class="col-lg-8">' +
    '<input class="form-control mb-2" data-r="busca" placeholder="Buscar produto ou ler código do produto…" autocomplete="off">' +
    '<div class="chips mb-2" data-r="cats"></div><div class="grid-prod" data-r="grid"></div></div>' +
    '<div class="col-lg-4"><div class="card-r cart"><h2>Pedido</h2><div data-r="itens"></div>' +
    '<div class="d-flex justify-content-between fw-bold my-2"><span>Total</span><span data-r="total">R$ 0,00</span></div>' +
    '<button class="btn btn-ember w-100" data-r="enviar" disabled><i class="bi bi-send"></i> Enviar pedido</button></div></div></div>';
  var $ = function (r) { return el.querySelector('[data-r="' + r + '"]'); };

  function add(p, d) {
    var q = (carrinho[p.id] ? carrinho[p.id].q : 0) + d;
    if (q <= 0) delete carrinho[p.id];
    else carrinho[p.id] = { p: p, q: q, obs: carrinho[p.id] ? carrinho[p.id].obs : '' };
    render();
  }

  function render() {
    $('cats').innerHTML = cats.map(function (c) {
      return '<button type="button" class="btn btn-sm ' + (c === cat ? 'btn-primary' : 'btn-outline-primary') + '" data-cat="' + esc(c) + '">' + esc(c) + '</button>';
    }).join('');
    var b = busca.toLowerCase();
    $('grid').innerHTML = produtos.filter(function (p) {
      return (cat === 'Todos' || p.categoria === cat) && (p.nome.toLowerCase().indexOf(b) >= 0 || p.codigo === busca);
    }).map(function (p) {
      var q = carrinho[p.id] ? carrinho[p.id].q : 0;
      return '<button type="button" class="prod-btn ' + (q ? 'on' : '') + '" data-id="' + p.id + '">' + (q ? '<span class="q">' + q + '</span>' : '') +
        '<div class="n">' + esc(p.nome) + '</div><div class="p">' + brl(p.preco) + '</div>' +
        (p.controlar && p.estoque <= 5 ? '<div class="small text-danger">Estoque: ' + p.estoque + '</div>' : '') + '</button>';
    }).join('');
    var linhas = Object.keys(carrinho).map(function (k) { return carrinho[k]; });
    $('itens').innerHTML = linhas.length ? linhas.map(function (l) {
      return '<div class="border-bottom pb-2 mb-2"><div class="d-flex align-items-center gap-2"><span class="flex-grow-1 small fw-semibold">' + esc(l.p.nome) + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-menos="' + l.p.id + '">−</button><b>' + l.q + '</b>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" data-mais="' + l.p.id + '">+</button></div>' +
        '<input class="form-control form-control-sm mt-1" placeholder="Observação (ex: sem cebola)" data-obs="' + l.p.id + '" value="' + esc(l.obs) + '"></div>';
    }).join('') : '<p class="small muted">Toque nos produtos para adicionar.</p>';
    $('total').textContent = brl(linhas.reduce(function (s, l) { return s + l.p.preco * l.q; }, 0));
    $('enviar').disabled = !linhas.length;
  }

  var byId = function (id) { return produtos.find(function (p) { return p.id == id; }); };
  el.addEventListener('click', function (e) {
    var t = e.target.closest('button'); if (!t) return;
    if (t.dataset.cat) { cat = t.dataset.cat; render(); }
    else if (t.dataset.id) add(byId(t.dataset.id), 1);
    else if (t.dataset.mais) add(byId(t.dataset.mais), 1);
    else if (t.dataset.menos) add(byId(t.dataset.menos), -1);
  });
  el.addEventListener('input', function (e) {
    if (e.target.dataset.obs) carrinho[e.target.dataset.obs].obs = e.target.value;
    if (e.target.dataset.r === 'busca') { busca = e.target.value; render(); e.target.focus(); }
  });
  $('busca').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var p = produtos.find(function (x) { return x.codigo && x.codigo === busca.trim(); });
    if (p) { add(p, 1); busca = ''; e.target.value = ''; render(); }
  });
  $('enviar').addEventListener('click', function () {
    var btn = this; btn.disabled = true;
    var linhas = Object.keys(carrinho).map(function (k) { return { produto_id: +k, quantidade: carrinho[k].q, observacao: carrinho[k].obs }; });
    fetch(BASE_URL + '/api/lancar.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
      body: JSON.stringify({ comanda_id: comandaId, garcom: garcom, itens: linhas })
    }).then(function (r) { return r.json(); }).then(function (r) {
      if (r.erro) { mostrarAviso(r.erro); btn.disabled = false; return; }
      carrinho = {}; render();
      if (onEnviado) onEnviado();
      else mostrarAviso('Pedido enviado para impressão na cozinha/balcão!');
    }).catch(function () { mostrarAviso('Falha de conexão. Tente novamente.'); btn.disabled = false; });
  });
  render();
}
