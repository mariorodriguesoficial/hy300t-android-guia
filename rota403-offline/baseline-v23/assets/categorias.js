document.querySelectorAll('[data-edicao-nome]').forEach(function (formulario) {
  var campo = formulario.querySelector('[name="nome"]');
  var id = formulario.getAttribute('id');
  var editar = document.querySelector('[data-editar="' + id + '"]');
  var salvar = document.querySelector('[data-salvar="' + id + '"]');
  var cancelar = document.querySelector('[data-cancelar="' + id + '"]');
  if (!campo || !editar || !salvar || !cancelar) return;
  var original = campo.value;
  function modoEdicao(ativo) {
    campo.readOnly = !ativo;
    editar.hidden = ativo;
    salvar.hidden = !ativo;
    cancelar.hidden = !ativo;
    if (ativo) { campo.focus(); campo.select(); }
  }
  editar.addEventListener('click', function () { modoEdicao(true); });
  cancelar.addEventListener('click', function () { campo.value = original; modoEdicao(false); });
  formulario.addEventListener('submit', function (evento) {
    if (campo.readOnly) evento.preventDefault();
  });
});