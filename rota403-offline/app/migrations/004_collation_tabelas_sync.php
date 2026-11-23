<?php
/**
 * 004 — Alinha a collation das tabelas de sincronização com a das tabelas do sistema (utf8mb4_unicode_ci).
 * As tabelas criadas nas migrações 001/002 herdaram a collation PADRÃO do banco, que varia por servidor; comparar
 * colunas uuid entre tabelas de collations diferentes gera "Illegal mix of collations" (erro 1267) — ex.: no `semear`.
 * Idempotente (converter para a mesma collation é inofensivo).
 */
$tabelas = ['sync_meta', 'idempotencia', 'sync_tombstones', 'estoque_movimentos', 'sync_outbox', 'sync_state', 'sync_inbox',
            'sync_deferred', 'sync_conflicts', 'sync_devices', 'sync_nonces', 'sync_log'];
$up = [];
foreach ($tabelas as $t) $up[] = "ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
return ['nome' => 'collation_tabelas_sync', 'up' => $up];
