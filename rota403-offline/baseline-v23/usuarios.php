<?php
require_once __DIR__ . '/config/config.php';
$eu = Auth::exigir(['__admin__']);

// Login protegido: o usuário Supervisor não pode ser editado, excluído nem desativado.
const LOGIN_SUPERVISOR = 'supervisor';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['acao'] ?? '';
    $nivel = array_key_exists($_POST['nivel'] ?? '', Auth::NIVEIS) ? $_POST['nivel'] : 'garcom';
    try {
        if ($a === 'criar') {
            validar_senha_nova((string) ($_POST['senha'] ?? ''));
            $nomeNovo = trim((string) ($_POST['nome'] ?? '')); $loginNovo = trim((string) ($_POST['login'] ?? ''));
            if ($nomeNovo === '' || $loginNovo === '' || mb_strlen($nomeNovo) > 100 || mb_strlen($loginNovo) > 50) throw new RuntimeException('Informe nome (até 100) e login (até 50 caracteres).');
            Database::query('INSERT INTO usuarios (nome, login, senha, nivel) VALUES (?,?,?,?)', [cifrar($nomeNovo), $loginNovo, hash_senha($_POST['senha']), $nivel]);
            flash('Usuário criado.');
        } elseif ($a === 'editar') {
            $id = (int) $_POST['id'];
            $alvo = Database::one('SELECT * FROM usuarios WHERE id=?', [$id]);
            if (!$alvo) throw new RuntimeException('Usuário não encontrado.');
            if ($alvo['login'] === LOGIN_SUPERVISOR) throw new RuntimeException('O usuário Supervisor não pode ser alterado.');
            $login = trim($_POST['login'] ?? '');
            if ($login === '') throw new RuntimeException('O login não pode ficar em branco.');
            if ($login === LOGIN_SUPERVISOR) throw new RuntimeException('Esse login é reservado.');
            Database::query('UPDATE usuarios SET nome=?, login=?, nivel=?, ativo=? WHERE id=?', [cifrar(trim((string) $_POST['nome'])), $login, $id === (int) $eu['id'] ? 'admin' : $nivel, $id === (int) $eu['id'] ? 1 : (isset($_POST['ativo']) ? 1 : 0), $id]);
            if (!empty($_POST['senha'])) {
                validar_senha_nova((string) $_POST['senha']);
                Database::query('UPDATE usuarios SET senha=? WHERE id=?', [hash_senha($_POST['senha']), $id]);
            }
            flash('Usuário atualizado.');
        } elseif ($a === 'excluir') {
            $id = (int) $_POST['id'];
            $alvo = Database::one('SELECT * FROM usuarios WHERE id=?', [$id]);
            if (!$alvo) throw new RuntimeException('Usuário não encontrado.');
            if ($alvo['login'] === LOGIN_SUPERVISOR) throw new RuntimeException('O usuário Supervisor não pode ser excluído.');
            if ($id === (int) $eu['id']) throw new RuntimeException('Você não pode excluir o seu próprio usuário.');
            Database::query('DELETE FROM usuarios WHERE id=?', [$id]);
            flash('Usuário excluído.');
        }
    } catch (PDOException $e) { flash('Esse login já existe.', 'danger'); }
    catch (RuntimeException $e) { flash($e->getMessage(), 'danger'); }
    redirect('usuarios.php');
}

$usuarios = Database::all('SELECT id, nome, login, nivel, ativo FROM usuarios');
foreach ($usuarios as &$x) $x['nome'] = decifrar($x['nome']);
unset($x);
usort($usuarios, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
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
  <thead><tr><th>Nome</th><th>Login</th><th>Nível</th><th>Ativo</th><th></th></tr></thead>
  <tbody><?php foreach ($usuarios as $x): $sup = $x['login'] === LOGIN_SUPERVISOR; ?>
    <tr>
      <td><?= e($x['nome']) ?></td>
      <td><?= e($x['login']) ?></td>
      <td><?= e(Auth::NIVEIS[$x['nivel']] ?? $x['nivel']) ?></td>
      <td><?= $x['ativo'] ? 'Sim' : 'Não' ?></td>
      <td class="text-end text-nowrap">
        <?php if ($sup): ?>
          <span class="badge bg-secondary">Protegido</span>
        <?php else: ?>
          <button type="button" class="btn btn-sm btn-outline-primary"
            data-bs-toggle="modal" data-bs-target="#modalEditar"
            data-id="<?= $x['id'] ?>" data-nome="<?= e($x['nome']) ?>" data-login="<?= e($x['login']) ?>"
            data-nivel="<?= e($x['nivel']) ?>" data-ativo="<?= $x['ativo'] ? 1 : 0 ?>">Editar</button>
          <?php if ((int) $x['id'] !== (int) $eu['id']): ?>
            <form method="post" class="d-inline" data-confirm="Excluir este usuário?"><?= csrf_field() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-sm btn-outline-danger">Excluir</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>

<div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content">
      <?= csrf_field() ?><input type="hidden" name="acao" value="editar"><input type="hidden" name="id" id="edit-id">
      <div class="modal-header"><h5 class="modal-title">Editar usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Nome</label><input name="nome" id="edit-nome" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Login</label><input name="login" id="edit-login" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Nível de acesso</label>
          <select name="nivel" id="edit-nivel" class="form-select"><?php foreach (Auth::NIVEIS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
        </div>
        <div class="mb-2"><label class="form-label">Nova senha</label><input name="senha" type="password" class="form-control" placeholder="Deixe em branco para manter"></div>
        <div class="form-check"><input type="checkbox" name="ativo" id="edit-ativo" class="form-check-input" value="1"><label class="form-check-label" for="edit-ativo">Usuário ativo</label></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-ember">Salvar alterações</button></div>
    </form>
  </div>
</div>
<script>
document.getElementById('modalEditar').addEventListener('show.bs.modal', function (ev) {
  var b = ev.relatedTarget;
  document.getElementById('edit-id').value = b.dataset.id;
  document.getElementById('edit-nome').value = b.dataset.nome;
  document.getElementById('edit-login').value = b.dataset.login;
  document.getElementById('edit-nivel').value = b.dataset.nivel;
  document.getElementById('edit-ativo').checked = b.dataset.ativo === '1';
});
</script>
<?php require __DIR__ . '/includes/footer.php';
