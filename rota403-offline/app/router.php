<?php
// Roteador do servidor embutido do PHP (equivale ao rewrite do .htaccess: /comandas -> comandas.php).
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$f = __DIR__ . $p;
if ($p === '/' ) { require __DIR__ . '/index.php'; return true; }
if (preg_match('#^/(config|includes|storage)(/|$)#', $p) || preg_match('#\.(sql|md|txt|log|pem|key)$#i', $p)) { http_response_code(403); exit('Acesso negado'); }
if (is_file($f)) return false;                       // estáticos e .php explícitos
if (is_file($f . '.php')) { $_SERVER['SCRIPT_NAME'] = $p . '.php'; require $f . '.php'; return true; }
http_response_code(404); echo 'Não encontrado';
