// Atualiza a tela automaticamente quando comandas são criadas, pagas ou alteradas (a cada 3 segundos)
(function () {
  var cfg = window.ATUALIZAR_COMANDAS || {}, base = null;
  function ocupado() {
    var a = document.activeElement;
    if (a && /INPUT|TEXTAREA|SELECT/.test(a.tagName) && a.value && a.type !== 'radio' && a.type !== 'checkbox') return true;
    if (document.querySelector('#pgLista > div, [data-r="itens"] .border-bottom, .modal.show, .aviso-confirmacao')) return true;
    return false;
  }
  function checar() {
    if (document.hidden) return;
    fetch(BASE_URL + '/api/comandas_abertas.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (r) {
        if (!r || !r.assinatura) return;
        if (cfg.comandaId && r.ids.indexOf(cfg.comandaId) < 0) { location.href = cfg.sair || (BASE_URL + '/garcom.php'); return; }
        if (cfg.comandaId) return; // dentro da comanda: só sai quando ela for paga/cancelada
        if (base === null) { base = r.assinatura; return; }
        if (r.assinatura !== base && !ocupado()) location.reload();
      }).catch(function () {});
  }
  checar();
  setInterval(checar, 3000);
  document.addEventListener('visibilitychange', checar);
})();
