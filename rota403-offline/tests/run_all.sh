#!/usr/bin/env bash
# Sobe MariaDB temporário (porta 3399) + PHP embutido (8099), carrega o esquema, roda a suíte e derruba tudo.
# Requer: mariadbd, mariadb-install-db, php, python3.   Uso: tests/run_all.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; T="$(mktemp -d)"; PW="t$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
cleanup(){ [ -f "$T/p.pid" ] && kill "$(cat $T/p.pid)" 2>/dev/null || true; [ -f "$T/php.pid" ] && kill "$(cat $T/php.pid)" 2>/dev/null || true; rm -rf "$T"; }
trap cleanup EXIT
mkdir -p "$T/data" "$T/app"
mariadb-install-db --user=root --datadir="$T/data" --auth-root-authentication-method=normal >/dev/null 2>&1
mariadbd --user=root --datadir="$T/data" --port=3399 --bind-address=127.0.0.1 --socket="$T/s.sock" --pid-file="$T/p.pid" --skip-name-resolve >"$T/db.log" 2>&1 &
for i in $(seq 30); do mariadb -S "$T/s.sock" -uroot -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
SQL="mariadb -S $T/s.sock -uroot"
$SQL -e "CREATE DATABASE rota403_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'rota403'@'127.0.0.1' IDENTIFIED BY '$PW'; GRANT ALL ON rota403_test.* TO 'rota403'@'127.0.0.1';"
$SQL rota403_test < "$ROOT/baseline-kit/schema-only.sql"
cp -r "$ROOT/app/." "$T/app/"; rm -f "$T/app/config/config.local.php" "$T/app/config/chave_seguranca.php"; mkdir -p "$T/app/storage"
cat > "$T/app/config/config.local.php" <<PHP
<?php return ['DB_HOST'=>'127.0.0.1','DB_PORT'=>'3399','DB_USER'=>'rota403','DB_PASS'=>'$PW','DB_NAME'=>'rota403_test','BASE_URL'=>''];
PHP
(cd "$T/app" && php bin/migrate.php >/dev/null)
php -d output_buffering=0 -d opcache.enable=0 -S 127.0.0.1:8099 -t "$T/app" "$T/app/router.php" >"$T/php.log" 2>&1 & echo $! > "$T/php.pid"
sleep 1
BASE=http://127.0.0.1:8099 SOCK="$T/s.sock" DBNAME=rota403_test APPDIR="$T/app" python3 -I "$ROOT/tests/test_sync_base.py" -v
