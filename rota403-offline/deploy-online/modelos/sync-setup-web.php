<?php
/**
 * Ferramenta de USO ÚNICO para quem NÃO tem acesso SSH à hospedagem.
 * 1) Troque TOKEN abaixo por 40+ caracteres aleatórios ANTES de enviar o arquivo.
 * 2) Envie para a pasta do sistema (ex.: /rota403/), abra pelo navegador (HTTPS), use e APAGUE O ARQUIVO.
 * Ele se apaga sozinho depois de criar o dispositivo. Sem o token correto, nada acontece.
 */
const TOKEN = 'TROQUE-ESTE-TOKEN-POR-40-OU-MAIS-CARACTERES-ALEATORIOS';

require_once __DIR__ . '/config/config.php';
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
function fim(string $m, int $c = 200): never { http_response_code($c); echo '<pre>' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</pre>'; exit; }
if (strlen(TOKEN) < 40 || str_starts_with(TOKEN, 'TROQUE')) fim('Defina TOKEN (40+ caracteres aleatórios) dentro do arquivo antes de usar.', 403);
if (!((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'))) fim('Use HTTPS.', 400);
if (!class_exists('Migrator') || !class_exists('Sync') || !class_exists('SyncServer')) fim('Faltam as 3 linhas require no config/config.php (veja LEIA-ME-DEPLOY).', 500);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo '<form method="post" style="font:16px system-ui;max-width:460px;margin:30px auto"><h3>Rota 403 — preparar sincronização</h3>
    <p>Token <input name="token" type="password" style="width:100%"></p>
    <p>Ação <select name="acao" style="width:100%"><option value="status">1. Ver estado</option><option value="migrar">2. Aplicar migrações (faça BACKUP antes!)</option>
    <option value="semear">3. Enfileirar dados existentes</option><option value="dispositivo">4. Criar dispositivo do PDV (mostra o segredo UMA vez e apaga este arquivo)</option></select></p>
    <p>Nome do dispositivo <input name="nome" value="PDV Balcao" style="width:100%"></p><button>Executar</button></form>';
    exit;
}
if (!hash_equals(TOKEN, (string) ($_POST['token'] ?? ''))) { sleep(3); fim('Negado.', 403); }
$acao = (string) ($_POST['acao'] ?? '');
try {
    $pdo = Database::pdo();
    if ($acao === 'status') {
        fim("Esquema: versão " . Migrator::versaoAtual($pdo) . "\nDispositivos: " . (int) Database::val('SELECT COUNT(*) FROM sync_devices') . "\nPendências no outbox: " . (int) Database::val('SELECT COUNT(*) FROM sync_outbox'));
    }
    if ($acao === 'migrar') { $n = Migrator::aplicar($pdo); fim("Aplicadas agora: " . ($n ? implode(', ', $n) : 'nenhuma (já estava em dia)') . "\nVersão atual: " . Migrator::versaoAtual($pdo)); }
    if ($acao === 'semear') { fim("Enfileirado: " . json_encode(Sync::semear())); }
    if ($acao === 'dispositivo') {
        $d = SyncServer::novoDispositivo(trim((string) ($_POST['nome'] ?? 'PDV')) ?: 'PDV');
        $apagou = @unlink(__FILE__);
        fim("device_id: {$d['device_id']}\nsecret (aparece UMA vez; copie agora): {$d['secret']}\n\nEste arquivo " . ($apagou ? 'foi APAGADO automaticamente.' : 'NÃO conseguiu se apagar: APAGUE-O MANUALMENTE AGORA.'));
    }
    fim('Ação inválida.', 400);
} catch (Throwable $e) { fim('Erro: ' . $e->getMessage(), 500); }
