<?php
/**
 * Migrações versionadas. Arquivos migrations/NNN_nome.php retornam
 *   ['nome' => '...', 'up' => [ 'SQL', ... ]]
 * Todas as instruções devem ser idempotentes (IF NOT EXISTS / DROP ... IF EXISTS),
 * pois DDL não é transacional: se uma falhar no meio, rodar de novo é seguro.
 * Convive com os garantir*() do sistema original (que criam tabelas sob demanda).
 */
class Migrator
{
    public static function dir(): string { return dirname(__DIR__) . '/migrations'; }

    public static function garantirTabela(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            versao INT NOT NULL PRIMARY KEY, nome VARCHAR(100) NOT NULL, checksum CHAR(64) NOT NULL,
            aplicada_em_utc DATETIME NOT NULL DEFAULT UTC_TIMESTAMP()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** @return array<int,array{nome:string,up:array,checksum:string,arquivo:string}> */
    public static function disponiveis(): array
    {
        $r = [];
        foreach (glob(self::dir() . '/[0-9][0-9][0-9]_*.php') ?: [] as $f) {
            $v = (int) substr(basename($f), 0, 3);
            $m = require $f;
            $r[$v] = ['nome' => $m['nome'], 'up' => $m['up'], 'arquivo' => $f, 'checksum' => hash('sha256', json_encode($m['up']))];
        }
        ksort($r);
        return $r;
    }

    /** @return array<int,string> versões aplicadas agora */
    public static function aplicar(PDO $pdo, ?int $ate = null): array
    {
        self::garantirTabela($pdo);
        $feitas = [];
        foreach ($pdo->query('SELECT versao, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR) as $v => $c) $feitas[(int) $v] = $c;
        $novas = [];
        foreach (self::disponiveis() as $v => $m) {
            if ($ate !== null && $v > $ate) break;
            if (isset($feitas[$v])) {
                if ($feitas[$v] !== $m['checksum']) throw new RuntimeException("Migração $v foi alterada depois de aplicada (checksum diferente).");
                continue;
            }
            foreach ($m['up'] as $sql) $pdo->exec($sql);
            $st = $pdo->prepare('INSERT INTO schema_migrations (versao, nome, checksum) VALUES (?,?,?)');
            $st->execute([$v, $m['nome'], $m['checksum']]);
            $novas[] = $v . ' ' . $m['nome'];
        }
        return $novas;
    }

    public static function versaoAtual(PDO $pdo): int
    {
        self::garantirTabela($pdo);
        return (int) $pdo->query('SELECT COALESCE(MAX(versao),0) FROM schema_migrations')->fetchColumn();
    }
}
