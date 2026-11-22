<?php
/** Autenticação, anti-replay, rate limit e utilidades dos endpoints /api/sync/v1/*. Nunca usa a sessão de navegador. */
final class SyncServer
{
    const LIMITE_POR_MINUTO = 120;

    public static function resp(array $d, int $http = 200): never
    {
        header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
        json_out($d, $http);
    }
    public static function log(?string $dev, string $acao, int $http, int $n = 0, ?string $det = null): void
    {
        try { Database::query('INSERT INTO sync_log (device_id, acao, http, eventos, detalhe) VALUES (?,?,?,?,?)', [$dev, $acao, $http, $n, $det ? mb_substr($det, 0, 255) : null]); } catch (Throwable $e) {}
    }
    public static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
    public static function falhar(?string $dev, string $acao, int $http, string $msg): never
    {
        self::log($dev, $acao, $http, 0, $msg); self::resp(['ok' => false, 'erro' => $msg], $http);
    }

    /** Valida HTTPS, método, dispositivo, janela de tempo, nonce, HMAC e limite. Devolve [device_id, corpo bruto]. */
    public static function autenticar(string $acao, string $metodoEsperado): array
    {
        if (defined('SYNC_REQUIRE_HTTPS') ? SYNC_REQUIRE_HTTPS : true) { if (!self::https()) self::falhar(null, $acao, 400, 'HTTPS obrigatório'); }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodoEsperado) self::falhar(null, $acao, 405, 'Método inválido');
        $dev = (string) ($_SERVER['HTTP_X_DEVICE'] ?? ''); $ts = (string) ($_SERVER['HTTP_X_TIMESTAMP'] ?? '');
        $nonce = (string) ($_SERVER['HTTP_X_NONCE'] ?? ''); $sig = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');
        if (!preg_match(Sync::UUID_RE, $dev) || !ctype_digit($ts) || !preg_match('/^[0-9a-f]{32}$/', $nonce) || !preg_match('/^[0-9a-f]{64}$/', $sig)) self::falhar(null, $acao, 401, 'Autenticação ausente ou malformada');
        $body = file_get_contents('php://input', false, null, 0, Sync::MAX_BODY + 1) ?: '';
        if (strlen($body) > Sync::MAX_BODY) self::falhar($dev, $acao, 413, 'Payload grande demais');
        $d = Database::one('SELECT * FROM sync_devices WHERE device_id=?', [$dev]);
        if (!$d || $d['revogado_utc'] !== null) self::falhar($dev, $acao, 401, 'Dispositivo desconhecido ou revogado');
        if (abs(time() - (int) $ts) > Sync::JANELA_TS) self::falhar($dev, $acao, 401, 'Relógio fora da janela de 5 min (verifique data/hora)');
        $segredo = decifrar($d['secret_enc']);
        $esperada = Sync::assinar($segredo, $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $ts, $nonce, $body);
        if (!hash_equals($esperada, $sig)) self::falhar($dev, $acao, 401, 'Assinatura inválida');
        Database::query('DELETE FROM sync_nonces WHERE expira_utc < UTC_TIMESTAMP()');
        $n = Database::query('INSERT IGNORE INTO sync_nonces (nonce, device_id, expira_utc) VALUES (?,?, UTC_TIMESTAMP() + INTERVAL 15 MINUTE)', [$nonce, $dev]);
        if ($n->rowCount() === 0) self::falhar($dev, $acao, 401, 'Requisição repetida (replay)');
        if ((int) Database::val("SELECT COUNT(*) FROM sync_log WHERE device_id=? AND criado_utc > (UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE)", [$dev]) > self::LIMITE_POR_MINUTO) self::falhar($dev, $acao, 429, 'Limite de requisições excedido');
        Database::query('UPDATE sync_devices SET ultimo_acesso_utc = UTC_TIMESTAMP() WHERE device_id=?', [$dev]);
        return [$dev, $body];
    }

    public static function novoDispositivo(string $nome): array
    {
        $id = (string) Database::val('SELECT UUID()'); $segredo = bin2hex(random_bytes(32));
        Database::query('INSERT INTO sync_devices (device_id, nome, secret_enc) VALUES (?,?,?)', [$id, mb_substr($nome, 0, 80), cifrar($segredo)]);
        return ['device_id' => $id, 'secret' => $segredo];
    }
}
