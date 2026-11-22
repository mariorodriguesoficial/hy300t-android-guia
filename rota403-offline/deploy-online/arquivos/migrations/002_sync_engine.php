<?php
/**
 * 002 — Motor de sincronização: outbox (feed de mudanças), estado por registro, caixa de entrada
 * idempotente, conflitos, dispositivos, nonces anti-replay e log de auditoria.
 *
 * outbox.via: NULL = mudança originada neste banco; senão = id do dispositivo de quem recebemos
 * (permite relay no hub sem devolver o evento ao remetente e sem ecoar do cliente para o servidor).
 */
$spec = require dirname(__DIR__) . '/includes/SyncSpec.php';
$up = [];
$up[] = "CREATE TABLE IF NOT EXISTS sync_outbox (
    seq BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id CHAR(36) NOT NULL DEFAULT (UUID()),
    tabela VARCHAR(40) NOT NULL, uuid CHAR(36) NOT NULL, rev INT UNSIGNED NOT NULL, op CHAR(1) NOT NULL DEFAULT 'U',
    via CHAR(36) NULL, forcar TINYINT(1) NOT NULL DEFAULT 0,
    estado ENUM('pendente','enviado','rejeitado') NOT NULL DEFAULT 'pendente',
    tentativas INT NOT NULL DEFAULT 0, erro VARCHAR(255) NULL,
    criado_utc DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY uq_event (event_id), KEY idx_pend (estado, via, seq), KEY idx_pull (via, seq), KEY idx_row (tabela, uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "CREATE TABLE IF NOT EXISTS sync_state (tabela VARCHAR(40) NOT NULL, uuid CHAR(36) NOT NULL, synced_rev INT UNSIGNED NOT NULL,
    PRIMARY KEY (tabela, uuid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "CREATE TABLE IF NOT EXISTS sync_inbox (event_id CHAR(36) NOT NULL PRIMARY KEY, tabela VARCHAR(40) NOT NULL, uuid CHAR(36) NOT NULL,
    resultado VARCHAR(20) NOT NULL DEFAULT 'aceito', recebido_utc DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "CREATE TABLE IF NOT EXISTS sync_deferred (event_id CHAR(36) NOT NULL PRIMARY KEY, evento_json MEDIUMTEXT NOT NULL, tentativas INT NOT NULL DEFAULT 0,
    detalhe VARCHAR(255) NULL, criado_utc DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "CREATE TABLE IF NOT EXISTS sync_conflicts (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(40) NOT NULL, uuid CHAR(36) NOT NULL, tipo VARCHAR(40) NOT NULL,
    campos VARCHAR(255) NULL, local_json MEDIUMTEXT NULL, remoto_json MEDIUMTEXT NULL, origem CHAR(36) NULL, evento_id CHAR(36) NULL,
    criado_utc DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    estado ENUM('aberto','resolvido') NOT NULL DEFAULT 'aberto', decisao VARCHAR(20) NULL,
    resolvido_por VARCHAR(100) NULL, resolvido_utc DATETIME(3) NULL, justificativa VARCHAR(500) NULL,
    KEY idx_estado (estado, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "CREATE TABLE IF NOT EXISTS sync_devices (
    device_id CHAR(36) NOT NULL PRIMARY KEY, nome VARCHAR(80) NOT NULL, secret_enc TEXT NOT NULL,
    criado_utc DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(), revogado_utc DATETIME NULL, ultimo_acesso_utc DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "CREATE TABLE IF NOT EXISTS sync_nonces (nonce CHAR(32) NOT NULL PRIMARY KEY, device_id CHAR(36) NOT NULL, expira_utc DATETIME NOT NULL,
    KEY idx_exp (expira_utc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "CREATE TABLE IF NOT EXISTS sync_log (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, criado_utc DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)), device_id CHAR(36) NULL,
    acao VARCHAR(20) NOT NULL, http INT NOT NULL DEFAULT 200, eventos INT NOT NULL DEFAULT 0, detalhe VARCHAR(255) NULL,
    KEY idx_dev (device_id, criado_utc)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

foreach ($spec as $t => $s) {
    if ($t === 'estoque_movimentos') {
        $up[] = "DROP TRIGGER IF EXISTS trg_estoque_movimentos_sync_ai";
        $up[] = "CREATE TRIGGER trg_estoque_movimentos_sync_ai AFTER INSERT ON estoque_movimentos FOR EACH ROW
            INSERT INTO sync_outbox (tabela, uuid, rev, op, via) VALUES ('estoque_movimentos', NEW.uuid, 1, 'I', @sync_via)";
        continue;
    }
    $iguais = implode(' AND ', array_map(fn($c) => "NEW.`$c` <=> OLD.`$c`", $s['cols']));
    $up[] = "DROP TRIGGER IF EXISTS trg_{$t}_sync_ai";
    $up[] = "CREATE TRIGGER trg_{$t}_sync_ai AFTER INSERT ON `$t` FOR EACH ROW
        INSERT INTO sync_outbox (tabela, uuid, rev, op, via) VALUES ('$t', NEW.uuid, NEW.rev, 'U', @sync_via)";
    $up[] = "DROP TRIGGER IF EXISTS trg_{$t}_sync_au";
    $up[] = "CREATE TRIGGER trg_{$t}_sync_au AFTER UPDATE ON `$t` FOR EACH ROW BEGIN
        IF NOT ($iguais) THEN INSERT INTO sync_outbox (tabela, uuid, rev, op, via) VALUES ('$t', NEW.uuid, NEW.rev, 'U', @sync_via); END IF; END";
    $up[] = "DROP TRIGGER IF EXISTS trg_{$t}_sync_ad";
    $up[] = "CREATE TRIGGER trg_{$t}_sync_ad AFTER DELETE ON `$t` FOR EACH ROW
        INSERT INTO sync_outbox (tabela, uuid, rev, op, via) VALUES ('$t', OLD.uuid, OLD.rev + 1, 'D', @sync_via)";
}
return ['nome' => 'sync_engine', 'up' => $up];
