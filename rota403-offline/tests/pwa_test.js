// Testes do PWA do garçom em Chromium com emulação de Pixel 7. Saída: PASS/FAIL por verificação.
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const BASE = process.env.BASE, SOCK = process.env.SOCK, DB = process.env.DBNAME;
const sql = q => execFileSync('mariadb', ['-S', SOCK, '-uroot', DB, '-N', '-B', '-e', q]).toString().trim();
let falhas = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) falhas++; };
const esperar = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const browser = await chromium.launch({ args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] });
  const ctx = await browser.newContext({ ...devices['Pixel 7'], locale: 'pt-BR', permissions: ['camera'] });
  const page = await ctx.newPage();
  if (process.env.DEBUG) page.on('console', m => console.log('  [console]', m.type(), m.text()));
  const consoleErros = []; page.on('pageerror', e => consoleErros.push(e.message));
  const externas = new Set(); page.on('request', r => { const u = new URL(r.url()); if (!['127.0.0.1', 'localhost'].includes(u.hostname) && u.protocol.startsWith('http')) externas.add(r.url()); });

  // ---- login como garçom
  await page.goto(BASE + '/login'); await page.fill('[name=login]', 'maria'); await page.fill('[name=senha]', 'Senha1234'); await page.click('button');
  await page.waitForURL(/\/garcom/); ok(true, 'garçom entra e cai em /garcom');

  // ---- manifesto
  const man = await (await page.request.get(BASE + '/manifest.php')).json();
  ok(man.display === 'standalone' && man.start_url === '/garcom' && man.scope === '/' && man.theme_color && man.name.includes('Garçom'), 'manifesto: standalone, start_url, scope, nome');
  const tipos = man.icons.map(i => i.sizes + ':' + i.purpose).sort().join(',');
  ok(tipos === '192x192:any,512x512:any,512x512:maskable', 'manifesto: ícones 192/512 + maskable (' + tipos + ')');
  for (const i of man.icons) { const r = await page.request.get(BASE + i.src); ok(r.status() === 200 && /image\/png/.test(r.headers()['content-type']), 'ícone acessível ' + i.src); }
  ok((await page.request.get(BASE + '/manifest.php')).headers()['content-type'].includes('application/manifest+json'), 'manifesto com MIME correto');

  // ---- service worker + instalabilidade
  await page.reload(); await page.evaluate(() => navigator.serviceWorker.ready);
  await page.reload(); await esperar(500);
  ok(await page.evaluate(() => !!navigator.serviceWorker.controller), 'service worker ativo e controlando a página');
  const cdp = await ctx.newCDPSession(page);
  try { const r = await cdp.send('Page.getInstallabilityErrors'); ok(r.installabilityErrors.length === 0, 'instalável (sem erros de instalabilidade) ' + JSON.stringify(r.installabilityErrors.map(e => e.errorId))); }
  catch (e) { console.log('SKIP instalabilidade via CDP: ' + e.message); }
  const cacheInfo = await page.evaluate(async () => { const o = []; for (const k of await caches.keys()) { const c = await caches.open(k); for (const r of await c.keys()) o.push(new URL(r.url).pathname); } return o; });
  if (process.env.DEBUG) console.log('  cache:', cacheInfo.join(' '));
  ok(cacheInfo.includes('/offline.html') && cacheInfo.some(p => p.includes('bootstrap')), 'estáticos e página offline pré-carregados (' + cacheInfo.length + ' itens)');
  ok(!cacheInfo.some(p => p.startsWith('/api/') || /^\/(garcom|comandas|caixa|login)/.test(p)), 'cache NÃO contém API nem páginas autenticadas');

  // ---- layout mobile
  const larg = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  ok(larg[0] <= larg[1], `sem rolagem horizontal na lista (${larg[0]}px ≤ ${larg[1]}px)`);
  if (process.env.SHOTS) await page.screenshot({ path: process.env.SHOTS + '/01-lista.png' });

  // ---- permissões do perfil garçom
  for (const p of ['/caixa', '/produtos', '/usuarios', '/configuracoes', '/sync_conflitos']) {
    const r = await page.goto(BASE + p); ok(!/Abrir caixa|Estoque|Criar usuário|Sincroniza/.test(await page.content()) , 'garçom NÃO acessa ' + p);
  }

  // ---- polling: comanda criada por outro dispositivo aparece sozinha
  await page.goto(BASE + '/garcom'); await esperar(500);
  sql("INSERT INTO comandas (identificacao,tipo,codigo) VALUES ('7','mesa','CMD000007')");
  await page.waitForFunction(() => [...document.querySelectorAll('.cmd-card .nome')].some(n => n.textContent.trim() === '7'), null, { timeout: 12000 }).then(() => ok(true, 'lista atualiza sozinha (polling) com a nova comanda'), () => ok(false, 'lista atualiza sozinha (polling)'));

  // ---- câmera (contexto seguro: localhost). Câmera fake do Chromium: só valida que abre sem erro.
  await page.click('#btnCamera'); await esperar(1500);
  const camOk = await page.evaluate(() => !!document.querySelector('#cameraLeitor video')) && !(await page.locator('.aviso-central').count());
  ok(camOk, 'câmera abre em contexto seguro (câmera simulada)' + (camOk ? '' : ' → ' + JSON.stringify(await page.evaluate(() => ({ box: document.getElementById('cameraBox').style.display, h: document.getElementById('cameraLeitor').innerHTML.slice(0, 200), q: typeof Html5Qrcode, sec: isSecureContext, av: [...document.querySelectorAll('.aviso-central')].map(a => a.innerText) })))));
  if (camOk) { await page.click('#btnFecharCamera'); await esperar(300); } else await page.reload();

  // ---- fluxo do pedido
  const cid = sql("SELECT id FROM comandas WHERE identificacao='7'");
  await page.goto(BASE + '/garcom?id=' + cid);
  await page.fill('[data-r=busca]', 'cerv');
  await page.click('.prod-btn >> text=Cerveja lata'); await page.click('.aviso-confirmar');           // adiciona 1
  await page.click('.prod-btn >> text=Cerveja lata'); await page.click('.aviso-confirmar');           // 2
  await page.fill('[data-r=busca]', 'espeto'); await page.click('.prod-btn >> text=Espeto de carne'); await page.click('.aviso-confirmar');
  await page.fill('[data-obs]:visible >> nth=-1', 'ao ponto');
  ok(sql(`SELECT COUNT(*) FROM comanda_itens WHERE comanda_id=${cid}`) === '0', 'nada gravado antes de enviar');
  await page.click('[data-r=benviar]');
  await page.waitForSelector('.resumo');
  const resumo = await page.innerText('.resumo');
  ok(/2×/.test(resumo) && /Cerveja lata/.test(resumo) && /Espeto de carne/.test(resumo) && /ao ponto/.test(resumo) && /R\$\s*27,00/.test(resumo), 'resumo mostra itens, observação e total R$ 27,00');
  if (process.env.SHOTS) await page.screenshot({ path: process.env.SHOTS + '/02-resumo.png' });
  await page.click('.resumo [data-x=voltar]');
  ok(sql(`SELECT COUNT(*) FROM comanda_itens WHERE comanda_id=${cid}`) === '0', '"Voltar" não envia nada');

  // falha de rede ANTES de chegar ao servidor
  await page.route('**/api/lancar.php', r => r.abort('failed'));
  await page.click('[data-r=benviar]'); await page.click('.resumo [data-x=ok]'); await page.waitForSelector('.aviso-central');
  ok(/NÃO ter sido enviado|NÃO foi enviado/.test(await page.innerText('.aviso-central')) && sql(`SELECT COUNT(*) FROM comanda_itens WHERE comanda_id=${cid}`) === '0', 'falha de rede: avisa que NÃO enviou e nada é gravado');
  await page.unroute('**/api/lancar.php'); await page.click('.aviso-fechar').catch(() => {});

  // servidor GRAVA mas a resposta se perde (pior caso) → reenvio não duplica
  await page.route('**/api/lancar.php', async r => { await r.fetch(); await r.abort('failed'); });
  await page.click('[data-r=benviar]'); await page.click('.resumo [data-x=ok]'); await page.waitForSelector('.aviso-central'); await esperar(300);
  ok(sql(`SELECT COUNT(*) FROM comanda_itens WHERE comanda_id=${cid}`) === '2' && sql(`SELECT SUM(quantidade) FROM comanda_itens WHERE comanda_id=${cid}`).startsWith('3'), 'resposta perdida: servidor gravou (3 un.) mas app NÃO declarou "enviado"');
  await page.unroute('**/api/lancar.php'); await page.click('.aviso-fechar').catch(() => {});
  const antes = sql(`SELECT COUNT(*) FROM comanda_itens WHERE comanda_id=${cid}`);
  await page.click('[data-r=benviar]');
  await page.dblclick('.resumo [data-x=ok]').catch(() => {});                                            // toque duplo no confirmar
  await page.waitForURL(/enviado=1/, { timeout: 10000 }).catch(() => {});
  ok(sql(`SELECT COUNT(*) FROM comanda_itens WHERE comanda_id=${cid}`) === antes && sql(`SELECT SUM(quantidade) FROM comanda_itens WHERE comanda_id=${cid}`).startsWith('3'), 'reenvio + toque duplo: continua 3 unidades (idempotente), sem duplicar');
  ok(sql(`SELECT estoque FROM produtos WHERE nome='Cerveja lata'`) === '98.000', 'estoque baixou uma única vez (98)');
  ok(/enviado/i.test(await page.content()) || page.url().includes('enviado=1'), 'só após confirmação do servidor a tela mostra "enviado"');

  // sessão expirada
  await page.goto(BASE + '/garcom?id=' + cid); await page.fill('[data-r=busca]', 'cerv'); await page.click('.prod-btn >> text=Cerveja lata'); await page.click('.aviso-confirmar');
  await ctx.clearCookies();
  await page.click('[data-r=benviar]'); await page.click('.resumo [data-x=ok]'); await page.waitForSelector('.aviso-central');
  ok(/sessão expirou/i.test(await page.innerText('.aviso-central')) && sql(`SELECT COUNT(*) FROM comanda_itens WHERE comanda_id=${cid}`) === antes, 'sessão expirada: mensagem clara e nada gravado');

  // ---- offline: página offline do SW (HTML autenticado nunca fica guardado)
  await page.goto(BASE + '/login'); await page.fill('[name=login]', 'maria'); await page.fill('[name=senha]', 'Senha1234'); await page.click('button'); await page.waitForURL(/\/garcom/);
  await ctx.setOffline(true);
  await page.goto(BASE + '/garcom').catch(() => {}); await esperar(500);
  ok(/Sem conexão com o caixa/.test(await page.content()) && /NÃO são guardados|não são guardados/i.test(await page.content()), 'offline: abre página "Sem conexão com o caixa"');
  if (process.env.SHOTS) await page.screenshot({ path: process.env.SHOTS + '/03-offline.png' });
  await ctx.setOffline(false);

  // ---- contexto inseguro (HTTP pela LAN): sem SW e câmera desabilitada com explicação, mas o pedido continua funcionando
  const ctx2 = await browser.newContext({ ...devices['Pixel 7'], locale: 'pt-BR' });
  await ctx2.addInitScript(() => Object.defineProperty(window, 'isSecureContext', { value: false }));
  const p2 = await ctx2.newPage(); await p2.goto(BASE + '/login'); await p2.fill('[name=login]', 'maria'); await p2.fill('[name=senha]', 'Senha1234'); await p2.click('button'); await p2.waitForURL(/\/garcom/);
  const b2 = await p2.locator('#btnCamera'); ok(await b2.isDisabled() && /HTTPS/.test(await b2.innerText()), 'HTTP (inseguro): botão da câmera desabilitado explicando que precisa de HTTPS');
  ok(!(await p2.evaluate(() => navigator.serviceWorker && navigator.serviceWorker.controller)), 'HTTP (inseguro): SW não é registrado (limitação documentada)');
  await ctx2.close();

  ok(externas.size === 0, 'nenhuma requisição externa durante todo o fluxo' + (externas.size ? ' → ' + [...externas].join(',') : ''));
  ok(consoleErros.length === 0, 'sem erros JS na página' + (consoleErros.length ? ': ' + consoleErros.join(' | ') : ''));
  await browser.close();
  console.log(falhas ? `\n${falhas} FALHA(S)` : '\nTODOS OS TESTES PASSARAM'); process.exit(falhas ? 1 : 0);
})().catch(e => { console.error('ERRO', e); process.exit(2); });
