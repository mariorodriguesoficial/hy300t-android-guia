#!/usr/bin/env bash
# Monta deploy-online/arquivos/ (SÓ o necessário para o servidor online falar com o PDV local) + zip.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; D="$ROOT/deploy-online"; A="$D/arquivos"
rm -rf "$A" "$D/rota403-sync-online.zip"; mkdir -p "$A/includes" "$A/migrations" "$A/api/sync/v1" "$A/bin"
for f in Migrator Sync SyncSpec SyncServer; do cp "$ROOT/app/includes/$f.php" "$A/includes/"; done
cp "$ROOT/app/migrations/"*.php "$A/migrations/"
cp "$ROOT/app/api/sync/v1/"*.php "$A/api/sync/v1/"
cp "$ROOT/app/bin/migrate.php" "$ROOT/app/bin/sync-admin.php" "$A/bin/"
cp "$D/modelos/sync-setup-web.php" "$A/sync-setup-web.php"
(cd "$A" && zip -qr "$D/rota403-sync-online.zip" .)
(cd "$A" && find . -type f | sort)
