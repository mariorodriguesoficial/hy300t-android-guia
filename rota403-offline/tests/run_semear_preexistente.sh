#!/usr/bin/env bash
# Regressão: `semear` num banco que JÁ TINHA DADOS antes da migração, com servidor de collation padrão diferente (general_ci).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; T="$(mktemp -d)"; trap '[ -f "$T/p.pid" ] && kill "$(cat $T/p.pid)" 2>/dev/null; rm -rf "$T"' EXIT
mkdir -p "$T/data" "$T/app/config"
mariadb-install-db --user=root --datadir="$T/data" --auth-root-authentication-method=normal >/dev/null 2>&1
mariadbd --user=root --datadir="$T/data" --port=3393 --bind-address=127.0.0.1 --socket="$T/s.sock" --pid-file="$T/p.pid" --skip-name-resolve --collation-server=utf8mb4_general_ci --character-set-server=utf8mb4 >"$T/db.log" 2>&1 &
for i in $(seq 30); do mariadb -S "$T/s.sock" -uroot -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
R="mariadb -S $T/s.sock -uroot"
$R -e "create database r403 character set utf8mb4 collate utf8mb4_unicode_ci; create user 'rota403'@'127.0.0.1' identified by 'x1'; grant all on r403.* to 'rota403'@'127.0.0.1'"
$R r403 < "$ROOT/baseline-kit/schema-only.sql"
$R r403 -e "insert into categorias(nome,ordem) values('Bebidas',1),('Espetos',2); insert into produtos(nome,preco,estoque,destino) values('Cola',6,40,'balcao'),('Espeto',12,25,'cozinha'); insert into caixas(usuario_id,saldo_inicial) values(1,0); insert into comandas(identificacao,tipo,codigo) values('3','mesa','CMD000003');"
mkdir -p "$T/app/includes"; cp -r "$ROOT/deploy-online/arquivos/." "$T/app/"; cp "$ROOT/app/migrations/004_collation_tabelas_sync.php" "$T/app/migrations/"
for f in Database helpers Auth seguranca fotos_produtos; do cp "$ROOT/baseline-v23/includes/$f.php" "$T/app/includes/"; done
cat > "$T/app/config/config.php" <<'PHP'
<?php
define('DB_HOST', '127.0.0.1;port=3393'); define('DB_USER', 'rota403'); define('DB_PASS', 'x1'); define('DB_NAME', 'r403');
define('EMPRESA_NOME', 'ROTA 403'); define('EMPRESA_SUB', 't'); define('BASE_URL', ''); define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('America/Sao_Paulo'); ini_set('display_errors', '1');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once BASE_PATH . '/includes/Database.php'; require_once BASE_PATH . '/includes/helpers.php'; require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/Migrator.php'; require_once BASE_PATH . '/includes/Sync.php'; require_once BASE_PATH . '/includes/SyncServer.php';
PHP
cd "$T/app"
php bin/migrate.php | tail -1
OUT="$(php bin/sync-admin.php semear 2>&1 | tr -d '\n ' )"; echo "semear 1ª vez: $OUT"
echo "$OUT" | grep -q "Illegal mix" && { echo "FAIL collation"; exit 1; }
N="$($R r403 -N -B -e 'select count(*) from sync_outbox')"; [ "$N" -ge 6 ] && echo "PASS outbox com $N linhas (categorias, produtos, caixa, comanda, formas_pagamento...)" || { echo "FAIL outbox=$N"; exit 1; }
L="$($R r403 -N -B -e 'select count(*), sum(delta) from estoque_movimentos')"; echo "ledger (movimentos, soma): $L"; [ "$L" = "$(printf '2\t65.000')" ] && echo "PASS saldo inicial do estoque virou movimentos (40+25=65)" || { echo "FAIL ledger"; exit 1; }
OUT2="$(php bin/sync-admin.php semear 2>&1 | tr -d '\n ')"; echo "semear 2ª vez: $OUT2"
echo "$OUT2" | grep -q '=>0' || true
N2="$($R r403 -N -B -e 'select count(*) from sync_outbox')"; [ "$N2" = "$N" ] && echo "PASS 2ª execução não duplica (idempotente)" || { echo "FAIL duplicou $N -> $N2"; exit 1; }
