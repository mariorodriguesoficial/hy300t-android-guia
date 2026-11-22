  </main>
</div>
<script src="<?= url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= e(url('assets/avisos.js?v=16')) ?>"></script>
<script>
window.BASE_URL = <?= json_encode(BASE_URL) ?>;
window.CSRF = document.querySelector('meta[name=csrf]').content;
document.querySelectorAll('[data-monitor-popup]').forEach(function (link) {
  link.addEventListener('click', function (evento) {
    evento.preventDefault();
    var monitor = window.open(link.href + (link.href.indexOf('?') >= 0 ? '&' : '?') + 'popup=1', 'rota403MonitorImpressao', 'popup=yes,width=1100,height=760,resizable=yes,scrollbars=yes');
    if (monitor) monitor.focus(); else window.location.href = link.href;
  });
});
// ===== PWA: service worker (somente em contexto seguro: HTTPS ou localhost) e botão "Instalar app" =====
(function () {
  if (window.isSecureContext && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register(BASE_URL + '/sw.js', { scope: BASE_URL + '/' }).catch(function () {});
  }
  var evInstalar = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); evInstalar = e;
    var b = document.getElementById('btnInstalarApp'); if (b) b.hidden = false;
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('#btnInstalarApp'); if (!b || !evInstalar) return;
    evInstalar.prompt(); evInstalar.userChoice.finally(function () { evInstalar = null; b.hidden = true; });
  });
  window.addEventListener('appinstalled', function () { var b = document.getElementById('btnInstalarApp'); if (b) b.hidden = true; });
  // Faixa de aviso quando o celular perde o Wi-Fi do restaurante
  function faixa() {
    var f = document.getElementById('faixaOffline');
    if (navigator.onLine === false) {
      if (!f) { f = document.createElement('div'); f.id = 'faixaOffline'; f.setAttribute('role', 'alert'); f.textContent = 'Sem conexão com o caixa — pedidos NÃO serão enviados'; document.body.prepend(f); }
    } else if (f) f.remove();
  }
  window.addEventListener('online', faixa); window.addEventListener('offline', faixa); faixa();
})();
// Imprime uma página (80mm) num iframe oculto
window.imprimirUrl = function (path) {
  return new Promise(function (resolve, reject) {
    var f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    f.src = BASE_URL + '/' + path;
    document.body.appendChild(f);
    var terminou = false;
    var timer = setTimeout(function () { finalizar(new Error('A página de impressão não respondeu.')); }, 12000);
    function finalizar(erro) { if (terminou) return; terminou = true; clearTimeout(timer); window.removeEventListener('message', pronto); f.remove(); erro ? reject(erro) : resolve(); }
    function pronto(e) { if (e.source === f.contentWindow && e.data === 'ROTA403_PRINT_READY') finalizar(); }
    window.addEventListener('message', pronto);
    f.onerror = function () { finalizar(new Error('Não foi possível carregar a impressão.')); };
  });
};
// Pergunta antes de imprimir (janela padrão do sistema, com Sim / Não)
window.confirmarImprimir = function (mensagem, path) {
  mostrarAviso(mensagem, { confirmar: true, textoConfirmar: 'Sim', textoCancelar: 'Não', aoConfirmar: function () { imprimirUrl(path); } });
};
// Pergunta Sim / Não antes de enviar um formulário de impressão
window.confirmarForm = function (form, mensagem) {
  if (form.dataset.ok) return true;
  mostrarAviso(mensagem, { confirmar: true, textoConfirmar: 'Sim', textoCancelar: 'Não', aoConfirmar: function () { form.dataset.ok = '1'; form.submit(); } });
  return false;
};
// Envia a conta para a impressora térmica (impressa direto pelo Monitor de impressão)
window.enviarConta = function (id, botao, mensagem) {
  mostrarAviso(mensagem || 'Deseja Imprimir a Conferencia de Conta?', { confirmar: true, textoConfirmar: 'Sim', textoCancelar: 'Não', aoConfirmar: function () { enviarContaAgora(id, botao); } });
};
window.enviarContaAgora = function (id, botao) {
  if (botao) botao.disabled = true;
  fetch(BASE_URL + '/api/fila_impressao.php', { method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify({ acao: 'enviar', tipo: 'conta', id: id }) })
    .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.erro || 'Falha ao enviar.'); return j; }); })
    .then(function () { mostrarAviso('Conta enviada para a impressora térmica.'); })
    .catch(function (e) { mostrarAviso(e.message || 'Não foi possível enviar a conta.'); })
    .finally(function () { if (botao) setTimeout(function () { botao.disabled = false; }, 3000); });
};
</script>
<script>function filtrarComandas(i){var t=i.value.trim().toLowerCase();document.querySelectorAll('.cmd-item').forEach(function(el){el.classList.toggle('oculto',t!==''&&el.dataset.busca.indexOf(t)<0);});}</script>
<?php if (!empty($scripts)) echo $scripts; ?>
</body>
</html>
