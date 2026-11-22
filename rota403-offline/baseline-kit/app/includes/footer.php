  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.BASE_URL = <?= json_encode(BASE_URL) ?>;
window.CSRF = document.querySelector('meta[name=csrf]').content;
window.mostrarAviso = function (mensagem, opcoes) {
  opcoes = opcoes || {};
  var atual = document.querySelector('.aviso-central');
  if (atual) (atual.closest('.aviso-fundo') || atual).remove();
  var fundo = document.createElement('div');
  fundo.className = 'aviso-fundo';
  var aviso = document.createElement('div');
  aviso.className = 'aviso-central' + (opcoes.confirmar ? ' aviso-confirmacao' : '');
  aviso.setAttribute('role', opcoes.confirmar ? 'alertdialog' : 'status');
  aviso.setAttribute('aria-live', 'polite');
  var texto = document.createElement('span');
  texto.textContent = mensagem;
  aviso.appendChild(texto);
  if (opcoes.confirmar) {
    var acoes = document.createElement('div'); acoes.className = 'aviso-acoes';
    var cancelar = document.createElement('button'); cancelar.type = 'button'; cancelar.className = 'aviso-botao aviso-cancelar'; cancelar.textContent = 'Cancelar';
    var confirmar = document.createElement('button'); confirmar.type = 'button'; confirmar.className = 'aviso-botao aviso-confirmar'; confirmar.textContent = 'Confirmar';
    cancelar.addEventListener('click', function () { fundo.remove(); });
    confirmar.addEventListener('click', function () { fundo.remove(); opcoes.aoConfirmar(); });
    acoes.appendChild(cancelar); acoes.appendChild(confirmar); aviso.appendChild(acoes);
  } else {
    var fechar = document.createElement('button'); fechar.type = 'button'; fechar.className = 'aviso-fechar'; fechar.setAttribute('aria-label', 'Fechar aviso'); fechar.innerHTML = '&times;';
    fechar.addEventListener('click', function () { fundo.remove(); }); aviso.appendChild(fechar);
    setTimeout(function () { if (fundo.isConnected) fundo.remove(); }, 5000);
  }
  fundo.appendChild(aviso); document.body.appendChild(fundo);
};
document.querySelectorAll('.aviso-central').forEach(function (aviso) {
  var fechar = aviso.querySelector('.aviso-fechar');
  var fundo = aviso.closest('.aviso-fundo') || aviso;
  if (fechar) fechar.addEventListener('click', function () { fundo.remove(); });
  setTimeout(function () { if (fundo.isConnected) fundo.remove(); }, 5000);
});
document.addEventListener('submit', function (evento) {
  var formulario = evento.target;
  var mensagem = formulario.getAttribute('data-confirm');
  if (!mensagem || formulario.dataset.confirmado === '1') return;
  evento.preventDefault();
  mostrarAviso(mensagem, { confirmar: true, aoConfirmar: function () { formulario.dataset.confirmado = '1'; formulario.requestSubmit(); } });
});
document.querySelectorAll('[data-monitor-popup]').forEach(function (link) {
  link.addEventListener('click', function (evento) {
    evento.preventDefault();
    var monitor = window.open(link.href + (link.href.indexOf('?') >= 0 ? '&' : '?') + 'popup=1', 'rota403MonitorImpressao', 'popup=yes,width=1100,height=760,resizable=yes,scrollbars=yes');
    if (monitor) monitor.focus(); else window.location.href = link.href;
  });
});
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
</script>
<?php if (!empty($scripts)) echo $scripts; ?>
</body>
</html>
