<?php
require_once __DIR__ . '/config/config.php';
Auth::exigir(['__admin__']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
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
