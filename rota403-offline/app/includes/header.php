<?php
$u = Auth::user();
$atual = basename($_SERVER['SCRIPT_NAME']);
$menu = [
    ['comandas.php', 'Comandas', 'bi-receipt', ['caixa', 'garcom']],
    ['garcom.php', 'Garçom', 'bi-phone', ['caixa', 'garcom']],
    ['caixa.php', 'Caixa', 'bi-cash-coin', ['caixa']],
    ['produtos.php', 'Estoque', 'bi-box-seam', ['__admin__']],
    ['categorias.php', 'Categorias', 'bi-tags', ['__admin__']],
    ['relatorios.php', 'Relatórios', 'bi-graph-up', ['__admin__']],
    ['usuarios.php', 'Usuários', 'bi-people', ['__admin__']],
    ['sync_conflitos.php', 'Sincronização', 'bi-arrow-repeat', ['__admin__']],
    ['configuracoes.php', 'Configurações', 'bi-gear', ['__admin__']],
    ['impressao.php', 'Monitor Impressão', 'bi-printer', ['caixa']],
];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= csrf_token() ?>">
<title><?= e($titulo ?? 'Comandas') ?> — Rota 403</title>
<link rel="manifest" href="<?= url('manifest.php') ?>">
<meta name="theme-color" content="#d9530f">
<meta name="mobile-web-app-capable" content="yes">
<link rel="apple-touch-icon" href="<?= url('assets/pwa/apple-touch-icon.png') ?>">
<link href="<?= url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= url('assets/vendor/bootstrap/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="<?= url('assets/style.css?v=' . (int) (@filemtime(__DIR__ . '/../assets/style.css') ?: 36)) ?>" rel="stylesheet">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <?php $emp = empresa(); ?><div class="brand"><?php if ($emp['logo'] && is_file(BASE_PATH . '/' . $emp['logo'])): ?><img class="brand-logo" src="<?= e(url($emp['logo'])) ?>?v=<?= filemtime(BASE_PATH . '/' . $emp['logo']) ?>" alt=""><?php else: ?><span class="ember-dot"><i class="bi bi-fire"></i></span><?php endif; ?><span class="brand-name"><?= e($emp['nome']) ?></span></div>
    <nav>
      <?php foreach ($menu as [$arq, $label, $ico, $niv]): if (!Auth::pode($niv)) continue; ?>
        <a href="<?= url($arq) ?>"<?= $arq === 'impressao.php' ? ' data-monitor-popup' : '' ?> class="<?= $atual === $arq || ($arq === 'comandas.php' && $atual === 'comanda.php') || ($arq === 'produtos.php' && $atual === 'produto_form.php') ? 'active' : '' ?>"><i class="bi <?= $ico ?>"></i><span><?= $label ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="user-box"><small><?= e($u['nome'] ?? '') ?> • <?= e(Auth::NIVEIS[$u['nivel'] ?? 'garcom'] ?? '') ?></small>
      <a href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i><span>Sair</span></a></div>
  </aside>
  <main class="content<?= empty($telaFixa) ? '' : ' tela-fixa' ?>">
  <?php if ($f = flash()): ?><div class="aviso-fundo"><div class="aviso-central" role="status" aria-live="polite"><span><?= e($f[0]) ?></span><button type="button" class="aviso-fechar" aria-label="Fechar aviso">&times;</button></div></div><?php endif; ?>
