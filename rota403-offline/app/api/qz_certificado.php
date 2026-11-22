<?php
// Certificado PUBLICO do sistema (nao e segredo): o QZ Tray o compara com o override.crt
// instalado no PC. Sem bloqueios aqui, para o QZ nunca receber erro e liberar "Remember".
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$arq = __DIR__ . '/../config/qz-certificado.crt';
if (!is_file($arq)) { http_response_code(500); exit('Certificado nao encontrado em config/qz-certificado.crt'); }
readfile($arq);
