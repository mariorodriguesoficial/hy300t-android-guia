import os, re, subprocess, unittest, urllib.request, urllib.parse, http.cookiejar, json, threading

BASE, SOCK, DB, APPDIR = os.environ['BASE'], os.environ['SOCK'], os.environ['DBNAME'], os.environ['APPDIR']

def sql(q):
    r = subprocess.run(['mariadb', '-S', SOCK, '-uroot', DB, '-N', '-B', '-e', q], capture_output=True, text=True)
    if r.returncode: raise RuntimeError(r.stderr)
    return [l.split('\t') for l in r.stdout.strip().splitlines()] if r.stdout.strip() else []
def val(q): return sql(q)[0][0]

class Cliente:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
    def req(self, path, data=None, json_body=None, headers=None):
        h = dict(headers or {}); body = None
        if json_body is not None: body = json.dumps(json_body).encode(); h['Content-Type'] = 'application/json'
        elif data is not None: body = urllib.parse.urlencode(data, doseq=True).encode()
        try: r = self.op.open(urllib.request.Request(BASE + path, body, h)); return r.status, r.read().decode()
        except urllib.error.HTTPError as e: return e.code, e.read().decode()
    def csrf(self, path='/login'):
        s, b = self.req(path)
        m = re.search(r'name="csrf" (?:value|content)="([0-9a-f]+)"', b); return m.group(1)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

def novo_admin_logado():
    c = Cliente(); t = c.csrf('/login')
    if val('select count(*) from usuarios') == '0':
        c.req('/login', {'csrf': t, 'nome': 'Admin', 'login': 'admin', 'senha': 'Teste1234'})
    else:
        c.req('/login', {'csrf': t, 'login': 'admin', 'senha': 'Teste1234'})
    return c

def lancar(c, cid, pid, q, chave=None):
    t = c.csrf('/caixa')
    body = {'comanda_id': cid, 'itens': [{'produto_id': pid, 'quantidade': q}]}
    if chave: body['chave'] = chave
    return c.req('/api/lancar', json_body=body, headers={'X-CSRF': t})

def mk(c, estoque=50):
    t = c.csrf('/caixa')
    if val("select count(*) from caixas where status='aberto'") == '0': c.req('/caixa', {'csrf': t, 'acao': 'abrir', 'saldo': '0'})
    c.req('/caixa', {'csrf': t, 'acao': 'criar_comanda', 'tipo': 'mesa', 'identificacao': 'T'})
    cid = int(val('select max(id) from comandas'))
    sql(f"insert into produtos(nome,preco,estoque,destino) values('P{cid}',10,{estoque},'cozinha')")
    return cid, int(val('select max(id) from produtos'))

class TestMigracao(unittest.TestCase):
    def test_idempotente_e_uuid_unico(self):
        out = subprocess.run(['php', 'bin/migrate.php'], cwd=APPDIR, capture_output=True, text=True).stdout
        self.assertNotIn('Aplicada', out)           # segunda execução não reaplica
        self.assertEqual(val('select max(versao) from schema_migrations'), '2')
        for t in ['caixas','categorias','comandas','comanda_itens','comanda_pagamentos','pedidos','produtos','formas_pagamento']:
            self.assertEqual(val(f'select count(*)-count(distinct uuid) from {t}'), '0', t)
            self.assertEqual(val(f'select count(*) from {t} where uuid is null'), '0', t)
    def test_tamper_checksum(self):
        sql("update schema_migrations set checksum='x' where versao=2")
        out = subprocess.run(['php', 'bin/migrate.php'], cwd=APPDIR, capture_output=True, text=True)
        sql("update schema_migrations set checksum=(select checksum from (select 1) z) where 0")  # no-op
        self.assertIn('alterada', out.stdout + out.stderr)
        # restaura: recalcula via PHP
        sql("delete from schema_migrations where versao=2")
        subprocess.run(['php', 'bin/migrate.php'], cwd=APPDIR, capture_output=True)
        self.assertEqual(val('select max(versao) from schema_migrations'), '2')

class TestTriggers(unittest.TestCase):
    def test_rev_estoque_ledger_tombstone(self):
        sql("insert into produtos(nome,preco,estoque) values('TR',5,10)")
        pid = val("select id from produtos where nome='TR'"); u = val(f'select uuid from produtos where id={pid}')
        self.assertEqual(val(f"select delta from estoque_movimentos where produto_uuid='{u}' and motivo='inicial'"), '10.000')
        sql(f'update produtos set estoque=estoque-3 where id={pid}')
        self.assertEqual(val(f'select rev from produtos where id={pid}'), '1')     # estoque não muda rev do cadastro
        self.assertEqual(val(f"select sum(delta) from estoque_movimentos where produto_uuid='{u}'"), '7.000')
        sql(f'update produtos set preco=6 where id={pid}')
        self.assertEqual(val(f'select rev from produtos where id={pid}'), '2')
        sql('set @sync_aplicando=1; update produtos set preco=7, rev=9 where id=%s; ' % pid)  # outra sessão: variável não vaza
        sql(f'delete from produtos where id={pid}')
        self.assertEqual(val(f"select count(*) from sync_tombstones where uuid='{u}'"), '1')

class TestLancar(unittest.TestCase):
    @classmethod
    def setUpClass(cls): cls.c = novo_admin_logado()
    def test_mesma_chave_nao_duplica(self):
        cid, pid = mk(self.c)
        for _ in range(3): s, b = lancar(self.c, cid, pid, 2, 'chave-teste-0001'); self.assertEqual(s, 200, b)
        self.assertEqual(val(f'select count(*) from comanda_itens where comanda_id={cid}'), '1')
        self.assertEqual(val(f'select estoque from produtos where id={pid}'), '48.000')
        self.assertEqual(val(f'select total from comandas where id={cid}'), '20.00')
        self.assertEqual(val(f'select count(*) from pedidos where comanda_id={cid}'), '1')
    def test_chaves_diferentes_somam(self):
        cid, pid = mk(self.c)
        lancar(self.c, cid, pid, 1, 'chave-teste-A001'); lancar(self.c, cid, pid, 1, 'chave-teste-B001')
        self.assertEqual(val(f'select count(*) from comanda_itens where comanda_id={cid}'), '2')
    def test_concorrente_mesma_chave(self):
        cid, pid = mk(self.c); res = []
        def f(): res.append(lancar(self.c if False else novo_admin_logado(), cid, pid, 1, 'chave-concorr-01')[0])
        th = [threading.Thread(target=f) for _ in range(6)]; [x.start() for x in th]; [x.join() for x in th]
        self.assertTrue(all(r == 200 for r in res), res)
        self.assertEqual(val(f'select count(*) from comanda_itens where comanda_id={cid}'), '1')
        self.assertEqual(val(f'select estoque from produtos where id={pid}'), '49.000')
    def test_falha_nao_consome_chave(self):
        cid, pid = mk(self.c)
        s, _ = lancar(self.c, 999999, pid, 1, 'chave-falha-0001'); self.assertEqual(s, 400)
        s, _ = lancar(self.c, cid, pid, 1, 'chave-falha-0001'); self.assertEqual(s, 200)
        self.assertEqual(val(f'select count(*) from comanda_itens where comanda_id={cid}'), '1')
    def test_sem_chave_continua_funcionando(self):
        cid, pid = mk(self.c); lancar(self.c, cid, pid, 1); self.assertEqual(val(f'select count(*) from comanda_itens where comanda_id={cid}'), '1')

class TestCancelar(unittest.TestCase):
    @classmethod
    def setUpClass(cls): cls.c = novo_admin_logado()
    def cancelar(self, cid):
        t = self.c.csrf('/caixa'); return self.c.req('/caixa', {'csrf': t, 'acao': 'cancelar', 'comanda_id': cid})
    def test_cancelar_aberta_devolve_uma_vez(self):
        cid, pid = mk(self.c); lancar(self.c, cid, pid, 2)
        self.assertEqual(val(f'select estoque from produtos where id={pid}'), '48.000')
        self.cancelar(cid); self.cancelar(cid)          # segunda chamada não devolve de novo
        self.assertEqual(val(f'select estoque from produtos where id={pid}'), '50.000')
        self.assertEqual(val(f'select status from comandas where id={cid}'), 'cancelada')
    def test_cancelar_fechada_sem_linha_de_pagamento_nao_devolve(self):
        cid, pid = mk(self.c); lancar(self.c, cid, pid, 2)
        sql(f"update comandas set status='fechada', forma_pagamento='PIX' where id={cid}")   # legado: sem comanda_pagamentos
        self.cancelar(cid)
        self.assertEqual(val(f'select estoque from produtos where id={pid}'), '48.000')
        self.assertEqual(val(f'select status from comandas where id={cid}'), 'fechada')

if __name__ == '__main__': unittest.main()
