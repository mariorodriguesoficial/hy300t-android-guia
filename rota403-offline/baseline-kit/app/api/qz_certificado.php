<?php
http_response_code(503);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['erro' => 'Assinatura QZ Tray não configurada nesta compilação offline. Configure certificado próprio para impressão segura.'], JSON_UNESCAPED_UNICODE);
