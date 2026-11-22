#!/usr/bin/env bash
# Sobe MariaDB+PHP temporários e roda tests/pwa_test.js (Playwright/Chromium com emulação de celular).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; T="$(mktemp -d)"; PW="t$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
cleanup(){ for f in p.pid php.pid; do [ -f "$T/$f" ] && kill "$(cat $T/$f)" 2>/dev/null || true; done; [ -f "$T/cgi.pids" ] && kill $(cat "$T/cgi.pids") 2>/dev/null || true; rm -rf "$T"; }
trap cleanup EXIT
mkdir -p "$T/data" "$T/app"
mariadb-install-db --user=root --datadir="$T/data" --auth-root-authentication-method=normal >/dev/null 2>&1
mariadbd --user=root --datadir="$T/data" --port=3397 --bind-address=127.0.0.1 --socket="$T/s.sock" --pid-file="$T/p.pid" --skip-name-resolve >"$T/db.log" 2>&1 &
for i in $(seq 30); do mariadb -S "$T/s.sock" -uroot -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
SQL="mariadb -S $T/s.sock -uroot"
$SQL -e "CREATE DATABASE rota403_pwa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'rota403'@'127.0.0.1' IDENTIFIED BY '$PW'; GRANT ALL ON rota403_pwa.* TO 'rota403'@'127.0.0.1';"
$SQL rota403_pwa < "$ROOT/baseline-kit/schema-only.sql"
cp -r "$ROOT/app/." "$T/app/"; rm -f "$T/app/config/config.local.php" "$T/app/config/chave_seguranca.php"; mkdir -p "$T/app/storage"
cat > "$T/app/config/config.local.php" <<PHP
<?php return ['DB_HOST'=>'127.0.0.1','DB_PORT'=>'3397','DB_USER'=>'rota403','DB_PASS'=>'$PW','DB_NAME'=>'rota403_pwa','BASE_URL'=>''];
PHP
(cd "$T/app" && php bin/migrate.php >/dev/null)
(cd "$T/app" && php -r 'require "config/config.php";
 foreach ([["Admin","admin","admin"],["Maria Garçom","maria","garcom"]] as [$n,$l,$v]) Database::query("INSERT INTO usuarios (nome,login,senha,nivel) VALUES (?,?,?,?)",[cifrar($n),$l,hash_senha("Senha1234"),$v]);
 Database::query("INSERT INTO categorias (nome,ordem) VALUES (\"Bebidas\",1),(\"Espetos\",2)");
 $c=Database::val("SELECT id FROM categorias WHERE nome=\"Bebidas\""); $e=Database::val("SELECT id FROM categorias WHERE nome=\"Espetos\"");
 Database::query("INSERT INTO produtos (nome,preco,categoria_id,estoque,destino,codigo_barras) VALUES (\"Cerveja lata\",7.5,?,100,\"balcao\",\"1001\"),(\"Espeto de carne\",12,?,100,\"cozinha\",\"1002\")",[$c,$e]);
 Database::query("INSERT INTO caixas (usuario_id,saldo_inicial) VALUES (1,0)");')
if [ "${STACK:-}" = "caddy" ]; then   # mesma pilha do Windows: Caddy + pool de php-cgi
  for p in 9101 9102 9103 9104; do PHP_FCGI_MAX_REQUESTS=500 php-cgi -b 127.0.0.1:$p >"$T/cgi$p.log" 2>&1 & echo $! >> "$T/cgi.pids"; done
  php "$T/app/bin/gerar-caddyfile.php" --raiz="$T/app" --dados="$T/dados" --porta-http=8105 --cgi=9101,9102,9103,9104 >/dev/null
  caddy run --config "$T/dados/Caddyfile" --adapter caddyfile >"$T/caddy.log" 2>&1 & echo $! > "$T/php.pid"
else
  php -S 127.0.0.1:8105 -t "$T/app" "$T/app/router.php" >"$T/php.log" 2>&1 & echo $! > "$T/php.pid"
fi
sleep 2
export BASE=http://127.0.0.1:8105 SOCK="$T/s.sock" DBNAME=rota403_pwa SHOTS="${SHOTS:-}" NODE_PATH="$(npm root -g)"
node "$ROOT/tests/pwa_test.js"
