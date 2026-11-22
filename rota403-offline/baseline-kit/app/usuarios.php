<?php
require_once __DIR__ . '/config/config.php';
$eu = Auth::exigir(['__admin__']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['acao'] ?? '';
    $nivel = array_key_exists($_POST['nivel'] ?? '', Auth::NIVEIS) ? $_POST['nivel'] : 'garcom';
    try {
        if ($a === 'criar') {
            if (strlen($_POST['senha'] ?? '') < 6) throw new RuntimeException('A senha precisa ter pelo menos 6 caracteres.');
            Database::query('INSERT INTO usuarios (nome, login, senha, nivel) VALUES (?,?,?,?)', [trim($_POST['nome']), trim($_POST['login']), password_hash($_POST['senha'], PASSWORD_DEFAULT), $nivel]);
            flash('Usuário criado.');
        } elseif ($a === 'editar') {
            $id = (int) $_POST['id'];
            Database::query('UPDATE usuarios SET nome=?, nivel=?, ativo=? WHERE id=?', [trim($_POST['nome']), $id === (int) $eu['id'] ? 'admin' : $nivel, $id === (int) $eu['id'] ? 1 : (isset($_POST['ativo']) ? 1 : 0), $id]);
            if (!empty($_POST['senha'])) {
                if (strlen($_POST['senha']) < 6) throw new RuntimeException('A senha precisa ter pelo menos 6 caracteres.');
                Database::query('UPDATE usuarios SET senha=? WHERE id=?', [password_hash($_POST['senha'], PASSWORD_DEFAULT), $id]);
            }
            flash('Usuário atualizado.');
        }
    } catch (PDOException $e) { flash('Esse login já existe.', 'danger'); }
    catch (RuntimeException $e) { flash($e->getMessage(), 'danger'); }
    redirect('usuarios.php');
}

$usuarios = Database::all('SELECT * FROM usuarios ORDER BY nome');
$titulo = 'Usuários';
require __DIR__ . '/includes/header.php';
?>
<h1>Usuários</h1>
<form method="post" class="card-r mb-3">
  <?= csrf_field() ?><input type="hidden" name="acao" value="criar">
  <div class="row g-2">
    <div class="col-md-3"><input name="nome" class="form-control" placeholder="Nome" required></div>
    <div class="col-md-3"><input name="login" class="form-control" placeholder="Login" required></div>
    <div class="col-md-2"><input name="senha" type="password" class="form-control" placeholder="Senha" required></div>
    <div class="col-md-2"><select name="nivel" class="form-select"><?php foreach (Auth::NIVEIS as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'garcom' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-ember w-100">Criar usuário</button></div>
  </div>
  <div class="small muted mt-2">Garçom: comandas e pedidos • Caixa: + caixa e impressão • Administrador: tudo.</div>
</form>
<div class="card-r p-0 table-responsive"><table class="table mb-0 align-middle">
  <thead><tr><th>Nome</th><th>Login</th><th>Nível</th><th>Nova senha</th><th>Ativo</th><th></th></tr></thead>
  <tbody><?php foreach ($usuarios as $x): $f = 'u' . $x['id']; ?>
    <tr>
      <td><input form="<?= $f ?>" name="nome" class="form-control form-control-sm" value="<?= e($x['nome']) ?>"></td>
      <td><?= e($x['login']) ?></td>
      <td><select form="<?= $f ?>" name="nivel" class="form-select form-select-sm"><?php foreach (Auth::NIVEIS as $k => $v): ?><option value="<?= $k ?>" <?= $x['nivel'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></td>
      <td><input form="<?= $f ?>" name="senha" type="password" class="form-control form-control-sm" placeholder="(manter)"></td>
      <td><input form="<?= $f ?>" type="checkbox" name="ativo" class="form-check-input" <?= $x['ativo'] ? 'checked' : '' ?>></td>
      <td><form id="<?= $f ?>" method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="editar"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-sm btn-outline-primary">Salvar</button></form></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php require __DIR__ . '/includes/footer.php';
