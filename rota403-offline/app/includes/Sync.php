<?php
/**
 * Motor de sincronização (emissor + aplicador). Ver docs/04-arquitetura-sincronizacao.md.
 * Nada aqui roda no caminho de uma venda: o PDV grava no banco local e os triggers enfileiram.
 */
final class SyncRetry extends RuntimeException {}    // dependência ainda ausente: tentar de novo depois
final class SyncRejeitado extends RuntimeException {} // evento inválido/não permitido: não adianta repetir

final class Sync
{
    const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    const MAX_EVENTOS = 200;
    const MAX_BODY = 1048576;
    const JANELA_TS = 300;

    private static ?array $spec = null;
    public static function spec(): array { return self::$spec ??= require __DIR__ . '/SyncSpec.php'; }
    private static function pdo(): PDO { return Database::pdo(); }

    public static function deviceId(): string { return (string) Database::val("SELECT v FROM sync_meta WHERE k='device_id'"); }
    public static function meta(string $k, ?string $v = null): ?string
    {
        if ($v !== null) { Database::query('INSERT INTO sync_meta (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $v]); return $v; }
        $r = Database::val('SELECT v FROM sync_meta WHERE k=?', [$k]); return $r === false ? null : (string) $r;
    }

    // ================= Assinatura HMAC (cliente e servidor) =================
    public static function baseAssinatura(string $metodo, string $pathQuery, string $ts, string $nonce, string $body): string
    {
        return strtoupper($metodo) . "\n" . $pathQuery . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);
    }
    public static function assinar(string $segredo, string $metodo, string $pathQuery, string $ts, string $nonce, string $body): string
    {
        return hash_hmac('sha256', self::baseAssinatura($metodo, $pathQuery, $ts, $nonce, $body), $segredo);
    }

    // ================= Sementes: dados que já existiam antes da migração =================
    /** Enfileira linhas existentes (uma vez) e cria saldo inicial no livro-razão. Idempotente. */
    public static function semear(): array
    {
        $r = [];
        foreach (self::spec() as $t => $s) {
            if ($t === 'estoque_movimentos') continue;
            $st = self::query("INSERT INTO sync_outbox (tabela, uuid, rev, op) SELECT '$t', x.uuid, x.rev, 'U' FROM `$t` x
                WHERE NOT EXISTS (SELECT 1 FROM sync_outbox o WHERE o.tabela='$t' AND o.uuid=x.uuid)");
            $r[$t] = $st->rowCount();
        }
        $st = self::query("INSERT INTO estoque_movimentos (produto_uuid, delta, motivo, origin_device)
            SELECT p.uuid, p.estoque - COALESCE((SELECT SUM(m.delta) FROM estoque_movimentos m WHERE m.produto_uuid=p.uuid),0), 'saldo_migracao', (SELECT v FROM sync_meta WHERE k='device_id')
            FROM produtos p WHERE p.estoque - COALESCE((SELECT SUM(m.delta) FROM estoque_movimentos m WHERE m.produto_uuid=p.uuid),0) <> 0");
        $r['estoque_movimentos'] = $st->rowCount();
        return $r;
    }
    private static function query(string $sql, array $p = []): PDOStatement { return Database::query($sql, $p); }

    // ================= EMISSÃO =================
    /** Constrói o evento a partir do estado ATUAL da linha (colapsa várias mudanças em uma). */
    public static function construirEvento(array $ob): array
    {
        $t = $ob['tabela']; $s = self::spec()[$t];
        $base = ['event_id' => $ob['event_id'], 'tabela' => $t, 'uuid' => $ob['uuid']];
        if (!empty($ob['forcar'])) $base['forcar'] = 1;
        if ($t === 'estoque_movimentos') {
            $m = Database::one('SELECT uuid, produto_uuid, delta, motivo, origin_device, criado_em_utc FROM estoque_movimentos WHERE uuid=?', [$ob['uuid']]);
            if (!$m) throw new RuntimeException('movimento ausente');
            return $base + ['op' => 'I', 'rev' => 1, 'origin_device' => $m['origin_device'], 'data' => ['produto_uuid' => $m['produto_uuid'], 'delta' => $m['delta'], 'motivo' => $m['motivo'], 'criado_em_utc' => $m['criado_em_utc']]];
        }
        $row = Database::one("SELECT * FROM `$t` WHERE uuid=?", [$ob['uuid']]);
        if (!$row) return $base + ['op' => 'D', 'rev' => (int) $ob['rev'], 'origin_device' => self::deviceId(), 'data' => new stdClass()];
        $data = [];
        foreach (array_merge($s['cols'], $s['extra']) as $c) {
            if (isset($s['fk'][$c])) {
                $data[$c . '_uuid'] = $row[$c] === null ? null : (Database::val('SELECT uuid FROM `' . $s['fk'][$c] . '` WHERE id=?', [$row[$c]]) ?: null);
            } else $data[$c] = $row[$c];
        }
        if ($t === 'comandas') $data['id_origem'] = (int) $row['id'];
        return $base + ['op' => 'U', 'rev' => (int) $row['rev'], 'origin_device' => $row['origin_device'], 'data' => $data];
    }

    // ================= APLICAÇÃO (cliente ao puxar; servidor ao receber push) =================
    /** @return array{status:string,detalhe?:string}  status: aceito|duplicado|conflito|rejeitado|retry */
    public static function aplicar(array $ev, string $via): array
    {
        $pdo = self::pdo();
        try { self::validar($ev); }
        catch (SyncRejeitado $e) { return ['status' => 'rejeitado', 'detalhe' => $e->getMessage()]; }
        $pdo->exec('SET @sync_aplicando = 1');
        Database::query('SET @sync_via = ?', [$via]);
        try {
            $pdo->beginTransaction();
            $ins = Database::query('INSERT IGNORE INTO sync_inbox (event_id, tabela, uuid) VALUES (?,?,?)', [$ev['event_id'], $ev['tabela'], $ev['uuid']]);
            if ($ins->rowCount() === 0) { $pdo->rollBack(); return ['status' => 'duplicado']; }
            $res = self::aplicarEvento($ev, $via);
            Database::query('UPDATE sync_inbox SET resultado=? WHERE event_id=?', [$res, $ev['event_id']]);
            $pdo->commit();
            return ['status' => $res];
        } catch (SyncRetry $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['status' => 'retry', 'detalhe' => $e->getMessage()];
        } catch (SyncRejeitado $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['status' => 'rejeitado', 'detalhe' => $e->getMessage()];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('sync aplicar: ' . $e->getMessage());
            return ['status' => 'retry', 'detalhe' => 'erro interno'];
        } finally {
            $pdo->exec('SET @sync_aplicando = 0'); $pdo->exec('SET @sync_via = NULL');
        }
    }

    private static function validar(array $ev): void
    {
        if (isset($ev['forcar']) && $ev['forcar'] !== 1) throw new SyncRejeitado('forcar inválido');
        foreach (['event_id', 'tabela', 'uuid', 'op', 'rev'] as $k) if (!isset($ev[$k])) throw new SyncRejeitado("campo ausente: $k");
        if (!preg_match(self::UUID_RE, (string) $ev['event_id']) || !preg_match(self::UUID_RE, (string) $ev['uuid'])) throw new SyncRejeitado('uuid inválido');
        $spec = self::spec();
        if (!isset($spec[$ev['tabela']])) throw new SyncRejeitado('tabela não sincronizável');
        if (!in_array($ev['op'], ['U', 'I', 'D'], true)) throw new SyncRejeitado('operação inválida');
        if (!is_int($ev['rev']) || $ev['rev'] < 1) throw new SyncRejeitado('rev inválida');
        $data = (array) ($ev['data'] ?? []);
        if ($ev['op'] !== 'D') {
            $s = $spec[$ev['tabela']];
            $ok = $ev['tabela'] === 'estoque_movimentos' ? ['produto_uuid', 'delta', 'motivo', 'criado_em_utc']
                : array_merge(array_map(fn($c) => isset($s['fk'][$c]) ? $c . '_uuid' : $c, array_merge($s['cols'], $s['extra'])), $ev['tabela'] === 'comandas' ? ['id_origem'] : []);
            if (array_diff(array_keys($data), $ok)) throw new SyncRejeitado('campos não permitidos: ' . implode(',', array_diff(array_keys($data), $ok)));
        }
    }

    private static function aplicarEvento(array $ev, string $via): string
    {
        $t = $ev['tabela']; $s = self::spec()[$t]; $data = (array) ($ev['data'] ?? []);
        if ($t === 'estoque_movimentos') return self::aplicarMovimento($ev, $data);
        if ($ev['op'] === 'D') return self::aplicarExclusao($ev, $s);
        $local = Database::one("SELECT * FROM `$t` WHERE uuid=?", [$ev['uuid']]);
        if (!$local && ($s['natural'] ?? null)) $local = self::adotarPorChaveNatural($t, $s, $ev, $data);
        if (!$local) return self::inserir($ev, $s, $data, $via);
        if ($s['modo'] === 'imutavel') return 'duplicado'; // pagamentos nunca são reescritos
        if (!empty($ev['forcar'])) return self::aplicarForcado($ev, $s, $data, $local);
        return self::atualizar($ev, $s, $data, $local, $via);
    }

    private static function sincronizado(string $t, string $uuid): int
    {
        $r = Database::val('SELECT synced_rev FROM sync_state WHERE tabela=? AND uuid=?', [$t, $uuid]); return $r === false ? 0 : (int) $r;
    }
    private static function marcar(string $t, string $uuid, int $rev): void
    {
        Database::query('INSERT INTO sync_state (tabela, uuid, synced_rev) VALUES (?,?,?) ON DUPLICATE KEY UPDATE synced_rev=VALUES(synced_rev)', [$t, $uuid, $rev]);
    }

    /** Converte <fk>_uuid em id local; ausência do pai => retry. */
    private static function traduzir(array $s, array $data): array
    {
        $o = [];
        foreach (array_merge($s['cols'], $s['extra']) as $c) {
            if (isset($s['fk'][$c])) {
                $u = $data[$c . '_uuid'] ?? null;
                if ($u === null) { $o[$c] = null; continue; }
                $id = Database::val('SELECT id FROM `' . $s['fk'][$c] . '` WHERE uuid=?', [$u]);
                if ($id === false) throw new SyncRetry('pai ausente: ' . $s['fk'][$c] . ' ' . $u);
                $o[$c] = (int) $id;
            } elseif (array_key_exists($c, $data)) $o[$c] = $data[$c];
        }
        return $o;
    }

    private static function inserir(array $ev, array $s, array $data, string $via): string
    {
        $t = $ev['tabela']; $v = self::traduzir($s, $data);
        if ($t === 'caixas') $v['usuario_id'] = (int) (Database::val("SELECT MIN(id) FROM usuarios WHERE nivel='admin'") ?: Database::val('SELECT MIN(id) FROM usuarios') ?: 1);
        if ($t === 'comandas') {
            $v['impressa'] = 1; $v['total'] = 0;
            // Reaproveita o nº da origem só se couber ABAIXO do contador local (não pode empurrar o AUTO_INCREMENT para a faixa
            // de outro dispositivo). Senão usa nº próprio; o `codigo` (CMD…) da origem é preservado e é o que o leitor usa.
            $idO = (int) ($data['id_origem'] ?? 0);
            $prox = (int) Database::val("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comandas'");
            if ($idO > 0 && $idO < $prox && !Database::one('SELECT id FROM comandas WHERE id=?', [$idO])) $v['id'] = $idO;
            if (!empty($v['codigo']) && Database::one('SELECT id FROM comandas WHERE codigo=?', [$v['codigo']])) {
                $orig = $v['codigo']; $v['codigo'] = substr($orig, 0, 14) . '-' . substr($ev['uuid'], 0, 4);
                self::conflito('comandas', $ev['uuid'], 'codigo_duplicado', 'codigo', ['codigo' => $orig], ['codigo_atribuido' => $v['codigo']], $ev);
            }
        }
        if ($t === 'pedidos') $v['impresso'] = 1; // já foi atendido no ambiente de origem: não imprimir de novo aqui
        $v['uuid'] = $ev['uuid']; $v['rev'] = $ev['rev']; $v['origin_device'] = $ev['origin_device'] ?? $via;
        $cols = array_keys($v);
        Database::query('INSERT INTO `' . $t . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($v));
        self::marcar($t, $ev['uuid'], $ev['rev']);
        $res = 'aceito';
        if (in_array($t, ['comanda_itens', 'comanda_pagamentos'], true)) $res = self::posItemOuPagamento($ev, $t, $v);
        return $res;
    }

    /** Recalcula total da comanda (derivado) e sinaliza situações que exigem olho humano. */
    private static function posItemOuPagamento(array $ev, string $t, array $v): string
    {
        $cid = (int) $v['comanda_id'];
        Database::query('UPDATE comandas SET total = (SELECT COALESCE(SUM(subtotal),0) FROM comanda_itens WHERE comanda_id=?) WHERE id=?', [$cid, $cid]);
        $c = Database::one('SELECT * FROM comandas WHERE id=?', [$cid]);
        // Só é conflito o item lançado DEPOIS do fechamento (a comanda já foi paga sem ele). Item que chega junto com uma
        // venda histórica (adicionado_em <= fechado_em) é normal — senão toda importação inicial viraria "conflito".
        // Tolerância de 1 s: venda direta grava itens e fecha no mesmo segundo.
        if ($t === 'comanda_itens' && $c['status'] !== 'aberta') {
            $adic = isset($v['adicionado_em']) ? strtotime((string) $v['adicionado_em']) : false;
            $fech = !empty($c['fechado_em']) ? strtotime((string) $c['fechado_em']) : false;
            if ($adic !== false && $fech !== false && $adic > $fech + 1) {
                self::conflito($t, $ev['uuid'], 'item_em_comanda_' . $c['status'], 'comanda_id', $c, $ev['data'], $ev);
                return 'conflito';
            }
        }
        if ($t === 'comanda_pagamentos') {
            $pago = (float) Database::val('SELECT COALESCE(SUM(valor),0) FROM comanda_pagamentos WHERE comanda_id=?', [$cid]);
            if ($pago > (float) $c['total'] + 0.009) { self::conflito($t, $ev['uuid'], 'pagamento_excede_total', 'valor', ['total' => $c['total'], 'pago' => $pago], $ev['data'], $ev); return 'conflito'; }
        }
        return 'aceito';
    }

    private static function aplicarMovimento(array $ev, array $d): string
    {
        $p = Database::one('SELECT id FROM produtos WHERE uuid=?', [$d['produto_uuid'] ?? '']);
        if (!$p) throw new SyncRetry('produto ausente para movimento');
        if (!is_numeric($d['delta'] ?? null)) throw new SyncRejeitado('delta inválido');
        $ins = Database::query('INSERT IGNORE INTO estoque_movimentos (uuid, produto_uuid, delta, motivo, origin_device, criado_em_utc) VALUES (?,?,?,?,?,?)',
            [$ev['uuid'], $d['produto_uuid'], $d['delta'], substr((string) ($d['motivo'] ?? 'sync'), 0, 30), $ev['origin_device'] ?? null, $d['criado_em_utc'] ?? gmdate('Y-m-d H:i:s.v')]);
        if ($ins->rowCount() === 1) Database::query('UPDATE produtos SET estoque = estoque + ? WHERE id=?', [$d['delta'], $p['id']]);
        return $ins->rowCount() === 1 ? 'aceito' : 'duplicado';
    }

    private static function aplicarExclusao(array $ev, array $s): string
    {
        $t = $ev['tabela'];
        if ($s['modo'] !== 'upsert' || in_array($t, ['comandas', 'caixas'], true)) throw new SyncRejeitado('exclusão não permitida em ' . $t);
        $local = Database::one("SELECT * FROM `$t` WHERE uuid=?", [$ev['uuid']]);
        if (!$local) return 'duplicado';
        if ((int) $local['rev'] > self::sincronizado($t, $ev['uuid'])) { self::conflito($t, $ev['uuid'], 'exclusao_vs_edicao', null, $local, null, $ev); return 'conflito'; }
        Database::query("DELETE FROM `$t` WHERE uuid=?", [$ev['uuid']]);
        if ($t === 'comanda_itens') Database::query('UPDATE comandas SET total = (SELECT COALESCE(SUM(subtotal),0) FROM comanda_itens WHERE comanda_id=?) WHERE id=?', [$local['comanda_id'], $local['comanda_id']]);
        return 'aceito';
    }

    private static function iguais($a, $b): bool
    {
        if ($a === null || $b === null) return $a === $b;
        if (is_numeric($a) && is_numeric($b)) return abs((float) $a - (float) $b) < 0.0005;
        return (string) $a === (string) $b;
    }

    private static function atualizar(array $ev, array $s, array $data, array $local, string $via): string
    {
        $t = $ev['tabela']; $S = self::sincronizado($t, $ev['uuid']);
        if ($ev['rev'] <= $S) return 'duplicado';                       // evento velho/repetido
        $novo = self::traduzir($s, $data);
        $dif = [];
        foreach ($s['cols'] as $c) if (array_key_exists($c, $novo) && !self::iguais($local[$c], $novo[$c])) $dif[] = $c;
        $localMudou = (int) $local['rev'] > $S;
        if (!$dif) { self::marcar($t, $ev['uuid'], $ev['rev']); return 'aceito'; }
        if (!$localMudou) return self::gravar($t, $ev, $novo, $s, max($ev['rev'], 1));
        // ---- as duas pontas mudaram desde o último ponto comum ----
        if ($t === 'comandas') return self::mesclarComanda($ev, $local, $novo, $dif);
        self::conflito($t, $ev['uuid'], 'edicao_concorrente', implode(',', $dif), $local, $data, $ev);
        self::marcar($t, $ev['uuid'], $ev['rev']);   // evita recontar o mesmo evento; local continua com seu valor até decisão
        return 'conflito';
    }

    /** Decisão explícita de administrador do outro lado: sobrescreve e fecha conflitos abertos desse registro. */
    private static function aplicarForcado(array $ev, array $s, array $data, array $local): string
    {
        $t = $ev['tabela'];
        self::gravar($t, $ev, self::traduzir($s, $data), $s, max((int) $local['rev'], $ev['rev']));
        Database::query("UPDATE sync_conflicts SET estado='resolvido', decisao='origem', resolvido_por='sync', resolvido_utc=UTC_TIMESTAMP(3), justificativa='Decidido no outro ambiente' WHERE tabela=? AND uuid=? AND estado='aberto'", [$t, $ev['uuid']]);
        return 'aceito';
    }

    private static function gravar(string $t, array $ev, array $novo, array $s, int $rev): string
    {
        $set = []; $par = [];
        foreach (array_merge($s['cols'], $s['extra']) as $c) if (array_key_exists($c, $novo)) { $set[] = "`$c`=?"; $par[] = $novo[$c]; }
        $set[] = 'rev=?'; $par[] = $rev; $par[] = $ev['uuid'];
        Database::query("UPDATE `$t` SET " . implode(',', $set) . ' WHERE uuid=?', $par);
        self::marcar($t, $ev['uuid'], $ev['rev']);
        if ($t === 'comandas') { /* total é derivado dos itens, não do evento */ }
        return 'aceito';
    }

    /** Estados terminais (fechada/cancelada) vencem 'aberta'; terminais divergentes viram conflito. Nunca reabre. */
    private static function mesclarComanda(array $ev, array $local, array $novo, array $dif): string
    {
        $s = self::spec()['comandas']; $t = 'comandas';
        $lt = $local['status'] !== 'aberta'; $rt = ($novo['status'] ?? 'aberta') !== 'aberta';
        if ($lt && $rt && $local['status'] !== $novo['status']) {
            self::conflito($t, $ev['uuid'], 'estado_divergente', 'status', $local, $ev['data'], $ev);
            self::marcar($t, $ev['uuid'], $ev['rev']);
            return 'conflito';
        }
        if (!$lt && $rt) { self::marcar($t, $ev['uuid'], $ev['rev']); return 'aceito'; }       // mesmo estado terminal
        if ($lt && !$rt) { self::marcar($t, $ev['uuid'], $ev['rev']); return 'aceito'; }       // remoto antigo: mantém fechamento local
        if (!$lt && !$rt) { self::marcar($t, $ev['uuid'], $ev['rev']); return 'aceito'; }       // ambos abertos: mantém campos locais
        // local aberta, remoto terminal: adota os campos de fechamento, mantém o resto
        $cols = array_intersect_key($novo, array_flip(['status', 'forma_pagamento', 'fechado_em', 'troco', 'caixa_id']));
        $set = []; $par = [];
        foreach ($cols as $c => $v) { $set[] = "`$c`=?"; $par[] = $v; }
        $set[] = 'rev=?'; $par[] = max((int) $local['rev'], $ev['rev']) + 1; $par[] = $ev['uuid'];
        Database::query('UPDATE comandas SET ' . implode(',', $set) . ' WHERE uuid=?', $par);
        self::marcar($t, $ev['uuid'], $ev['rev']);
        return 'aceito';
    }

    /** Se existir linha local com a mesma chave natural (nome/código de barras) e outro uuid, adota o uuid remoto. */
    private static function adotarPorChaveNatural(string $t, array $s, array $ev, array $data): array|false
    {
        $c = $s['natural']; $v = $data[$c] ?? null;
        if ($v === null || $v === '') return false;
        $l = Database::one("SELECT * FROM `$t` WHERE `$c` = ? AND uuid <> ?", [$v, $ev['uuid']]);
        if (!$l) return false;
        Database::query("UPDATE `$t` SET uuid=? WHERE id=?", [$ev['uuid'], $l['id']]);
        $l['uuid'] = $ev['uuid'];
        return $l;
    }

    public static function conflito(string $t, string $uuid, string $tipo, ?string $campos, $local, $remoto, array $ev): void
    {
        Database::query('INSERT INTO sync_conflicts (tabela, uuid, tipo, campos, local_json, remoto_json, origem, evento_id) VALUES (?,?,?,?,?,?,?,?)',
            [$t, $uuid, $tipo, $campos, json_encode($local, JSON_UNESCAPED_UNICODE), json_encode($remoto, JSON_UNESCAPED_UNICODE), $ev['origin_device'] ?? null, $ev['event_id'] ?? null]);
    }

    /** Tipos que apenas informam (não alteram dados ao resolver): podem ser resolvidos em lote com "manter". */
    const TIPOS_INFORMATIVOS = ['item_em_comanda_fechada', 'item_em_comanda_cancelada', 'pagamento_excede_total', 'exclusao_vs_edicao', 'codigo_duplicado'];

    public static function resolverLote(string $tipo, string $usuario, string $justificativa): int
    {
        if (!in_array($tipo, self::TIPOS_INFORMATIVOS, true)) throw new RuntimeException('Este tipo não pode ser resolvido em lote.');
        if (trim($justificativa) === '') throw new RuntimeException('Justificativa obrigatória.');
        $ids = array_column(Database::all("SELECT id FROM sync_conflicts WHERE estado='aberto' AND tipo=?", [$tipo]), 'id');
        foreach ($ids as $id) self::resolver((int) $id, 'local', $usuario, $justificativa);
        return count($ids);
    }

    /** Decisão explícita do administrador. 'local' mantém; 'remoto' aplica o valor remoto guardado. */
    public static function resolver(int $id, string $decisao, string $usuario, string $justificativa): void
    {
        $c = Database::one("SELECT * FROM sync_conflicts WHERE id=? AND estado='aberto'", [$id]);
        if (!$c) throw new RuntimeException('Conflito não encontrado ou já resolvido.');
        if (!in_array($decisao, ['local', 'remoto'], true)) throw new RuntimeException('Decisão inválida.');
        if (trim($justificativa) === '') throw new RuntimeException('Justificativa obrigatória.');
        $propaga = in_array($c['tipo'], ['edicao_concorrente', 'estado_divergente'], true);
        if ($decisao === 'remoto' && !$propaga) throw new RuntimeException('Este tipo de conflito só admite "manter local".');
        $pdo = self::pdo();
        if ($propaga) {
            $s = self::spec()[$c['tabela']]; $t = $c['tabela']; $pdo->beginTransaction();
            try {
                $pdo->exec('SET @sync_aplicando = 1'); // rev é definida aqui, explicitamente
                if ($decisao === 'remoto') {
                    $novo = self::traduzir($s, (array) json_decode((string) $c['remoto_json'], true));
                    $set = []; $par = [];
                    foreach ($s['cols'] as $col) if (array_key_exists($col, $novo)) { $set[] = "`$col`=?"; $par[] = $novo[$col]; }
                    $par[] = $c['uuid'];
                    Database::query("UPDATE `$t` SET " . implode(',', $set) . ', rev = rev + 1 WHERE uuid=?', $par);
                } else {
                    Database::query("UPDATE `$t` SET rev = rev + 1 WHERE uuid=?", [$c['uuid']]);
                }
                $rev = (int) Database::val("SELECT rev FROM `$t` WHERE uuid=?", [$c['uuid']]);
                Database::query("INSERT INTO sync_outbox (tabela, uuid, rev, op, forcar) VALUES (?,?,?,'U',1)", [$t, $c['uuid'], $rev]);
                self::marcar($t, $c['uuid'], $rev);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            finally { $pdo->exec('SET @sync_aplicando = 0'); }
        }
        Database::query("UPDATE sync_conflicts SET estado='resolvido', decisao=?, resolvido_por=?, resolvido_utc=UTC_TIMESTAMP(3), justificativa=? WHERE id=?",
            [$decisao, mb_substr($usuario, 0, 100), mb_substr($justificativa, 0, 500), $id]);
    }
}
