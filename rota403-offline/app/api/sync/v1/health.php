<?php
// Saúde pública e mínima: não revela dados. (Sem autenticação por desenho.)
require_once __DIR__ . '/../../../config/config.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
try { $v = Migrator::versaoAtual(Database::pdo()); echo json_encode(['ok' => true, 'api' => 1, 'esquema' => $v >= 2]); }
catch (Throwable $e) { http_response_code(503); echo json_encode(['ok' => false]); }
