<?php
require_once __DIR__ . '/config/config.php';

$primeiroAcesso = (int) Database::val('SELECT COUNT(*) FROM usuarios') === 0;
$emp = empresa();
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $login = trim($_POST['login'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');
    $bloq = $login !== '' ? seg_bloqueio_restante($login) : 0;
    if ($bloq > 0) {
        $erro = 'Muitas tentativas erradas. Acesso bloqueado por ' . $bloq . ' minuto(s).';
    } elseif ($primeiroAcesso) {
        $nome = trim($_POST['nome'] ?? '');
        try {
            if ($nome === '' || $login === '' || mb_strlen($nome) > 100 || mb_strlen($login) > 50) throw new RuntimeException('Preencha nome e login.');
            validar_senha_nova($senha);
            Database::query("INSERT INTO usuarios (nome, login, senha, nivel) VALUES (?,?,?,'admin')", [cifrar($nome), $login, hash_senha($senha)]);
            Auth::login($login, $senha);
            redirect('comandas.php');
        } catch (RuntimeException $e) { $erro = $e->getMessage(); }
    } elseif (Auth::login($login, $senha)) {
        redirect(Auth::user()['nivel'] === 'garcom' ? 'garcom.php' : 'comandas.php');
    } else {
        $resta = seg_tentativas_restantes($login);
        $erro = $resta > 0 ? 'Login ou senha inválidos. Restam ' . $resta . ' tentativa(s).' : 'Muitas tentativas erradas. Acesso bloqueado por ' . SEG_BLOQUEIO_MIN . ' minutos.';
    }
}
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar — Rota 403</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Bebas+Neue&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= url('assets/style.css?v=' . (int) (@filemtime(__DIR__ . '/../assets/style.css') ?: 36)) ?>" rel="stylesheet"></head>
<body><div class="login-wrap"><form method="post" class="login-box">
  <?= csrf_field() ?>
  <div class="text-center mb-3">
    <?php if ($emp['logo'] && is_file(BASE_PATH . '/' . $emp['logo'])): ?>
      <img class="login-logo" src="<?= e(url($emp['logo'])) ?>?v=<?= filemtime(BASE_PATH . '/' . $emp['logo']) ?>" alt="<?= e($emp['nome']) ?>">
    <?php else: ?>
      <span class="ember-dot mx-auto" style="width:56px;height:56px;font-size:1.6rem"><i class="bi bi-fire"></i></span>
    <?php endif; ?>
    <div class="muted"><?= $primeiroAcesso ? 'Primeiro acesso: crie o administrador' : 'Sistema de comandas' ?></div>
  </div>
  <?php if ($erro): ?><div class="aviso-fundo"><div class="aviso-central" role="alert"><span><?= e($erro) ?></span><button type="button" class="aviso-fechar" aria-label="Fechar aviso" onclick="this.closest('.aviso-fundo').remove()">&times;</button></div></div><?php endif; ?>
  <?php if ($primeiroAcesso): ?><div class="mb-2"><label class="form-label">Nome</label><input name="nome" class="form-control" required></div><?php endif; ?>
  <div class="mb-2"><label class="form-label">Login</label><input name="login" class="form-control" required autofocus></div>
  <div class="mb-3"><label class="form-label">Senha</label><input name="senha" type="password" class="form-control" required autocomplete="current-password"></div>
  <button class="btn btn-ember w-100"><?= $primeiroAcesso ? 'Criar administrador' : 'Entrar' ?></button>
  <div class="login-creditos">
    <div>© 2026 SysComandas | Versão 1.0.0</div>
    <div>Desenvolvido por © MR INFORMÁTICA</div>
  </div>
</form></div><script src="<?= e(url('assets/avisos.js?v=15')) ?>"></script></body></html>
