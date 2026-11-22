#!/usr/bin/env bash
# Dois ambientes (online=hub PHP:8101 / local=cliente+worker) em bancos separados no mesmo MariaDB temporário.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; T="$(mktemp -d)"; PW="t$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
cleanup(){ for f in p.pid on.pid lo.pid; do [ -f "$T/$f" ] && kill "$(cat $T/$f)" 2>/dev/null || true; done; rm -rf "$T"; }
trap cleanup EXIT
mkdir -p "$T/data"
mariadb-install-db --user=root --datadir="$T/data" --auth-root-authentication-method=normal >/dev/null 2>&1
mariadbd --user=root --datadir="$T/data" --port=3398 --bind-address=127.0.0.1 --socket="$T/s.sock" --pid-file="$T/p.pid" --skip-name-resolve >"$T/db.log" 2>&1 &
for i in $(seq 30); do mariadb -S "$T/s.sock" -uroot -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
SQL="mariadb -S $T/s.sock -uroot"
for n in online local; do
  $SQL -e "CREATE DATABASE rota403_$n CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'rota403'@'127.0.0.1' IDENTIFIED BY '$PW'; GRANT ALL ON rota403_$n.* TO 'rota403'@'127.0.0.1';"
  $SQL rota403_$n < "$ROOT/baseline-kit/schema-only.sql"
  mkdir -p "$T/$n"; cp -r "$ROOT/app/." "$T/$n/"; rm -f "$T/$n/config/config.local.php" "$T/$n/config/chave_seguranca.php"; mkdir -p "$T/$n/storage"
done
cfg(){ # $1=nome $2=extra
cat > "$T/$1/config/config.local.php" <<PHP
<?php return ['DB_HOST'=>'127.0.0.1','DB_PORT'=>'3398','DB_USER'=>'rota403','DB_PASS'=>'$PW','DB_NAME'=>'rota403_$1','BASE_URL'=>'','SYNC_REQUIRE_HTTPS'=>false $2];
PHP
}
if [ "${ONLINE_V23:-}" = "1" ]; then
  # Servidor ONLINE realista: o v23 ORIGINAL (como está na Hostinger) + somente o pacote deploy-online + 3 linhas no config.php
  "$ROOT/tools/montar-pacote-online.sh" >/dev/null
  rm -rf "$T/online"; mkdir -p "$T/online"; cp -r "$ROOT/baseline-v23/." "$T/online/"; cp -r "$ROOT/deploy-online/arquivos/." "$T/online/"
  rm -f "$T/online/sync-setup-web.php"; cp "$ROOT/app/router.php" "$T/online/router.php"; mkdir -p "$T/online/storage" "$T/online/config"
  cat > "$T/online/config/config.php" <<PHP
<?php
// config.php no estilo do v23 (credenciais por define) + as 3 linhas do deploy
define('DB_HOST', '127.0.0.1;port=3398'); define('DB_USER', 'rota403'); define('DB_PASS', '$PW'); define('DB_NAME', 'rota403_online');
define('EMPRESA_NOME', 'ROTA 403'); define('EMPRESA_SUB', 'teste'); define('BASE_URL', ''); define('BASE_PATH', dirname(__DIR__));
define('SYNC_REQUIRE_HTTPS', false);
date_default_timezone_set('America/Sao_Paulo'); ini_set('display_errors', '0');
if (session_status() === PHP_SESSION_NONE) { session_set_cookie_params(['path' => BASE_URL . '/', 'httponly' => true, 'samesite' => 'Lax']); session_start(); }
require_once BASE_PATH . '/includes/Database.php';
require_once BASE_PATH . '/includes/helpers.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/Migrator.php';
require_once BASE_PATH . '/includes/Sync.php';
require_once BASE_PATH . '/includes/SyncServer.php';
PHP
  cfg local ""
else
  cfg online ""; cfg local ""
fi
(cd "$T/online" && php bin/migrate.php >/dev/null)
(cd "$T/local" && php bin/migrate.php >/dev/null)
php -S 127.0.0.1:8101 -t "$T/online" "$T/online/router.php" >"$T/on.log" 2>&1 & echo $! > "$T/on.pid"
php -S 127.0.0.1:8102 -t "$T/local" "$T/local/router.php" >"$T/lo.log" 2>&1 & echo $! > "$T/lo.pid"
sleep 1
export T SOCK="$T/s.sock" PW ROOT
python3 -I "$ROOT/tests/test_sync_engine.py" -v "$@"
