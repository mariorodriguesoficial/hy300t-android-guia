<?php
class Auth
{
    public const NIVEIS = ['admin' => 'Administrador', 'caixa' => 'Caixa', 'garcom' => 'Garçom'];

    public static function login(string $login, string $senha): bool
    {
        $u = Database::one('SELECT * FROM usuarios WHERE login = ? AND ativo = 1', [$login]);
        // Compara mesmo quando o login não existe (tempo constante, não revela logins válidos)
        $hash = $u['senha'] ?? '$2y$12$usuarioinexistenteXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
        if (!password_verify($senha, $hash) || !$u) { seg_registrar_tentativa($login, false); seg_log($u ? (int) $u['id'] : null, 'login_falhou'); return false; }
        if (password_needs_rehash($u['senha'], seg_algoritmo(), seg_opcoes())) {
            Database::query('UPDATE usuarios SET senha=? WHERE id=?', [hash_senha($senha), $u['id']]);
        }
        seg_registrar_tentativa($login, true);
        seg_iniciar_sessao($u);
        seg_log((int) $u['id'], 'login');
        return true;
    }

    public static function user(): ?array { return $_SESSION['usuario'] ?? null; }

    /** Exige login e, opcionalmente, um dos níveis informados (admin sempre pode). */
    public static function exigir(array $niveis = [], bool $api = false): array
    {
        $u = self::user();
        if (!$u) { if ($api) json_out(['erro' => 'Não autenticado'], 401); redirect('login.php'); }
        if ($niveis && $u['nivel'] !== 'admin' && !in_array($u['nivel'], $niveis, true)) {
            if ($api) json_out(['erro' => 'Sem permissão'], 403);
            flash('Você não tem permissão para acessar esta página.', 'danger');
            redirect('index.php');
        }
        return $u;
    }

    public static function pode(array $niveis): bool
    {
        $u = self::user();
        return $u && ($u['nivel'] === 'admin' || in_array($u['nivel'], $niveis, true));
    }
}

require_once __DIR__ . '/seguranca.php';
seg_bootstrap();
