<?php
class Auth
{
    public const NIVEIS = ['admin' => 'Administrador', 'caixa' => 'Caixa', 'garcom' => 'Garçom'];

    public static function login(string $login, string $senha): bool
    {
        $u = Database::one('SELECT * FROM usuarios WHERE login = ? AND ativo = 1', [$login]);
        if (!$u || !password_verify($senha, $u['senha'])) return false;
        session_regenerate_id(true);
        $_SESSION['usuario'] = ['id' => $u['id'], 'nome' => $u['nome'], 'nivel' => $u['nivel']];
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
