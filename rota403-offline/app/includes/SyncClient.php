<?php
/** Cliente de sincronização (roda no worker local). Gravar venda nunca depende disto. */
final class SyncClient
{
    public static function cfg(): array
    {
        foreach (['SYNC_API_URL', 'SYNC_DEVICE_ID', 'SYNC_SECRET'] as $k) if (!defined($k) || constant($k) === '') throw new RuntimeException("Configuração de sync ausente: $k");
        return [rtrim(SYNC_API_URL, '/'), SYNC_DEVICE_ID, SYNC_SECRET];
    }

    /** @return array{0:int,1:?array,2:string} [http, json, erro] */
    public static function chamar(string $metodo, string $rota, ?array $corpo = null): array
    {
        [$base, $dev, $segredo] = self::cfg();
        $url = $base . $rota; $p = parse_url($url);
        $pathQuery = $p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');
        $body = $corpo === null ? '' : json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $ts = (string) (time() + (defined('SYNC_CLOCK_SKEW') ? (int) SYNC_CLOCK_SKEW : 0)); $nonce = bin2hex(random_bytes(16));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POSTFIELDS => $metodo === 'POST' ? $body : null,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Device: ' . $dev, 'X-Timestamp: ' . $ts, 'X-Nonce: ' . $nonce,
                'X-Signature: ' . Sync::assinar($segredo, $metodo, $pathQuery, $ts, $nonce, $body)]]);
        if (defined('SYNC_CA_BUNDLE') && SYNC_CA_BUNDLE) curl_setopt($ch, CURLOPT_CAINFO, SYNC_CA_BUNDLE);
        $out = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($out === false) return [0, null, $err ?: 'sem resposta'];
        $j = json_decode((string) $out, true);
        return [$http, is_array($j) ? $j : null, $http >= 400 ? (string) ($j['erro'] ?? "HTTP $http") : ''];
    }

    /** Um ciclo: envia pendências e baixa novidades. Seguro para repetir/interromper em qualquer ponto. */
    public static function ciclo(int $lote = Sync::MAX_EVENTOS): array
    {
        $pdo = Database::pdo(); $st = ['enviados' => 0, 'recebidos' => 0, 'conflitos' => 0, 'erro' => null];
        if (!(int) Database::val("SELECT GET_LOCK('rota403_sync', 0)")) { $st['erro'] = 'ocupado'; return $st; }   // lease: um worker por vez
        try {
            try { Sync::reconhecerInformativos(); } catch (Throwable $e) { }
            self::enviar($lote, $st);
            if (!$st['erro']) self::receber($lote, $st);
            Sync::meta($st['erro'] ? 'ultimo_erro' : 'ultima_sync_utc', $st['erro'] ?: gmdate('Y-m-d H:i:s'));
            if (!$st['erro']) Sync::meta('ultimo_erro', '');
        } finally { Database::query("DO RELEASE_LOCK('rota403_sync')"); }
        return $st;
    }

    private static function enviar(int $lote, array &$st): void
    {
        $rows = Database::all("SELECT * FROM sync_outbox WHERE estado='pendente' AND via IS NULL ORDER BY seq LIMIT " . (int) $lote);
        if (!$rows) return;
        $grupos = [];                                   // colapsa várias mudanças da mesma linha
        foreach ($rows as $r) { $k = $r['tabela'] . '|' . $r['uuid']; $g = $grupos[$k] ?? ['seqs' => [], 'forcar' => 0]; $g['seqs'][] = (int) $r['seq']; $g['forcar'] |= (int) $r['forcar']; $g['ob'] = $r; $grupos[$k] = $g; }
        $ord = Sync::spec();
        uasort($grupos, fn($a, $b) => [($a['ob']['op'] === 'D') ? 1 : 0, $ord[$a['ob']['tabela']]['ord'], $a['seqs'][0]] <=> [($b['ob']['op'] === 'D') ? 1 : 0, $ord[$b['ob']['tabela']]['ord'], $b['seqs'][0]]);
        $eventos = []; $porEvento = [];
        foreach ($grupos as $g) {
            $ob = $g['ob']; $ob['forcar'] = $g['forcar'];
            try { $e = Sync::construirEvento($ob); } catch (Throwable $t) { self::marcarSeqs($g['seqs'], 'rejeitado', $t->getMessage()); continue; }
            $eventos[] = $e; $porEvento[$e['event_id']] = [$g, $e];
        }
        if (!$eventos) return;
        [$http, $j, $err] = self::chamar('POST', '/push', ['eventos' => $eventos]);
        if ($http !== 200 || !$j) { $st['erro'] = "push: $err"; return; }
        foreach ($j['resultados'] as $r) {
            if (!isset($porEvento[$r['event_id']])) continue;
            [$g, $e] = $porEvento[$r['event_id']]; $s = $r['status'];
            if (in_array($s, ['aceito', 'duplicado', 'conflito'], true)) {
                self::marcarSeqs($g['seqs'], 'enviado', null);
                if ($s !== 'conflito' && $e['tabela'] !== 'estoque_movimentos' && $e['op'] !== 'D') Database::query('INSERT INTO sync_state (tabela, uuid, synced_rev) VALUES (?,?,?) ON DUPLICATE KEY UPDATE synced_rev=GREATEST(synced_rev, VALUES(synced_rev))', [$e['tabela'], $e['uuid'], $e['rev']]);
                if ($s === 'conflito') $st['conflitos']++;
                $st['enviados']++;
            } elseif ($s === 'rejeitado') self::marcarSeqs($g['seqs'], 'rejeitado', (string) ($r['detalhe'] ?? ''));
            else { // retry: dependência ainda não chegou; desiste após 20 tentativas
                Database::query('UPDATE sync_outbox SET tentativas = tentativas + 1, erro = ? WHERE seq IN (' . implode(',', array_map('intval', $g['seqs'])) . ')', [mb_substr((string) ($r['detalhe'] ?? ''), 0, 255)]);
                Database::query("UPDATE sync_outbox SET estado='rejeitado' WHERE tentativas > 20 AND estado='pendente'");
            }
        }
    }
    private static function marcarSeqs(array $seqs, string $estado, ?string $erro): void
    {
        Database::query("UPDATE sync_outbox SET estado=?, erro=? WHERE seq IN (" . implode(',', array_map('intval', $seqs)) . ')', [$estado, $erro === null ? null : mb_substr($erro, 0, 255)]);
    }

    private static function receber(int $lote, array &$st): void
    {
        self::reaplicarAdiados($st);
        do {
            $cursor = (int) (Sync::meta('pull_cursor') ?? 0);
            [$http, $j, $err] = self::chamar('GET', '/pull?cursor=' . $cursor . '&limite=' . $lote);
            if ($http !== 200 || !$j) { $st['erro'] = "pull: $err"; return; }
            $via = (string) $j['servidor']; Sync::meta('servidor_id', $via); $ord = Sync::spec();
            $ev = $j['eventos'];   // dentro do lote: pais antes dos filhos; exclusões por último
            usort($ev, fn($a, $b) => [$a['op'] === 'D' ? 1 : 0, $ord[$a['tabela']]['ord'] ?? 99, $a['seq']] <=> [$b['op'] === 'D' ? 1 : 0, $ord[$b['tabela']]['ord'] ?? 99, $b['seq']]);
            foreach ($ev as $e) {
                $r = Sync::aplicar($e, $via);
                if ($r['status'] === 'retry') {   // pai em outro lote: guarda e segue (não trava a fila)
                    Database::query('INSERT IGNORE INTO sync_deferred (event_id, evento_json, detalhe) VALUES (?,?,?)', [$e['event_id'], json_encode($e), mb_substr((string) ($r['detalhe'] ?? ''), 0, 255)]);
                    continue;
                }
                if ($r['status'] === 'conflito') $st['conflitos']++;
                $st['recebidos']++;
            }
            // cursor só avança depois do lote inteiro (se cair no meio, o lote repete e é idempotente)
            Sync::meta('pull_cursor', (string) max($cursor, (int) $j['proximo_cursor']));
        } while (!empty($j['tem_mais']));
    }

    private static function reaplicarAdiados(array &$st): void
    {
        foreach (Database::all('SELECT * FROM sync_deferred ORDER BY criado_utc LIMIT 500') as $d) {
            $e = json_decode($d['evento_json'], true);
            $r = Sync::aplicar($e, (string) Sync::meta('servidor_id'));
            if ($r['status'] !== 'retry') { Database::query('DELETE FROM sync_deferred WHERE event_id=?', [$d['event_id']]); $st['recebidos']++; continue; }
            Database::query('UPDATE sync_deferred SET tentativas = tentativas + 1 WHERE event_id=?', [$d['event_id']]);
            if ((int) $d['tentativas'] >= 50) { Database::query('DELETE FROM sync_deferred WHERE event_id=?', [$d['event_id']]); error_log('sync: evento adiado descartado ' . $d['event_id']); }
        }
    }
}
