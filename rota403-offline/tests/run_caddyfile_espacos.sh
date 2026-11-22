#!/usr/bin/env bash
# Regressão: o Caddyfile gerado deve ser válido e funcionar com caminhos que têm ESPAÇOS (C:\Program Files\...).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; T="$(mktemp -d)"
trap 'pkill -f "caddy run --config $T" 2>/dev/null || true; [ -f "$T/cgi.pid" ] && kill "$(cat $T/cgi.pid)" 2>/dev/null || true; rm -rf "$T"' EXIT
mkdir -p "$T/Program Files/Rota403/app" "$T/ProgramData/Rota403"; echo '<?php echo "ok-espacos";' > "$T/Program Files/Rota403/app/index.php"
for lan in 0 1; do
  extra=""; [ "$lan" = 1 ] && extra="--lan=1 --ips=127.0.0.1"
  php "$ROOT/app/bin/gerar-caddyfile.php" --raiz="$T/Program Files/Rota403/app" --dados="$T/ProgramData/Rota403" --porta-http=8190 --cgi=9191 $extra >/dev/null
  caddy validate --config "$T/ProgramData/Rota403/Caddyfile" --adapter caddyfile 2>&1 | grep -q "Valid configuration" && echo "PASS Caddyfile válido (lan=$lan) com espaços no caminho" || { echo "FAIL Caddyfile inválido (lan=$lan)"; exit 1; }
done
# sobe com a configuração SEM LAN (a padrão da instalação) e confere onde o Caddy escuta
php "$ROOT/app/bin/gerar-caddyfile.php" --raiz="$T/Program Files/Rota403/app" --dados="$T/ProgramData/Rota403" --porta-http=8190 --cgi=9191 >/dev/null
PHP_FCGI_MAX_REQUESTS=500 php-cgi -b 127.0.0.1:9191 >/dev/null 2>&1 & echo $! > "$T/cgi.pid"
caddy run --config "$T/ProgramData/Rota403/Caddyfile" --adapter caddyfile >"$T/c.log" 2>&1 &
sleep 3
[ "$(curl -s -m 5 http://127.0.0.1:8190/index.php)" = "ok-espacos" ] && echo "PASS responde via Caddy+php-cgi em caminho com espaços" || { echo "FAIL sem resposta"; exit 1; }
ESCUTA="$(ss -ltnH | awk '$4 ~ /:8190$/ {print $4}' | sort -u | tr '\n' ' ')"
[ "$ESCUTA" = "127.0.0.1:8190 " ] && echo "PASS HTTP local escuta SÓ em 127.0.0.1 ($ESCUTA)" || { echo "FAIL HTTP escuta em: $ESCUTA"; exit 1; }
