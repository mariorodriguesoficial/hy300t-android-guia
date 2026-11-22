<?php
/**
 * 001 — Base de sincronização (sem alterar PKs existentes).
 *  - uuid global, rev (versão), updated_at_utc, origin_device, deleted_at_utc nas tabelas operacionais
 *  - triggers: incrementam rev/updated_at_utc em QUALQUER escrita (inclusive código legado) e registram
 *    tombstone em DELETE. Quando o aplicador de sync grava, define @sync_aplicando=1 e os triggers não interferem.
 *  - estoque_movimentos: livro-razão de estoque (delta), alimentado por trigger em produtos.estoque
 *  - sync_meta: identidade do dispositivo; idempotencia: chaves de lançamento
 * Tabelas fora do escopo: usuarios (política de segurança), configuracoes, login_*, log_acesso, fila_impressao (locais).
 */
$spec = require dirname(__DIR__) . '/includes/SyncSpec.php';
$tabelas = array_values(array_filter(array_keys($spec), fn($t) => $t !== 'estoque_movimentos'));

$up = [];
// O sistema original cria estas estruturas sob demanda (garantir*()). Garante-as antes dos triggers.
$up[] = "CREATE TABLE IF NOT EXISTS formas_pagamento (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(40) NOT NULL UNIQUE, ordem INT NOT NULL DEFAULT 0, criado_em DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "CREATE TABLE IF NOT EXISTS comanda_pagamentos (id INT AUTO_INCREMENT PRIMARY KEY, comanda_id INT NOT NULL, forma VARCHAR(40) NOT NULL, valor DECIMAL(10,2) NOT NULL, recebido DECIMAL(10,2) NOT NULL DEFAULT 0, criado_em DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX (comanda_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "ALTER TABLE comandas ADD COLUMN IF NOT EXISTS impressa TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN IF NOT EXISTS troco DECIMAL(10,2) NOT NULL DEFAULT 0";
$up[] = "ALTER TABLE produtos ADD COLUMN IF NOT EXISTS unidade VARCHAR(3) NOT NULL DEFAULT 'un'";
$up[] = "CREATE TABLE IF NOT EXISTS sync_meta (k VARCHAR(40) NOT NULL PRIMARY KEY, v VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "INSERT IGNORE INTO sync_meta (k, v) VALUES ('device_id', UUID())";
$up[] = "CREATE TABLE IF NOT EXISTS idempotencia (chave VARCHAR(64) NOT NULL PRIMARY KEY, comanda_id INT NOT NULL, criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "CREATE TABLE IF NOT EXISTS sync_tombstones (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, tabela VARCHAR(40) NOT NULL, uuid CHAR(36) NOT NULL, rev INT UNSIGNED NOT NULL,
    deleted_at_utc DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)), KEY idx_tab (tabela, uuid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$up[] = "CREATE TABLE IF NOT EXISTS estoque_movimentos (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL DEFAULT (UUID()),
    produto_uuid CHAR(36) NOT NULL,
    delta DECIMAL(12,3) NOT NULL,
    motivo VARCHAR(30) NOT NULL DEFAULT 'legado',
    origin_device CHAR(36) NULL,
    criado_em_utc DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    UNIQUE KEY uq_uuid (uuid), KEY idx_prod (produto_uuid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

foreach ($tabelas as $t) {
    $up[] = "ALTER TABLE `$t`
        ADD COLUMN IF NOT EXISTS uuid CHAR(36) NULL,
        ADD COLUMN IF NOT EXISTS rev INT UNSIGNED NOT NULL DEFAULT 1,
        ADD COLUMN IF NOT EXISTS updated_at_utc DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
        ADD COLUMN IF NOT EXISTS origin_device CHAR(36) NULL,
        ADD COLUMN IF NOT EXISTS deleted_at_utc DATETIME(3) NULL";
    $up[] = "UPDATE `$t` SET uuid = UUID() WHERE uuid IS NULL";
    $up[] = "ALTER TABLE `$t` MODIFY uuid CHAR(36) NOT NULL DEFAULT (UUID())";
    $up[] = "ALTER TABLE `$t` ADD UNIQUE INDEX IF NOT EXISTS uq_{$t}_uuid (uuid)";
    $up[] = "UPDATE `$t` SET origin_device = (SELECT v FROM sync_meta WHERE k='device_id') WHERE origin_device IS NULL";

    $cond = "(@sync_aplicando IS NULL OR @sync_aplicando = 0)";
    // rev só sobe quando muda coluna de NEGÓCIO (estoque, total, impressa/impresso não contam)
    $iguais = implode(' AND ', array_map(fn($c) => "NEW.`$c` <=> OLD.`$c`", $spec[$t]['cols']));
    $bump = "IF $cond AND NOT ($iguais) THEN SET NEW.rev = OLD.rev + 1; SET NEW.updated_at_utc = UTC_TIMESTAMP(3); END IF;";
    $up[] = "DROP TRIGGER IF EXISTS trg_{$t}_bu";
    $up[] = "CREATE TRIGGER trg_{$t}_bu BEFORE UPDATE ON `$t` FOR EACH ROW BEGIN $bump END";
    $up[] = "DROP TRIGGER IF EXISTS trg_{$t}_bi";
    $up[] = "CREATE TRIGGER trg_{$t}_bi BEFORE INSERT ON `$t` FOR EACH ROW BEGIN
        IF NEW.origin_device IS NULL THEN SET NEW.origin_device = (SELECT v FROM sync_meta WHERE k='device_id'); END IF; END";
    $up[] = "DROP TRIGGER IF EXISTS trg_{$t}_ad";
    $up[] = "CREATE TRIGGER trg_{$t}_ad AFTER DELETE ON `$t` FOR EACH ROW BEGIN
        IF $cond THEN INSERT INTO sync_tombstones (tabela, uuid, rev) VALUES ('$t', OLD.uuid, OLD.rev + 1); END IF; END";
}

// Estoque inicial de produto novo também é um movimento (senão o outro lado nunca o recebe)
$up[] = "DROP TRIGGER IF EXISTS trg_produtos_estoque_ai";
$up[] = "CREATE TRIGGER trg_produtos_estoque_ai AFTER INSERT ON produtos FOR EACH ROW BEGIN
    IF NEW.estoque <> 0 AND (@sync_aplicando IS NULL OR @sync_aplicando = 0) THEN
        INSERT INTO estoque_movimentos (produto_uuid, delta, motivo, origin_device)
        VALUES (NEW.uuid, NEW.estoque, 'inicial', (SELECT v FROM sync_meta WHERE k='device_id'));
    END IF; END";

// Livro-razão de estoque: toda variação de produtos.estoque vira um movimento (delta)
$up[] = "DROP TRIGGER IF EXISTS trg_produtos_estoque_au";
$up[] = "CREATE TRIGGER trg_produtos_estoque_au AFTER UPDATE ON produtos FOR EACH ROW BEGIN
    IF NOT (NEW.estoque <=> OLD.estoque) AND (@sync_aplicando IS NULL OR @sync_aplicando = 0) THEN
        INSERT INTO estoque_movimentos (produto_uuid, delta, motivo, origin_device)
        VALUES (NEW.uuid, NEW.estoque - OLD.estoque, COALESCE(@mov_motivo, 'legado'), (SELECT v FROM sync_meta WHERE k='device_id'));
    END IF; END";

return ['nome' => 'sync_base', 'up' => $up];
