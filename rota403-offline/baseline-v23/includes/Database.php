<?php
class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            try {
                self::$pdo = new PDO(
                    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                    DB_USER, DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
                );
                self::$pdo->exec("SET time_zone = '-03:00'");
            } catch (PDOException $e) {
                http_response_code(500);
                die('<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Falha na conexão</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;background:#f8f4ec;font-family:Arial,sans-serif}.aviso{width:min(440px,calc(100vw - 32px));box-sizing:border-box;padding:22px;border-radius:10px;background:#d9530f;color:#fff;box-shadow:0 18px 48px rgba(31,24,19,.35);text-align:center}.aviso b{display:block;font-size:20px;margin-bottom:8px}</style></head><body><div class="aviso"><b>Falha na conexão</b><span>Verifique os dados do banco em config/config.php.</span></div><script>setTimeout(function(){var aviso=document.querySelector(".aviso");if(aviso)aviso.remove();},2000);</script></body></html>');
            }
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $p = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st;
    }

    public static function all(string $sql, array $p = []): array { return self::query($sql, $p)->fetchAll(); }
    public static function one(string $sql, array $p = []): array|false { return self::query($sql, $p)->fetch(); }
    public static function val(string $sql, array $p = []): mixed { return self::query($sql, $p)->fetchColumn(); }
    public static function insert(string $sql, array $p = []): int { self::query($sql, $p); return (int) self::pdo()->lastInsertId(); }
}
