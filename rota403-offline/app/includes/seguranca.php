<?php
// ============================================================
// Segurança central do sistema
//  - Criptografia dos dados pessoais dos usuários (AES-256-GCM)
//  - Senhas com hash forte (Argon2id ou bcrypt custo 12)
//  - Limite de tentativas de login (por login e por IP)
//  - Sessão protegida (expiração, revalidação no banco)
//  - Políticas de acesso por registro (equivalente ao RLS)
//  - Cabeçalhos de proteção do navegador
// ============================================================

const SEG_MAX_TENTATIVAS = 5;        // erros seguidos antes de bloquear
const SEG_BLOQUEIO_MIN = 15;         // minutos de bloqueio
const SEG_MAX_TENTATIVAS_IP = 20;    // erros por IP (vários logins) na janela
const SEG_SESSAO_OCIOSA = 8 * 3600;  // sai após 8h sem uso
const SEG_SESSAO_MAX = 16 * 3600;    // sai após 16h de qualquer jeito

// ---------- Cabeçalhos de proteção ----------
function seg_cabecalhos(): void
{
    if (headers_sent()) return;
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
    if (seg_https()) header('Strict-Transport-Security: max-age=31536000');
    header_remove('X-Powered-By');
}

function seg_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function seg_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// ---------- Chave de criptografia (gerada uma vez, fica em config/) ----------
function seg_chave(): string
{
    static $chave = null;
    if ($chave !== null) return $chave;
    $arq = BASE_PATH . '/config/chave_seguranca.php';
    if (is_file($arq)) {
        $v = include $arq;
        if (is_string($v) && strlen($v) === 64) return $chave = hex2bin($v);
    }
    $hex = bin2hex(random_bytes(32));
    if (@file_put_contents($arq, "<?php\n// NÃO APAGUE E NÃO COMPARTILHE. Sem esta chave os nomes dos usuários não podem ser lidos.\nreturn '" . $hex . "';\n", LOCK_EX) === false) {
        // Pasta config sem permissão de escrita: usa chave fixa derivada do banco (não derruba o sistema)
        return $chave = hash('sha256', 'sp-seg|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS, true);
    }
    @chmod($arq, 0600);
    return $chave = hex2bin($hex);
}

function cifrar(?string $texto): ?string
{
    if ($texto === null || $texto === '' || str_starts_with($texto, 'enc1:')) return $texto;
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($texto, 'aes-256-gcm', seg_chave(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc1:' . base64_encode($iv . $tag . $c);
}

function decifrar(?string $valor): string
{
    if ($valor === null) return '';
    if (!str_starts_with($valor, 'enc1:')) return $valor; // dado antigo ainda não migrado
    $b = base64_decode(substr($valor, 5), true);
    if ($b === false || strlen($b) < 29) return '—';
    $t = openssl_decrypt(substr($b, 28), 'aes-256-gcm', seg_chave(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    return $t === false ? '—' : $t;
}

// ---------- Senhas ----------
function seg_algoritmo(): string|int { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT; }
function seg_opcoes(): array { return defined('PASSWORD_ARGON2ID') ? [] : ['cost' => 12]; }
function hash_senha(string $senha): string { return password_hash($senha, seg_algoritmo(), seg_opcoes()); }
function validar_senha_nova(string $senha): void
{
    if (strlen($senha) < 8 || !preg_match('/[A-Za-z]/', $senha) || !preg_match('/\d/', $senha)) {
        throw new RuntimeException('A senha precisa ter pelo menos 8 caracteres, com letras e números.');
    }
}

// ---------- Estrutura do banco (criada/ajustada sozinha, uma vez) ----------
function garantirSeguranca(): void
{
    if (!empty($_SESSION['seg_ok_v1'])) return;
    $pdo = Database::pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_tentativas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        login_hash CHAR(64) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        sucesso TINYINT(1) NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_login (login_hash, criado_em),
        KEY idx_ip (ip, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS log_acesso (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NULL,
        evento VARCHAR(40) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_usuario (usuario_id, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $tam = (int) Database::val("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='nome'");
    if ($tam && $tam < 400) $pdo->exec('ALTER TABLE usuarios MODIFY nome VARCHAR(400) NOT NULL');
    // Criptografa nomes antigos que ainda estão em texto aberto
    foreach (Database::all("SELECT id, nome FROM usuarios WHERE nome NOT LIKE 'enc1:%'") as $u) {
        Database::query('UPDATE usuarios SET nome=? WHERE id=?', [cifrar($u['nome']), $u['id']]);
    }
    // Limpa tentativas com mais de 30 dias
    $pdo->exec('DELETE FROM login_tentativas WHERE criado_em < (NOW() - INTERVAL 30 DAY)');
    $_SESSION['seg_ok_v1'] = 1;
}

function seg_log(?int $usuarioId, string $evento): void
{
    try { Database::query('INSERT INTO log_acesso (usuario_id, evento, ip) VALUES (?,?,?)', [$usuarioId, $evento, seg_ip()]); } catch (Throwable $e) {}
}

// ---------- Tentativas de login ----------
function seg_login_hash(string $login): string { return hash_hmac('sha256', mb_strtolower(trim($login)), seg_chave()); }

/** Retorna quantos minutos faltam para liberar (0 = liberado). */
function seg_bloqueio_restante(string $login): int
{
    $h = seg_login_hash($login);
    $janela = SEG_BLOQUEIO_MIN;
    $porLogin = Database::one("SELECT COUNT(*) n, MAX(criado_em) ult FROM login_tentativas
        WHERE login_hash=? AND sucesso=0 AND criado_em >= (NOW() - INTERVAL $janela MINUTE)
        AND id > COALESCE((SELECT MAX(id) FROM login_tentativas WHERE login_hash=? AND sucesso=1), 0)", [$h, $h]);
    $porIp = Database::one("SELECT COUNT(*) n, MAX(criado_em) ult FROM login_tentativas
        WHERE ip=? AND sucesso=0 AND criado_em >= (NOW() - INTERVAL $janela MINUTE)", [seg_ip()]);
    $ult = null;
    if ((int) $porLogin['n'] >= SEG_MAX_TENTATIVAS) $ult = $porLogin['ult'];
    if ((int) $porIp['n'] >= SEG_MAX_TENTATIVAS_IP) $ult = max((string) $ult, (string) $porIp['ult']);
    if (!$ult) return 0;
    $falta = strtotime($ult) + $janela * 60 - time();
    return $falta > 0 ? (int) ceil($falta / 60) : 0;
}

function seg_registrar_tentativa(string $login, bool $sucesso): void
{
    Database::query('INSERT INTO login_tentativas (login_hash, ip, sucesso) VALUES (?,?,?)', [seg_login_hash($login), seg_ip(), $sucesso ? 1 : 0]);
}

function seg_tentativas_restantes(string $login): int
{
    $h = seg_login_hash($login);
    $n = (int) Database::val("SELECT COUNT(*) FROM login_tentativas WHERE login_hash=? AND sucesso=0
        AND criado_em >= (NOW() - INTERVAL " . SEG_BLOQUEIO_MIN . " MINUTE)
        AND id > COALESCE((SELECT MAX(id) FROM login_tentativas WHERE login_hash=? AND sucesso=1), 0)", [$h, $h]);
    return max(0, SEG_MAX_TENTATIVAS - $n);
}

// ---------- Sessão ----------
function seg_validar_sessao(): void
{
    if (empty($_SESSION['usuario'])) return;
    $agora = time();
    $ua = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $expirou = ($agora - (int) ($_SESSION['seg_ultimo'] ?? $agora)) > SEG_SESSAO_OCIOSA
        || ($agora - (int) ($_SESSION['seg_inicio'] ?? $agora)) > SEG_SESSAO_MAX
        || (($_SESSION['seg_ua'] ?? $ua) !== $ua);
    if (!$expirou) {
        // Revalida no banco: usuário desativado, excluído ou com nível alterado perde o acesso na hora
        $u = Database::one('SELECT id, nome, nivel, ativo FROM usuarios WHERE id=?', [(int) $_SESSION['usuario']['id']]);
        if ($u && (int) $u['ativo'] === 1) {
            $_SESSION['usuario']['nivel'] = $u['nivel'];
            $_SESSION['usuario']['nome'] = decifrar($u['nome']);
            $_SESSION['seg_ultimo'] = $agora;
            return;
        }
    }
    $csrf = $_SESSION['csrf'] ?? null;
    $_SESSION = [];
    session_regenerate_id(true);
    if ($csrf) $_SESSION['csrf'] = $csrf;
}

function seg_iniciar_sessao(array $u): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf']); // novo token CSRF após o login
    $_SESSION['usuario'] = ['id' => (int) $u['id'], 'nome' => decifrar($u['nome']), 'nivel' => $u['nivel']];
    $_SESSION['seg_inicio'] = $_SESSION['seg_ultimo'] = time();
    $_SESSION['seg_ua'] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

// ---------- Políticas de acesso por registro (equivalente ao RLS) ----------
// Cada função decide se o usuário logado pode ver/alterar aquele registro.
// Todas as telas e APIs passam por aqui antes de devolver dados.

/** Comanda: admin e caixa veem todas; garçom só vê/altera comandas ABERTAS. */
function politica_comanda(array $u, int $id, bool $alterar = false): array
{
    $c = Database::one('SELECT * FROM comandas WHERE id=?', [$id]);
    if (!$c) throw new RuntimeException('Comanda não encontrada.');
    if ($u['nivel'] === 'garcom' && $c['status'] !== 'aberta') throw new RuntimeException('Esta comanda já foi fechada. Somente o caixa pode consultá-la.');
    if ($alterar && $c['status'] !== 'aberta') throw new RuntimeException('Comanda fechada não pode ser alterada.');
    return $c;
}

/** Caixa (movimento): admin vê todos; caixa só vê o caixa que ele mesmo abriu. */
function politica_caixa(array $u, int $id): array
{
    $cx = Database::one('SELECT * FROM caixas WHERE id=?', [$id]);
    if (!$cx) throw new RuntimeException('Caixa não encontrado.');
    if ($u['nivel'] !== 'admin' && (int) $cx['usuario_id'] !== (int) $u['id']) throw new RuntimeException('Você só pode consultar o caixa que você abriu.');
    return $cx;
}

/** Usuários: somente o administrador consulta dados de outros usuários. */
function politica_usuario(array $u, int $id): bool
{
    return $u['nivel'] === 'admin' || (int) $u['id'] === $id;
}


// ---------- Inicialização automática (funciona mesmo com config.php antigo) ----------
function seg_bootstrap(): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    set_exception_handler(function (Throwable $e) {
        @error_log(date('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine() . "\n", 3, BASE_PATH . '/config/erros.log');
        if (!headers_sent()) http_response_code(500);
        erro_central('Erro no sistema', 'Ocorreu um erro: ' . $e->getMessage(), 500);
    });
    try {
        seg_cabecalhos();
        garantirSeguranca();
        seg_validar_sessao();
    } catch (Throwable $e) {
        @error_log(date('c') . ' seg_bootstrap: ' . $e->getMessage() . "\n", 3, BASE_PATH . '/config/erros.log');
    }
}
