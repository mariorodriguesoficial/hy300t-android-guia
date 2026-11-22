<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente linha de comando.'); }
// Uso: php bin/migrate.php [--status]
require_once __DIR__ . '/../config/config.php';
$pdo = Database::pdo();
if (in_array('--status', $argv, true)) { echo 'Versão do esquema: ' . Migrator::versaoAtual($pdo) . PHP_EOL; exit(0); }
foreach (Migrator::aplicar($pdo) as $m) echo "Aplicada: $m" . PHP_EOL;
echo 'Versão do esquema: ' . Migrator::versaoAtual($pdo) . PHP_EOL;
