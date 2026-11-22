<?php
/**
 * Cria o PRIMEIRO administrador (usado pelo instalador). Recusa se já existir usuário.
 * Lê JSON {"nome","login","senha"} de um arquivo (--arquivo=) — a senha nunca vai na linha de comando.
 */
require_once __DIR__ . '/../config/config.php';
$f = null; foreach ($argv as $a) if (preg_match('/^--arquivo=(.+)$/', $a, $m)) $f = $m[1];
if (!$f || !is_file($f)) { fwrite(STDERR, "Informe --arquivo=caminho.json\n"); exit(2); }
$d = json_decode((string) file_get_contents($f), true);
if (!is_array($d)) { fwrite(STDERR, "JSON inválido\n"); exit(2); }
$nome = trim((string) ($d['nome'] ?? '')); $login = trim((string) ($d['login'] ?? '')); $senha = (string) ($d['senha'] ?? '');
if ($nome === '' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $login)) { fwrite(STDERR, "Nome obrigatório e login com 3–50 caracteres (letras, números, . _ -)\n"); exit(2); }
try { validar_senha_nova($senha); } catch (RuntimeException $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(2); }
if ((int) Database::val('SELECT COUNT(*) FROM usuarios') > 0) { fwrite(STDERR, "Já existe usuário; nada foi criado.\n"); exit(3); }
try {
    Database::query("INSERT INTO usuarios (nome, login, senha, nivel) VALUES (?,?,?,'admin')", [cifrar($nome), $login, hash_senha($senha)]);
} catch (Throwable $e) { fwrite(STDERR, 'Falha ao criar administrador: ' . $e->getMessage() . "\n"); exit(1); }
echo "Administrador criado: $login\n";
