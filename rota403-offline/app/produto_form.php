<?php
require_once __DIR__ . '/config/config.php';
Auth::exigir(['__admin__']);

$cats = Database::all('SELECT * FROM categorias ORDER BY ordem, nome');
$edit = !empty($_GET['editar']) ? Database::one('SELECT * FROM produtos WHERE id=?', [(int) $_GET['editar']]) : null;
if (!empty($_GET['editar']) && !$edit) { flash('Produto não encontrado.', 'danger'); redirect('produtos.php'); }
$p = $edit ?: ['id' => '', 'nome' => '', 'preco' => '', 'codigo_barras' => '', 'estoque' => 0, 'categoria_id' => '', 'destino' => 'cozinha', 'imprimir_na_comanda' => 1, 'controlar_estoque' => 1, 'ativo' => 1, 'unidade' => 'un'];
$titulo = $edit ? 'Editar produto' : 'Novo produto';
require __DIR__ . '/includes/header.php';
?>
<h1><?= $edit ? 'Editar produto' : 'Novo produto' ?></h1>
<form method="post" action="<?= url('produtos.php') ?>" enctype="multipart/form-data" class="card-r mb-3 form-produto" style="max-width:880px">
  <?= csrf_field() ?><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= e($p['id']) ?>">

  <div class="form-secao">Dados do produto</div>
  <div class="row g-3">
    <div class="col-12">
      <label class="form-label" for="pNome">Nome do produto</label>
      <input id="pNome" name="nome" class="form-control" placeholder="Ex.: Água com gás 500ml" value="<?= e($p['nome']) ?>" required>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label" for="pUnidade">Unidade</label>
      <select id="pUnidade" name="unidade" class="form-select"><option value="un" <?= ($p['unidade'] ?? 'un') !== 'kg' ? 'selected' : '' ?>>Unidade (un)</option><option value="kg" <?= ($p['unidade'] ?? '') === 'kg' ? 'selected' : '' ?>>Quilo (kg) — por peso</option></select>
      <div class="form-text">Escolha kg para produtos vendidos por peso.</div>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label" for="pPreco">Preço (R$)</label>
      <input id="pPreco" name="preco" class="form-control" placeholder="0,00" value="<?= e($p['preco']) ?>" required>
      <div class="form-text">Se for kg, informe o preço por quilo.</div>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label" for="pEstoque">Estoque</label>
      <input id="pEstoque" name="estoque" type="number" step="0.001" class="form-control" placeholder="0" value="<?= e($p['estoque']) ?>">
      <div class="form-text">Se for kg, informe em quilos.</div>
    </div>
  </div>

  <div class="form-secao mt-4">Identificação e agrupamento</div>
  <div class="row g-3">
    <div class="col-12 col-md-4">
      <label class="form-label" for="pCodigo">Código de barras</label>
      <input id="pCodigo" name="codigo_barras" class="form-control" placeholder="Código" value="<?= e($p['codigo_barras']) ?>">
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label" for="categoriaProduto">Categoria</label>
      <select id="categoriaProduto" name="categoria_id" class="form-select"><option value="">Selecione…</option><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $p['categoria_id'] == $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label" for="pDestino">Preparo (para onde vai o pedido)</label>
      <select id="pDestino" name="destino" class="form-select"><?php foreach (listarPreparos() as $sl => $nm): ?><option value="<?= e($sl) ?>" <?= $p['destino'] === $sl ? 'selected' : '' ?>><?= e($nm) ?></option><?php endforeach; ?></select>
    </div>
  </div>
  <p class="small muted mt-2 mb-0">Para cadastrar, editar ou excluir categorias, ou preparos, use o menu <a href="<?= url('categorias.php') ?>">Categorias</a>.</p>

  <div class="form-secao mt-4">Foto do produto</div>
  <div class="d-flex align-items-center gap-3">
    <?php if ($edit): ?><img class="produto-miniatura" src="<?= e(fotoProduto($p)) ?>" alt="<?= e($p['nome']) ?>" width="56" height="56"><?php endif; ?>
    <div class="flex-grow-1">
      <label for="fotoProduto" class="form-label">Trocar foto</label>
      <input id="fotoProduto" type="file" name="foto" class="form-control" accept="image/jpeg,image/png,image/webp">
      <div class="form-text">JPG, PNG ou WEBP — até 2 MB.</div>
    </div>
  </div>

  <div class="form-secao mt-4">Opções</div>
  <div class="d-flex flex-wrap gap-4 py-1">
    <label class="form-check"><input type="checkbox" class="form-check-input" name="imprimir_na_comanda" <?= $p['imprimir_na_comanda'] ? 'checked' : '' ?>> Aparece na comanda impressa</label>
    <label class="form-check"><input type="checkbox" class="form-check-input" name="controlar_estoque" <?= $p['controlar_estoque'] ? 'checked' : '' ?>> Controlar estoque</label>
    <label class="form-check"><input type="checkbox" class="form-check-input" name="ativo" <?= $p['ativo'] ? 'checked' : '' ?>> Ativo</label>
  </div>

  <div class="d-flex gap-2 mt-4 pt-3 form-botoes">
    <button class="btn btn-ember"><?= $edit ? 'Salvar alterações' : 'Cadastrar produto' ?></button>
    <a class="btn btn-outline-secondary" href="<?= url('produtos.php') ?>">Cancelar</a>
  </div>
</form>
<script>/* Leitor de código de barras envia Enter: não salva nem fecha o cadastro; só salva pelo botão "Salvar alterações" */
(function(){var f=document.querySelector('.form-produto');if(!f)return;f.addEventListener('keydown',function(e){if(e.key==='Enter'&&e.target.tagName==='INPUT'){e.preventDefault();}});})();</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
