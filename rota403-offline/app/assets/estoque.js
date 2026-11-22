(function () {
  var abas = Array.from(document.querySelectorAll('.abas-estoque [data-categoria]'));
  var linhas = Array.from(document.querySelectorAll('[data-produto-categoria]'));
  var painel = document.getElementById('painel-estoque');
  var vazio = document.getElementById('estoque-vazio');
  var busca = document.getElementById('buscaProduto');
  var categoriaAtiva = 'todos';
  function normalizar(texto) {
    return (texto || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  }
  function aplicarFiltros() {
    var termo = normalizar(busca ? busca.value.trim() : '');
    var visiveis = 0;
    linhas.forEach(function (linha) {
      var categoriaOk = categoriaAtiva === 'todos' || linha.dataset.produtoCategoria === categoriaAtiva;
      var buscaOk = termo === '' || normalizar(linha.dataset.busca || '').indexOf(termo) !== -1;
      linha.hidden = !(categoriaOk && buscaOk);
      if (!linha.hidden) visiveis++;
    });
    if (vazio) vazio.hidden = visiveis !== 0;
  }
  function selecionar(aba) {
    categoriaAtiva = aba.dataset.categoria;
    abas.forEach(function (item) {
      var ativa = item === aba;
      item.classList.toggle('active', ativa);
      item.setAttribute('aria-selected', ativa ? 'true' : 'false');
      item.tabIndex = ativa ? 0 : -1;
    });
    if (painel) painel.setAttribute('aria-labelledby', aba.id);
    aplicarFiltros();
  }
  abas.forEach(function (aba, indice) {
    aba.addEventListener('click', function () { selecionar(aba); });
    aba.addEventListener('keydown', function (evento) {
      var proxima;
      if (evento.key === 'ArrowRight') proxima = abas[(indice + 1) % abas.length];
      if (evento.key === 'ArrowLeft') proxima = abas[(indice + abas.length - 1) % abas.length];
      if (evento.key === 'Home') proxima = abas[0];
      if (evento.key === 'End') proxima = abas[abas.length - 1];
      if (!proxima) return;
      evento.preventDefault();
      proxima.focus();
      selecionar(proxima);
    });
  });
  if (busca) busca.addEventListener('input', aplicarFiltros);
  document.querySelectorAll('[data-confirmar-toggle]').forEach(function (caixa) {
    caixa.addEventListener('change', function () {
      var marcado = caixa.checked;
      caixa.checked = !marcado; // só muda depois do "Sim"
      var nome = caixa.dataset.nome || 'este produto';
      var msg = caixa.dataset.confirmarToggle === 'ativo'
        ? (marcado ? 'Deseja realmente ATIVAR o produto ' : 'Deseja realmente DESATIVAR o produto ') + nome + '?'
        : (marcado ? 'Deseja realmente COLOCAR na comanda impressa o produto ' : 'Deseja realmente TIRAR da comanda impressa o produto ') + nome + '?';
      mostrarAviso(msg, { confirmar: true, textoConfirmar: 'Sim', textoCancelar: 'Não', aoConfirmar: function () { caixa.checked = marcado; caixa.form.submit(); } });
    });
  });
}());
