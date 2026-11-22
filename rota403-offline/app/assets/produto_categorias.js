(function () {
  var botao = document.getElementById('adicionarCategoria');
  var botaoEditar = document.getElementById('editarCategoria');
  var botaoExcluir = document.getElementById('excluirCategoria');
  var nome = document.getElementById('novaCategoria');
  var select = document.getElementById('categoriaProduto');
  if (!botao || !nome || !select) return;
  var form = botao.closest('form');
  function csrf() { return form.querySelector('input[name="csrf"]').value; }
  function selecionada() {
    var opcao = select.options[select.selectedIndex];
    return opcao && opcao.value ? { id: opcao.value, nome: opcao.text } : null;
  }
  async function enviar(dados) {
    dados.append('csrf', csrf());
    var resposta = await fetch(botao.dataset.url, { method: 'POST', body: dados, credentials: 'same-origin' });
    var resultado = await resposta.json();
    if (!resposta.ok) throw new Error(resultado.erro || 'Não foi possível concluir.');
    return resultado;
  }
  async function adicionar() {
    if (botao.disabled) return;
    if (!nome.value.trim()) { mostrarAviso('Informe o nome da categoria.'); nome.focus(); return; }
    var dados = new FormData();
    dados.append('acao', 'criar');
    dados.append('nome', nome.value.trim());
    botao.disabled = true;
    try {
      var resultado = await enviar(dados);
      var categoria = resultado.categoria;
      var opcao = Array.from(select.options).find(function (o) { return o.value === String(categoria.id); });
      if (!opcao) { opcao = new Option(categoria.nome, String(categoria.id)); select.add(opcao); }
      select.value = String(categoria.id);
      nome.value = '';
      mostrarAviso('Categoria disponível e selecionada para este produto.');
    } catch (erro) {
      mostrarAviso(erro.message || 'Não foi possível cadastrar a categoria.');
    } finally { botao.disabled = false; }
  }
  async function renomear() {
    if (botaoEditar.disabled) return;
    var atual = selecionada();
    if (!atual) { mostrarAviso('Selecione a categoria que deseja editar.'); select.focus(); return; }
    if (!nome.value.trim()) { mostrarAviso('Digite o novo nome no campo ao lado.'); nome.focus(); return; }
    var dados = new FormData();
    dados.append('acao', 'renomear');
    dados.append('id', atual.id);
    dados.append('nome', nome.value.trim());
    botaoEditar.disabled = true;
    try {
      var resultado = await enviar(dados);
      var opcao = Array.from(select.options).find(function (o) { return o.value === String(resultado.categoria.id); });
      if (opcao) opcao.text = resultado.categoria.nome;
      nome.value = '';
      mostrarAviso('Categoria renomeada.');
    } catch (erro) {
      mostrarAviso(erro.message || 'Não foi possível renomear a categoria.');
    } finally { botaoEditar.disabled = false; }
  }
  function excluir() {
    var atual = selecionada();
    if (!atual) { mostrarAviso('Selecione a categoria que deseja excluir.'); select.focus(); return; }
    mostrarAviso('Excluir a categoria "' + atual.nome + '"?', {
      confirmar: true,
      aoConfirmar: async function () {
        var dados = new FormData();
        dados.append('acao', 'excluir');
        dados.append('id', atual.id);
        try {
          await enviar(dados);
          var opcao = Array.from(select.options).find(function (o) { return o.value === String(atual.id); });
          if (opcao) opcao.remove();
          select.value = '';
          mostrarAviso('Categoria excluída.');
        } catch (erro) {
          mostrarAviso(erro.message || 'Não foi possível excluir a categoria.');
        }
      }
    });
  }
  botao.addEventListener('click', adicionar);
  if (botaoEditar) botaoEditar.addEventListener('click', renomear);
  if (botaoExcluir) botaoExcluir.addEventListener('click', excluir);
  nome.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); adicionar(); } });
})();
