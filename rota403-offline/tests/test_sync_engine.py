import os, re, json, time, hmac, hashlib, secrets, subprocess, unittest, urllib.request, urllib.parse, http.cookiejar

T, SOCK, PW = os.environ['T'], os.environ['SOCK'], os.environ['PW']
ON, LO = 'http://127.0.0.1:8101', 'http://127.0.0.1:8102'
DIR = {'online': f'{T}/online', 'local': f'{T}/local'}

def sql(db, q):
    r = subprocess.run(['mariadb', '-S', SOCK, '-uroot', f'rota403_{db}', '-N', '-B', '-e', q], capture_output=True, text=True)
    if r.returncode: raise RuntimeError(r.stderr + q)
    return [l.split('\t') for l in r.stdout.strip().splitlines()] if r.stdout.strip() else []
def val(db, q): return sql(db, q)[0][0]

def php(db, *args):
    return subprocess.run(['php', *args], cwd=DIR[db], capture_output=True, text=True)

def write_local_cfg(extra):
    s = f"<?php return ['DB_HOST'=>'127.0.0.1','DB_PORT'=>'3398','DB_USER'=>'rota403','DB_PASS'=>'{PW}','DB_NAME'=>'rota403_local','BASE_URL'=>'','SYNC_REQUIRE_HTTPS'=>false, **EXTRA**];"
    ex = ','.join(f"'{k}'=>{json.dumps(v) if not isinstance(v,bool) else str(v).lower()}" for k, v in extra.items())
    open(f'{DIR["local"]}/config/config.local.php', 'w').write(s.replace('**EXTRA**', ex))

DEV = {}
def cfg_local(**over):
    c = {'SYNC_ENABLED': True, 'SYNC_API_URL': ON + '/api/sync/v1', 'SYNC_DEVICE_ID': DEV['id'], 'SYNC_SECRET': DEV['secret']}; c.update(over)
    write_local_cfg(c)

def worker(*a):
    r = php('local', 'bin/sync-worker.php', '--once', *a)
    try: return json.loads(r.stdout.strip().splitlines()[-1])
    except Exception: return {'erro': (r.stderr or r.stdout).strip()}
def diag():
    return {'pend': sql('local', "select tabela,estado,erro,count(*) from sync_outbox where via is null group by tabela,estado,erro"),
            'phplog': (open(f'{DIR["online"]}/storage/php-error.log').read()[-700:] if os.path.exists(f'{DIR["online"]}/storage/php-error.log') else ''),
            'log': sql('online', "select acao,http,eventos,detalhe from sync_log order by id desc limit 4")}
def sync_ate_estabilizar(n=6):
    for _ in range(n):
        r = worker()
        assert not r.get('erro'), (r, diag())
        if r['enviados'] == 0 and r['recebidos'] == 0: return
    raise AssertionError('não estabilizou')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
class Cli:
    def __init__(self, base):
        self.base = base; self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
    def req(self, path, data=None, js=None, h=None):
        h = dict(h or {}); body = None
        if js is not None: body = json.dumps(js).encode(); h['Content-Type'] = 'application/json'
        elif data is not None: body = urllib.parse.urlencode(data, doseq=True).encode()
        try: r = self.op.open(urllib.request.Request(self.base + path, body, h)); return r.status, r.read().decode()
        except urllib.error.HTTPError as e: return e.code, e.read().decode()
    def csrf(self, p='/caixa'):
        return re.search(r'name="csrf" (?:value|content)="([0-9a-f]+)"', self.req(p)[1]).group(1)
    def login(self, db):
        t = self.csrf('/login')
        if val(db, 'select count(*) from usuarios') == '0': self.req('/login', {'csrf': t, 'nome': 'Admin', 'login': 'admin', 'senha': 'Teste1234'})
        else: self.req('/login', {'csrf': t, 'login': 'admin', 'senha': 'Teste1234'})
        return self
    def post(self, path, **d): return self.req(path, {'csrf': self.csrf(), **d})
    def lancar(self, cid, pid, q=1):
        return self.req('/api/lancar', js={'comanda_id': cid, 'itens': [{'produto_id': pid, 'quantidade': q}], 'chave': secrets.token_hex(12)}, h={'X-CSRF': self.csrf()})

C = {}
def signed(method, path, body='', dev=None, secret=None, ts=None, nonce=None, sign_body=None):
    dev = dev or DEV['id']; secret = secret or DEV['secret']; ts = str(ts or int(time.time())); nonce = nonce or secrets.token_hex(16)
    base = f"{method}\n{path}\n{ts}\n{nonce}\n{hashlib.sha256((sign_body if sign_body is not None else body).encode()).hexdigest()}"
    sig = hmac.new(secret.encode(), base.encode(), hashlib.sha256).hexdigest()
    h = {'X-Device': dev, 'X-Timestamp': ts, 'X-Nonce': nonce, 'X-Signature': sig, 'Content-Type': 'application/json'}
    rq = urllib.request.Request(ON + path, body.encode() if method == 'POST' else None, h, method=method)
    try: r = urllib.request.urlopen(rq); return r.status, json.loads(r.read())
    except urllib.error.HTTPError as e: return e.code, json.loads(e.read() or b'{}')

def novo_produto(nome, preco=10, estoque=100):
    sql('online', f"insert into produtos(nome,preco,estoque,destino,codigo_barras) values('{nome}',{preco},{estoque},'cozinha','{nome}')")
    return nome
def pid(db, nome): return int(val(db, f"select id from produtos where nome='{nome}'"))
def caixa_aberto(c, db):
    if val(db, "select count(*) from caixas where status='aberto'") == '0': c.post('/caixa', acao='abrir', saldo='0')
def nova_comanda(c, db, nome):
    caixa_aberto(c, db); c.post('/caixa', acao='criar_comanda', tipo='mesa', identificacao=nome)
    return int(val(db, f"select id from comandas where identificacao='{nome}'"))

def setUpModule():
    if os.environ.get('ONLINE_V23'):   # garante que o "online" é mesmo o v23 original (sem as correções do app local)
        assert 'idempotencia' not in open(f'{DIR["online"]}/includes/helpers.php').read(), 'online não é o v23 puro'
    C['on'] = Cli(ON).login('online'); C['lo'] = Cli(LO).login('local')
    out = php('online', 'bin/sync-admin.php', 'device-add', 'Notebook Caixa').stdout
    DEV['id'] = re.search(r'device_id: (\S+)', out).group(1); DEV['secret'] = re.search(r'UMA vez[^:]*: (\S+)', out).group(1)
    sql('online', "insert into categorias(nome,ordem) values('Bebidas',1)")
    for p in ['Cola', 'Espeto']: novo_produto(p)
    php('online', 'bin/sync-admin.php', 'semear')
    assert php('local', 'bin/sync-admin.php', 'id-inicial', '500000').returncode == 0
    cfg_local(); sync_ate_estabilizar()

class Base(unittest.TestCase):
    def setUp(self): cfg_local()

class T01_Basico(Base):
    def test_catalogo_chega_no_local_e_estoque_inicial(self):
        self.assertEqual(val('local', 'select count(*) from produtos'), '2')
        self.assertEqual(val('local', "select estoque from produtos where nome='Cola'"), '100.000')
        self.assertEqual(val('local', "select uuid from produtos where nome='Cola'"), val('online', "select uuid from produtos where nome='Cola'"))
    def test_health_publico_sem_dados(self):
        j = json.loads(urllib.request.urlopen(ON + '/api/sync/v1/health').read()); self.assertEqual(set(j), {'ok', 'api', 'esquema'})

class T02_Offline(Base):
    def test_venda_local_sem_internet_e_sync_depois(self):
        cfg_local(SYNC_API_URL='http://127.0.0.1:9/api/sync/v1')      # "sem internet"
        cid = nova_comanda(C['lo'], 'local', 'OFF1'); s, _ = C['lo'].lancar(cid, pid('local', 'Cola'), 2); self.assertEqual(s, 200)   # PDV segue funcionando
        r = worker(); self.assertTrue(r.get('erro'), r)
        self.assertGreater(int(val('local', "select count(*) from sync_outbox where estado='pendente' and via is null")), 0)
        self.assertEqual(val('online', f"select count(*) from comandas where identificacao='OFF1'"), '0')
        cfg_local(); sync_ate_estabilizar()
        self.assertEqual(val('online', "select count(*) from comandas where identificacao='OFF1'"), '1')
        self.assertEqual(val('online', "select count(*) from comanda_itens i join comandas c on c.id=i.comanda_id where c.identificacao='OFF1'"), '1')
        self.assertGreaterEqual(int(cid), 500000)

class T03_Bidirecional(Base):
    def test_ambos_criam_enquanto_desconectados(self):
        a = nova_comanda(C['on'], 'online', 'ON-A'); b = nova_comanda(C['lo'], 'local', 'LO-B')
        C['on'].lancar(a, pid('online', 'Espeto'), 1); C['lo'].lancar(b, pid('local', 'Espeto'), 1)
        sync_ate_estabilizar()
        print('DIAG', diag(), sql('local', "select seq,tabela,estado,via,erro from sync_outbox order by seq desc limit 6"))
        for db in ('online', 'local'):
            self.assertEqual(val(db, "select count(*) from comandas where identificacao in ('ON-A','LO-B')"), '2', db)
            self.assertEqual(val(db, "select count(*) from comanda_itens i join comandas c on c.id=i.comanda_id where c.identificacao in ('ON-A','LO-B')"), '2', db)
        self.assertEqual(val('online', "select group_concat(uuid order by uuid) from comandas where identificacao in ('ON-A','LO-B')"), val('local', "select group_concat(uuid order by uuid) from comandas where identificacao in ('ON-A','LO-B')"))

class T04_Idempotencia(Base):
    def test_replay_de_evento_e_de_requisicao(self):
        nova_comanda(C['lo'], 'local', 'IDEM'); sync_ate_estabilizar()
        n0 = val('online', 'select count(*) from comandas')
        ob = sql('local', "select tabela, uuid, event_id from sync_outbox where tabela='comandas' order by seq desc limit 1")[0]
        import subprocess as sp
        code = f"require 'config/config.php'; $o=Database::one('select * from sync_outbox where event_id=?',['{ob[2]}']); echo json_encode(Sync::construirEvento($o));"
        ev = json.loads(php('local', '-r', code).stdout)
        body = json.dumps({'eventos': [ev]})
        s, j = signed('POST', '/api/sync/v1/push', body); self.assertEqual(j['resultados'][0]['status'], 'duplicado')
        self.assertEqual(val('online', 'select count(*) from comandas'), n0)
        nonce = secrets.token_hex(16)
        s1, _ = signed('POST', '/api/sync/v1/push', body, nonce=nonce); s2, j2 = signed('POST', '/api/sync/v1/push', body, nonce=nonce)
        self.assertEqual((s1, s2), (200, 401)); self.assertIn('replay', j2['erro'])
    def test_timeout_apos_servidor_gravar_antes_do_ack(self):
        cid = nova_comanda(C['lo'], 'local', 'ACK1'); C['lo'].lancar(cid, pid('local', 'Cola'), 1)
        # cliente "envia" mas morre antes de marcar enviado:
        code = ("require 'config/config.php'; require_once 'includes/SyncClient.php'; $rows=Database::all(\"select * from sync_outbox where estado='pendente' and via is null order by seq\");"
                "$ev=[]; foreach($rows as $r){$ev[$r['tabela'].$r['uuid']]=Sync::construirEvento($r);} usort($ev, fn($a,$b)=>Sync::spec()[$a['tabela']]['ord']<=>Sync::spec()[$b['tabela']]['ord']);"
                "[$h,$j,$e]=SyncClient::chamar('POST','/push',['eventos'=>array_values($ev)]); echo $h;")
        self.assertEqual(php('local', '-r', code).stdout.strip(), '200')
        self.assertGreater(int(val('local', "select count(*) from sync_outbox where estado='pendente' and via is null")), 0)   # nada foi marcado
        sync_ate_estabilizar()
        self.assertEqual(val('online', "select count(*) from comanda_itens i join comandas c on c.id=i.comanda_id where c.identificacao='ACK1'"), '1')
        self.assertEqual(val('local', "select count(*) from sync_outbox where estado='pendente' and via is null"), '0')

    def test_movimento_de_estoque_reenviado_com_outro_event_id_nao_reaplica(self):
        m = sql('online', "select uuid, produto_uuid, delta, motivo, criado_em_utc from estoque_movimentos order by id desc limit 1")[0]
        est = val('online', f"select estoque from produtos where uuid='{m[1]}'")
        ev = {'event_id': secrets.token_hex(4) + '-0000-4000-8000-' + secrets.token_hex(6), 'tabela': 'estoque_movimentos', 'op': 'I', 'rev': 1, 'uuid': m[0], 'origin_device': DEV['id'],
              'data': {'produto_uuid': m[1], 'delta': m[2], 'motivo': m[3], 'criado_em_utc': m[4]}}
        s, j = signed('POST', '/api/sync/v1/push', json.dumps({'eventos': [ev]})); self.assertEqual(j['resultados'][0]['status'], 'duplicado')
        self.assertEqual(val('online', f"select estoque from produtos where uuid='{m[1]}'"), est)

class T05_Fila(Base):
    def test_reinicio_no_meio_da_fila_lote_pequeno(self):
        for i in range(3):
            cid = nova_comanda(C['lo'], 'local', f'FILA{i}'); C['lo'].lancar(cid, pid('local', 'Cola'), 1)
        for _ in range(40):                       # "reinicia" o worker a cada 2 eventos
            r = worker('--lote=2')
            if r.get('enviados') == 0 and r.get('recebidos') == 0 and not r.get('erro'): break
        self.assertEqual(val('online', "select count(*) from comandas where identificacao like 'FILA%'"), '3')
        self.assertEqual(val('online', "select count(*) from comanda_itens i join comandas c on c.id=i.comanda_id where c.identificacao like 'FILA%'"), '3')

class T06_Concorrencia(Base):
    def test_comanda_com_itens_pagamentos_e_estoque_nos_dois_lados(self):
        cid = nova_comanda(C['on'], 'online', 'CONC'); C['on'].lancar(cid, pid('online', 'Espeto'), 1); sync_ate_estabilizar()
        e0 = float(val('online', "select estoque from produtos where nome='Cola'")); self.assertEqual(float(val('local', "select estoque from produtos where nome='Cola'")), e0)
        # ambos mexem na mesma comanda sem se verem
        C['on'].lancar(cid, pid('online', 'Cola'), 2); C['lo'].lancar(cid, pid('local', 'Cola'), 3)
        caixa_aberto(C['on'], 'online'); caixa_aberto(C['lo'], 'local')
        C['on'].post('/caixa', acao='pagamento_parcial', comanda_id=cid, forma='PIX', valor='3,00')
        C['lo'].post('/caixa', acao='pagamento_parcial', comanda_id=cid, forma='Dinheiro', valor='4,00')
        sync_ate_estabilizar()
        for db in ('online', 'local'):
            self.assertEqual(val(db, f"select count(*) from comanda_itens where comanda_id={cid}"), '3', db)
            self.assertEqual(val(db, f"select total from comandas where id={cid}"), '60.00', db)          # 10 + 20 + 30
            self.assertEqual(val(db, f"select count(*) from comanda_pagamentos where comanda_id={cid}"), '2', db)
            self.assertEqual(float(val(db, "select estoque from produtos where nome='Cola'")), e0 - 5, db)  # vendas dos dois lados somadas
            self.assertEqual(val(db, "select count(*) from sync_conflicts where tipo='pagamento_excede_total'"), '0', db)
        self.assertEqual(val('online', "select count(*) from estoque_movimentos"), val('local', "select count(*) from estoque_movimentos"))

class T07_DoisDispositivos(Base):
    def test_segundo_dispositivo_e_relay(self):
        out = php('online', 'bin/sync-admin.php', 'device-add', 'Tablet B').stdout
        b = {'id': re.search(r'device_id: (\S+)', out).group(1), 'secret': re.search(r'UMA vez[^:]*: (\S+)', out).group(1)}
        cuuid = val('online', "select uuid from comandas where identificacao='CONC'")
        ev = {'event_id': secrets.token_hex(16)[:8] + '-0000-4000-8000-' + secrets.token_hex(6), 'tabela': 'comanda_itens', 'op': 'U', 'rev': 1, 'origin_device': b['id'],
              'uuid': secrets.token_hex(16)[:8] + '-1111-4000-8000-' + secrets.token_hex(6),
              'data': {'comanda_id_uuid': cuuid, 'produto_id_uuid': val('online', "select uuid from produtos where nome='Espeto'"), 'nome': 'Espeto', 'quantidade': '1.000', 'preco_unitario': '10.00', 'subtotal': '10.00', 'observacao': None, 'garcom': 'B', 'adicionado_em': '2026-11-22 12:00:00'}}
        C['on'].lancar(int(val('online', "select id from comandas where identificacao='CONC'")), pid('online', 'Cola'), 1)   # mudança simultânea no hub
        s, j = signed('POST', '/api/sync/v1/push', json.dumps({'eventos': [ev]}), dev=b['id'], secret=b['secret']); self.assertEqual(j['resultados'][0]['status'], 'aceito', j)
        sync_ate_estabilizar()
        for db in ('online', 'local'):
            self.assertEqual(val(db, "select count(*) from comanda_itens i join comandas c on c.id=i.comanda_id where c.identificacao='CONC'"), '5', db)   # nada se perdeu
        # B não recebe de volta o que enviou (via), mas recebe o do local
        s, j = signed('GET', '/api/sync/v1/pull?cursor=0&limite=200', dev=b['id'], secret=b['secret']); self.assertEqual(s, 200)
        self.assertNotIn(ev['uuid'], [e['uuid'] for e in j['eventos']]); self.assertGreater(len(j['eventos']), 0)

class T08_Conflitos(Base):
    def test_preco_alterado_nos_dois_lados_ultima_alteracao_vence_sozinha(self):
        n = novo_produto('Preco1'); sync_ate_estabilizar()
        sql('online', "update produtos set preco=11 where nome='Preco1'"); time.sleep(0.05); sql('local', "update produtos set preco=12 where nome='Preco1'")
        sync_ate_estabilizar()
        for db in ('online', 'local'):
            self.assertEqual(val(db, "select preco from produtos where nome='Preco1'"), '12.00', db)       # a edição mais recente venceu nos DOIS lados
            self.assertEqual(val(db, "select count(*) from sync_conflicts where estado='aberto'"), '0', db) # nada fica esperando o usuário
        self.assertGreaterEqual(int(val('online', "select count(*) from sync_conflicts where tipo='edicao_concorrente' and decisao='auto'")), 1)   # mas fica registrado
    def test_edicao_inversa_o_outro_lado_mais_recente_vence(self):
        novo_produto('Preco2'); sync_ate_estabilizar()
        sql('local', "update produtos set preco=21 where nome='Preco2'"); time.sleep(0.05); sql('online', "update produtos set preco=22 where nome='Preco2'")
        sync_ate_estabilizar()
        for db in ('online', 'local'): self.assertEqual(val(db, "select preco from produtos where nome='Preco2'"), '22.00', db)
    def test_cancelar_e_fechar_simultaneos(self):
        cid = nova_comanda(C['on'], 'online', 'CXL'); C['on'].lancar(cid, pid('online', 'Espeto'), 1); sync_ate_estabilizar()
        caixa_aberto(C['lo'], 'local')
        C['on'].post('/caixa', acao='cancelar', comanda_id=cid)
        C['lo'].post('/caixa', acao='receber', comanda_id=cid, **{'pg_forma[]': 'PIX', 'pg_valor[]': '10,00'})
        self.assertEqual(val('online', f"select status from comandas where id={cid}"), 'cancelada'); self.assertEqual(val('local', f"select status from comandas where id={cid}"), 'fechada')
        sync_ate_estabilizar()
        for db in ('online', 'local'):
            self.assertEqual(val(db, f"select status from comandas where id={cid}"), 'fechada', db)       # fechada (paga) prevalece sobre cancelada, sem reabrir
            self.assertEqual(val(db, "select count(*) from sync_conflicts where estado='aberto'"), '0', db)
    def test_fechamento_remoto_nao_e_desfeito_por_estado_antigo(self):
        cid = nova_comanda(C['on'], 'online', 'FECH'); sync_ate_estabilizar(); caixa_aberto(C['on'], 'online')
        C['lo'].post('/caixa', acao='abrir', saldo='0') if val('local', "select count(*) from caixas where status='aberto'") == '0' else None
        C['on'].lancar(cid, pid('online', 'Cola'), 1)
        C['on'].post('/caixa', acao='receber', comanda_id=cid, **{'pg_forma[]': 'PIX', 'pg_valor[]': '10,00'})
        time.sleep(3)                                            # item realmente POSTERIOR ao fechamento
        C['lo'].lancar(cid, pid('local', 'Espeto'), 1)          # local ainda vê aberta e lança item
        sync_ate_estabilizar()
        for db in ('online', 'local'): self.assertEqual(val(db, f"select status from comandas where id={cid}"), 'fechada', db)
        self.assertGreaterEqual(int(val('online', "select count(*) from sync_conflicts where tipo like 'item_em_comanda_%'")), 1)   # item tardio sinalizado, não perdido
        self.assertEqual(val('online', f"select count(*) from comanda_itens where comanda_id={cid}"), '2')

class T09_Seguranca(Base):
    def test_token_revogado(self):
        out = php('online', 'bin/sync-admin.php', 'device-add', 'Revog').stdout
        d = re.search(r'device_id: (\S+)', out).group(1); sec = re.search(r'UMA vez[^:]*: (\S+)', out).group(1)
        self.assertEqual(signed('GET', '/api/sync/v1/pull?cursor=0', dev=d, secret=sec)[0], 200)
        php('online', 'bin/sync-admin.php', 'device-revoke', d)
        self.assertEqual(signed('GET', '/api/sync/v1/pull?cursor=0', dev=d, secret=sec)[0], 401)
    def test_timestamp_expirado_e_relogio_divergente(self):
        self.assertEqual(signed('GET', '/api/sync/v1/pull?cursor=0', ts=int(time.time()) - 4000)[0], 401)
        cfg_local(SYNC_CLOCK_SKEW=4000); r = worker(); self.assertIn('Relógio', r.get('erro', ''))      # erro claro
        cid = nova_comanda(C['lo'], 'local', 'SKEW'); self.assertGreater(cid, 0)                         # PDV não é afetado
        cfg_local(SYNC_CLOCK_SKEW=120); sync_ate_estabilizar()                                           # diferença pequena é tolerada
        self.assertEqual(val('online', "select count(*) from comandas where identificacao='SKEW'"), '1')
    def test_payload_adulterado_assinatura_errada_e_dispositivo_falso(self):
        body = json.dumps({'eventos': []})
        self.assertEqual(signed('POST', '/api/sync/v1/push', body, sign_body=json.dumps({'eventos': [{}]}))[0], 401)   # corpo != assinado
        self.assertEqual(signed('POST', '/api/sync/v1/push', body, secret='00' * 32)[0], 401)
        self.assertEqual(signed('POST', '/api/sync/v1/push', body, dev='11111111-1111-4111-8111-111111111111')[0], 401)
    def test_evento_invalido_rejeitado_e_tabela_proibida(self):
        for ev in ({'event_id': 'x'}, {'event_id': '22222222-2222-4222-8222-222222222222', 'tabela': 'usuarios', 'uuid': '33333333-3333-4333-8333-333333333333', 'op': 'U', 'rev': 1, 'data': {'senha': 'x'}}):
            s, j = signed('POST', '/api/sync/v1/push', json.dumps({'eventos': [ev]})); self.assertEqual(j['resultados'][0]['status'], 'rejeitado')
        self.assertEqual(val('online', "select count(*) from usuarios where senha='x'"), '0')
    def test_http_exigido_por_padrao(self):
        # (o servidor de teste roda com SYNC_REQUIRE_HTTPS=false; o padrão no código é true)
        self.assertIn("? SYNC_REQUIRE_HTTPS : true", open(f'{DIR["online"]}/includes/SyncServer.php').read())
        if not os.environ.get('ONLINE_V23'): self.assertIn("'SYNC_REQUIRE_HTTPS' => true", open(f'{DIR["online"]}/config/config.php').read())

class T09b_ScriptsNaoViaWeb(Base):
    def test_bin_e_setup_nao_executam_pela_web(self):
        for p in ('/bin/migrate.php', '/bin/sync-admin.php'):
            r = urllib.request.Request(ON + p)
            try: s = urllib.request.urlopen(r).status
            except urllib.error.HTTPError as e: s = e.code
            self.assertEqual(s, 403, p)
        self.assertEqual(val('online', "select count(*) from schema_migrations"), val('online', "select count(*) from schema_migrations"))

class T12_HistoricoFechado(Base):
    def test_venda_fechada_importada_nao_gera_conflito(self):
        for pad in range(3):
            cid = nova_comanda(C['on'], 'online', f'HIST{pad}'); C['on'].lancar(cid, pid('online', 'Espeto'), 2); C['on'].lancar(cid, pid('online', 'Cola'), 1)
            caixa_aberto(C['on'], 'online')
            C['on'].post('/caixa', acao='receber', comanda_id=cid, **{'pg_forma[]': 'PIX', 'pg_valor[]': '30,00'})
        # venda direta (itens e fechamento no MESMO segundo) também não pode ser sinalizada
        C['on'].post('/caixa', acao='venda_direta_finalizar', itens=json.dumps([{'id': pid('online', 'Cola'), 'q': 1}]), **{'pg_forma[]': 'PIX', 'pg_valor[]': '10,00'})
        antes = {db: int(val(db, "select count(*) from sync_conflicts")) for db in ('online', 'local')}
        sync_ate_estabilizar()
        for db in ('online', 'local'):
            n = int(val(db, "select count(*) from sync_conflicts where tipo like 'item_em_comanda_%' or tipo='pagamento_excede_total'")) - 0
            self.assertEqual(int(val(db, "select count(*) from sync_conflicts")), antes[db], f'{db}: conflitos novos na importação: ' + str(sql(db, "select tipo,count(*) from sync_conflicts group by tipo")))
        self.assertEqual(val('local', "select count(*) from comandas where identificacao like 'HIST%' and status='fechada'"), '3')

class T10_Restauracao(Base):
    def test_voltar_cursor_nao_duplica(self):
        sync_ate_estabilizar()
        antes = {t: val('local', f'select count(*) from {t}') for t in ('comandas', 'comanda_itens', 'comanda_pagamentos', 'estoque_movimentos', 'produtos')}
        est = val('local', "select sum(estoque) from produtos")
        sql('local', "update sync_meta set v='0' where k='pull_cursor'")   # como se restaurasse um backup antigo
        sync_ate_estabilizar()
        for t, n in antes.items(): self.assertEqual(val('local', f'select count(*) from {t}'), n, t)
        self.assertEqual(val('local', "select sum(estoque) from produtos"), est)             # ledger não reaplicou deltas
        self.assertEqual(val('local', "select count(*) from comandas where codigo is not null group by codigo having count(*)>1 limit 1") if sql('local', "select codigo from comandas group by codigo having count(*)>1") else '0', '0')

class T11_PainelConflitos(Base):
    def test_informativos_abertos_sao_resolvidos_sozinhos_e_painel_abre(self):
        sql('local', "insert into sync_conflicts (tabela, uuid, tipo, local_json, remoto_json) values ('comanda_itens', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'item_em_comanda_fechada', '{}', '{}')")
        self.assertEqual(val('local', "select count(*) from sync_conflicts where estado='aberto'"), '1')
        worker()
        self.assertEqual(val('local', "select count(*) from sync_conflicts where estado='aberto'"), '0')
        self.assertEqual(val('local', "select decisao from sync_conflicts where uuid='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'"), 'auto')
        st, html = C['lo'].req('/sync_conflitos'); self.assertEqual(st, 200)

if __name__ == '__main__': unittest.main()
