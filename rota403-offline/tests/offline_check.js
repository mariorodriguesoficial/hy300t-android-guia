const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext(); const pg = await ctx.newPage();
  const ext = new Set(), fail = new Set();
  pg.on('request', r => { const u = new URL(r.url()); if (!['127.0.0.1','localhost'].includes(u.hostname) && u.protocol.startsWith('http')) ext.add(r.url()); });
  pg.on('requestfailed', r => fail.add(r.url()));
  const B = process.env.BASE;
  await pg.goto(B + '/login'); 
  await pg.fill('[name=login]', process.env.RUSER||'admin'); await pg.fill('[name=senha]', process.env.RPASS); await pg.click('button');
  await pg.waitForLoadState('networkidle');
  for (const p of ['/comandas','/caixa','/garcom','/produtos','/impressao','/relatorios','/usuarios','/configuracoes','/imprimir/comanda?id=1']) {
    const r = await pg.goto(B + p); await pg.waitForLoadState('load'); await pg.waitForTimeout(800);
    const fonts = await pg.evaluate(() => [...document.fonts].filter(f=>f.status==='loaded').map(f=>f.family).join(','));
    console.log(p, r.status(), 'fontes:', fonts);
  }
  const icon = await pg.goto(B + '/comandas'); 
  const iconOk = await pg.evaluate(async () => { await document.fonts.ready; return document.fonts.check('16px "bootstrap-icons"'); });
  console.log('icones ok:', iconOk, '| externas:', [...ext], '| falhas:', [...fail]);
  await b.close();
})();
