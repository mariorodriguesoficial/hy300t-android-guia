window.mostrarAviso = function (mensagem, opcoes) {
  opcoes = opcoes || {};
  var dialogo = (opcoes.confirmar || opcoes.campo);
  // Janela de confirmação/peso aberta: só fecha quando o usuário tocar em Cancelar ou Confirmar
  if (!dialogo && document.querySelector('.aviso-confirmacao')) return;
  var atual = document.querySelector('.aviso-central');
  if (atual) (atual.closest('.aviso-fundo') || atual).remove();
  var fundo = document.createElement('div');
  fundo.className = 'aviso-fundo';
  var aviso = document.createElement('div');
  aviso.className = 'aviso-central' + ((opcoes.confirmar || opcoes.campo) ? ' aviso-confirmacao' : '');
  aviso.setAttribute('role', (opcoes.confirmar || opcoes.campo) ? 'alertdialog' : 'status');
  aviso.setAttribute('aria-live', 'polite');
  var texto = document.createElement('span');
  texto.textContent = mensagem;
  aviso.appendChild(texto);
  var campo = null;
  if (opcoes.campo) {
    campo = document.createElement('input'); campo.type = 'text'; campo.className = 'aviso-campo';
    campo.setAttribute('inputmode', 'decimal'); campo.setAttribute('autocomplete', 'off');
    campo.placeholder = opcoes.placeholder || ''; campo.value = opcoes.valor || '';
    aviso.appendChild(campo);
    campo.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); fundo.remove(); opcoes.aoConfirmar(campo.value); }
      else if (ev.key === 'Escape') { ev.preventDefault(); fundo.remove(); }
    });
  }
  if (opcoes.confirmar || opcoes.campo) {
    var acoes = document.createElement('div'); acoes.className = 'aviso-acoes';
    var cancelar = document.createElement('button'); cancelar.type = 'button'; cancelar.className = 'aviso-botao aviso-cancelar'; cancelar.textContent = opcoes.textoCancelar || 'Cancelar';
    var confirmar = document.createElement('button'); confirmar.type = 'button'; confirmar.className = 'aviso-botao aviso-confirmar'; confirmar.textContent = opcoes.textoConfirmar || (opcoes.campo ? 'OK' : 'Confirmar');
    cancelar.addEventListener('click', function () { fundo.remove(); });
    confirmar.addEventListener('click', function () { fundo.remove(); opcoes.aoConfirmar(campo ? campo.value : undefined); });
    acoes.appendChild(cancelar); acoes.appendChild(confirmar); aviso.appendChild(acoes);
  } else {
    var fechar = document.createElement('button'); fechar.type = 'button'; fechar.className = 'aviso-fechar'; fechar.setAttribute('aria-label', 'Fechar aviso'); fechar.innerHTML = '&times;';
    fechar.addEventListener('click', function () { fundo.remove(); }); aviso.appendChild(fechar);
  }
  fundo.appendChild(aviso); document.body.appendChild(fundo);
  if (campo) { setTimeout(function () { campo.focus(); }, 30); return; }
  if (opcoes.confirmar) { setTimeout(function () { var bc = aviso.querySelector('.aviso-confirmar'); if (bc) bc.focus(); }, 30); return; }
  setTimeout(function () { if (fundo.isConnected) fundo.remove(); }, opcoes.tempo || 2000);
};
document.querySelectorAll('.aviso-central').forEach(function (aviso) {
  var fechar = aviso.querySelector('.aviso-fechar');
  var fundo = aviso.closest('.aviso-fundo') || aviso;
  if (!fechar) return; // janelas de confirmação nunca fecham sozinhas
  fechar.addEventListener('click', function () { fundo.remove(); });
  setTimeout(function () { if (fundo.isConnected) fundo.remove(); }, 2000);
});
document.addEventListener('submit', function (evento) {
  var formulario = evento.target;
  var mensagem = formulario.getAttribute('data-confirm');
  if (!mensagem || formulario.dataset.confirmado === '1') return;
  evento.preventDefault();
  mostrarAviso(mensagem, { confirmar: true, textoConfirmar: 'Sim', textoCancelar: 'Não', aoConfirmar: function () { formulario.dataset.confirmado = '1'; formulario.requestSubmit(); } });
});

// Atalhos nas janelas de confirmação: Enter/S = Sim, Esc/N = Não
document.addEventListener('keydown', function (e) {
  var c = document.querySelector('.aviso-confirmacao'); if (!c || c.querySelector('.aviso-campo')) return;
  var k = e.key.toLowerCase();
  if (k === 'escape' || k === 'n') { e.preventDefault(); e.stopPropagation(); var b = c.querySelector('.aviso-cancelar'); if (b) b.click(); }
  else if (k === 's') { e.preventDefault(); e.stopPropagation(); var y = c.querySelector('.aviso-confirmar'); if (y) y.click(); }
}, true);
