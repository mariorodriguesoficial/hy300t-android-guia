<?php
require_once __DIR__ . '/config/config.php';
Auth::exigir(['__admin__']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $acaoFp = $_POST['acao_fp'] ?? '';
    if ($acaoFp !== '') {
        garantirFormasPagamento();
        if ($acaoFp === 'adicionar') {
            $nome = mb_substr(trim((string) ($_POST['nome_fp'] ?? '')), 0, 40);
            if ($nome === '') flash('Digite o nome da condição de pagamento.', 'danger');
            elseif (Database::val('SELECT COUNT(*) FROM formas_pagamento WHERE nome=?', [$nome])) flash("A condição \"$nome\" já está cadastrada.", 'danger');
            else { Database::query('INSERT INTO formas_pagamento (nome, ordem) VALUES (?, (SELECT COALESCE(MAX(o),0)+1 FROM (SELECT ordem o FROM formas_pagamento) t))', [$nome]); flash("Condição \"$nome\" cadastrada."); }
        } elseif ($acaoFp === 'editar') {
            $nome = mb_substr(trim((string) ($_POST['nome_fp'] ?? '')), 0, 40);
            if ($nome !== '') { Database::query('UPDATE formas_pagamento SET nome=? WHERE id=?', [$nome, (int) $_POST['id_fp']]); flash('Condição de pagamento atualizada.'); }
        } elseif ($acaoFp === 'excluir') {
            if ((int) Database::val('SELECT COUNT(*) FROM formas_pagamento') <= 1) flash('Mantenha pelo menos uma condição de pagamento.', 'danger');
            else { Database::query('DELETE FROM formas_pagamento WHERE id=?', [(int) $_POST['id_fp']]); flash('Condição de pagamento apagada.'); }
        }
        redirect('configuracoes.php?aba=pagamentos');
    }
    try {
        foreach (array_keys(EMPRESA_CAMPOS) as $k) salvarConfig($k, mb_substr(trim($_POST[$k] ?? ''), 0, 255));
        if (!empty($_POST['remover_logo'])) salvarConfig('logo', '');
        $f = $_FILES['logo'] ?? null;
        if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível enviar a logo.');
            if ($f['size'] > 2 * 1024 * 1024) throw new RuntimeException('A logo deve ter no máximo 2 MB.');
            $info = @getimagesize($f['tmp_name']);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
            if (!$ext) throw new RuntimeException('Formato inválido. Use JPG, PNG ou WEBP.');
            $dir = BASE_PATH . '/uploads';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            foreach (glob($dir . '/logo.*') as $old) @unlink($old);
            if (!move_uploaded_file($f['tmp_name'], "$dir/logo.$ext")) throw new RuntimeException('Sem permissão para gravar a logo na pasta uploads.');
            salvarConfig('logo', "uploads/logo.$ext");
        }
        flash('Configurações salvas.');
    } catch (RuntimeException $e) { flash($e->getMessage(), 'danger'); }
    redirect('configuracoes.php');
}

$cfg = empresa();
$titulo = 'Configurações';
require __DIR__ . '/includes/header.php';
$campo = function (string $k, string $col = 'col-md-6') use ($cfg) {
    echo '<div class="' . $col . '"><label class="form-label fw-semibold">' . e(EMPRESA_CAMPOS[$k]) . '</label><input name="' . $k . '" class="form-control" value="' . e($cfg[$k]) . '"' . ($k === 'nome' ? ' required' : '') . '></div>';
};
?>
<h1>Configurações</h1>
<?php $aba = ($_GET['aba'] ?? '') === 'pagamentos' ? 'pagamentos' : 'empresa'; ?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $aba === 'empresa' ? 'active' : '' ?>" href="<?= url('configuracoes.php') ?>">Dados da empresa</a></li>
  <li class="nav-item"><a class="nav-link <?= $aba === 'pagamentos' ? 'active' : '' ?>" href="<?= url('configuracoes.php?aba=pagamentos') ?>">Cadastro de Condições de Pagamento</a></li>
</ul>
<?php if ($aba === 'pagamentos'): garantirFormasPagamento(); $fps = Database::all('SELECT * FROM formas_pagamento ORDER BY ordem, id'); ?>
<div class="card-r" style="max-width:640px">
  <h2>Condições de pagamento</h2>
  <p class="muted">As condições cadastradas aqui aparecem como opções de forma de pagamento na tela do Caixa. Ao apagar, ela some do Caixa (as vendas antigas continuam nos relatórios).</p>
  <form method="post" class="input-group mb-3"><?= csrf_field() ?><input type="hidden" name="acao_fp" value="adicionar">
    <input name="nome_fp" class="form-control" maxlength="40" placeholder="Nova condição (ex.: Cartão Alelo)" required>
    <button class="btn btn-ember"><i class="bi bi-plus-lg"></i> Cadastrar</button></form>
  <?php foreach ($fps as $fp): ?>
    <div class="d-flex gap-2 align-items-center border-bottom py-2">
      <form method="post" class="input-group input-group-sm flex-grow-1" onsubmit="return confirmarForm(this, 'Salvar a alteração desta condição de pagamento?')"><?= csrf_field() ?><input type="hidden" name="acao_fp" value="editar"><input type="hidden" name="id_fp" value="<?= (int) $fp['id'] ?>">
        <input name="nome_fp" class="form-control" maxlength="40" value="<?= e($fp['nome']) ?>" required><button class="btn btn-outline-primary"><i class="bi bi-save"></i> Salvar</button></form>
      <form method="post" data-msg="Deseja realmente apagar a condição &quot;<?= e($fp['nome']) ?>&quot;?" onsubmit="return confirmarForm(this, this.dataset.msg)"><?= csrf_field() ?><input type="hidden" name="acao_fp" value="excluir"><input type="hidden" name="id_fp" value="<?= (int) $fp['id'] ?>">
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Apagar</button></form>
    </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; return; endif; ?>
<form method="post" enctype="multipart/form-data" class="card-r">
  <?= csrf_field() ?>
  <h2>Dados da empresa</h2>
  <p class="muted">Estas informações e a logo aparecem no cabeçalho da comanda impressa e nos documentos de venda entregues ao cliente.</p>
  <div class="row g-3">
    <?php $campo('nome'); $campo('slogan'); $campo('razao', 'col-md-8'); $campo('cnpj', 'col-md-4'); $campo('endereco', 'col-12');
          $campo('telefone'); $campo('whatsapp'); $campo('email'); $campo('instagram'); $campo('cidade'); $campo('rodape'); ?>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Logo da empresa</label>
      <input type="file" name="logo" class="form-control" accept="image/png,image/jpeg,image/webp">
      <div class="form-text">Formatos: JPG, PNG, WEBP (até 2 MB). Ideal: 200×200px, fundo branco.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-semibold">Logo atual</label>
      <div class="d-flex align-items-center gap-3">
        <?php if ($cfg['logo'] && is_file(BASE_PATH . '/' . $cfg['logo'])): ?>
          <img src="<?= url($cfg['logo']) ?>?v=<?= filemtime(BASE_PATH . '/' . $cfg['logo']) ?>" alt="Logo" style="max-height:90px;max-width:160px;border:1px solid #ddd;border-radius:8px;padding:4px;background:#fff">
          <label class="form-check"><input class="form-check-input" type="checkbox" name="remover_logo" value="1"> Remover logo</label>
        <?php else: ?><span class="muted">Nenhuma logo enviada.</span><?php endif; ?>
      </div>
    </div>
  </div>
  <button class="btn btn-ember mt-4"><i class="bi bi-save"></i> Salvar configurações</button>
</form>
<?php require __DIR__ . '/includes/footer.php';
