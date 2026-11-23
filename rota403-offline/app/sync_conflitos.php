<?php
require_once __DIR__ . '/config/config.php';
$u = Auth::exigir(['__admin__']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (($_POST['acao'] ?? '') === 'lote') {
            $n = Sync::resolverLote((string) ($_POST['tipo'] ?? ''), $u['nome'], (string) ($_POST['justificativa'] ?? ''));
            flash("$n conflito(s) resolvido(s) em lote e registrados."); redirect('sync_conflitos.php');
        }
        Sync::resolver((int) ($_POST['id'] ?? 0), (string) ($_POST['decisao'] ?? ''), $u['nome'], (string) ($_POST['justificativa'] ?? ''));
        flash('Conflito resolvido e registrado.');
    } catch (Throwable $e) { flash($e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível resolver.', 'danger'); }
    redirect('sync_conflitos.php');
}
$abertos = Database::all("SELECT * FROM sync_conflicts WHERE estado='aberto' ORDER BY id");
$hist = Database::all("SELECT * FROM sync_conflicts WHERE estado='resolvido' ORDER BY resolvido_utc DESC LIMIT 30");
$pend = (int) Database::val("SELECT COUNT(*) FROM sync_outbox WHERE estado='pendente' AND via IS NULL");
$titulo = 'Sincronização';
require __DIR__ . '/includes/header.php';
$j = fn($s) => json_decode((string) $s, true);
$fmt = fn($v) => is_array($v) ? e(implode(' • ', array_map(fn($k, $x) => $k . '=' . (is_scalar($x) || $x === null ? (string) $x : '…'), array_keys($v), $v))) : e((string) $v);
?>
<h1>Sincronização</h1>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="kpi"><div class="small muted">Pendentes de envio</div><div class="v"><?= $pend ?></div></div></div>
  <div class="col-6 col-md-3"><div class="kpi"><div class="small muted">Conflitos abertos</div><div class="v"><?= count($abertos) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="kpi"><div class="small muted">Última sincronização (UTC)</div><div class="v" style="font-size:1rem"><?= e(Sync::meta('ultima_sync_utc') ?: '—') ?></div></div></div>
  <div class="col-6 col-md-3"><div class="kpi"><div class="small muted">Último erro</div><div class="v" style="font-size:.9rem"><?= e(Sync::meta('ultimo_erro') ?: 'nenhum') ?></div></div></div>
</div>
<?php $porTipo = []; foreach ($abertos as $c) if (in_array($c['tipo'], Sync::TIPOS_INFORMATIVOS, true)) $porTipo[$c['tipo']] = ($porTipo[$c['tipo']] ?? 0) + 1; ?>
<?php if ($porTipo): ?>
<form method="post" class="card-r mb-3" data-confirm="Resolver TODOS os conflitos deste tipo mantendo os valores deste ambiente?">
  <?= csrf_field() ?><input type="hidden" name="acao" value="lote">
  <h2>Resolver em lote (conflitos apenas informativos)</h2>
  <div class="input-group">
    <select name="tipo" class="form-select"><?php foreach ($porTipo as $t => $n): ?><option value="<?= e($t) ?>"><?= e($t) ?> (<?= $n ?>)</option><?php endforeach; ?></select>
    <input name="justificativa" class="form-control" placeholder="Justificativa (obrigatória), ex.: histórico importado, conferido" required maxlength="500">
    <button class="btn btn-ember">Resolver todos</button>
  </div>
  <div class="small muted mt-1">Use só depois de olhar alguns exemplos abaixo. A decisão fica registrada no histórico.</div>
</form>
<?php endif; ?>
<h2>Conflitos</h2>
<?php foreach ($abertos as $c): $propaga = in_array($c['tipo'], ['edicao_concorrente', 'estado_divergente'], true); ?>
  <form method="post" class="card-r mb-2" data-confirm="Registrar esta decisão?">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>">
    <div><b><?= e($c['tipo']) ?></b> — <?= e($c['tabela']) ?> <small class="muted"><?= e($c['uuid']) ?> • <?= e($c['criado_utc']) ?> UTC</small></div>
    <?php if ($c['campos']): ?><div class="small">Campos: <?= e($c['campos']) ?></div><?php endif; ?>
    <div class="row small mt-1"><div class="col-md-6"><b>Este ambiente:</b><br><?= $fmt($j($c['local_json'])) ?></div><div class="col-md-6"><b>Outro ambiente:</b><br><?= $fmt($j($c['remoto_json'])) ?></div></div>
    <div class="input-group mt-2">
      <select name="decisao" class="form-select"><option value="local">Manter o valor deste ambiente</option><?php if ($propaga): ?><option value="remoto">Aplicar o valor do outro ambiente</option><?php endif; ?></select>
      <input name="justificativa" class="form-control" placeholder="Justificativa (obrigatória)" required maxlength="500">
      <button class="btn btn-ember">Resolver</button>
    </div>
  </form>
<?php endforeach; if (!$abertos): ?><p class="muted">Nenhum conflito aberto.</p><?php endif; ?>
<h2 class="mt-4">Histórico</h2>
<div class="card-r p-0 table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Tipo</th><th>Tabela</th><th>Decisão</th><th>Por</th><th>Quando (UTC)</th><th>Justificativa</th></tr></thead><tbody>
<?php foreach ($hist as $c): ?><tr><td><?= e($c['tipo']) ?></td><td><?= e($c['tabela']) ?></td><td><?= e($c['decisao']) ?></td><td><?= e($c['resolvido_por']) ?></td><td><?= e($c['resolvido_utc']) ?></td><td><?= e($c['justificativa']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php require __DIR__ . '/includes/footer.php';
