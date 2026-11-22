/* Service worker do Rota 403 (garçom).
 * Regras de segurança/negócio:
 *  - NUNCA guarda HTML autenticado nem respostas /api/ (dados de venda não ficam no aparelho);
 *  - NUNCA enfileira pedidos offline: pedido só é "enviado" quando o servidor confirma;
 *  - só guarda estáticos (CSS/JS/fontes/ícones/fotos) e a página offline.
 * Troque VERSAO ao alterar a lista de arquivos pré-carregados. */
const VERSAO = 'rota403-pwa-v1';
const BASE = self.location.pathname.replace(/\/sw\.js$/, '');
const PRECACHE = ['/offline.html', '/assets/style.css', '/assets/lancador.js', '/assets/atualizar.js', '/assets/avisos.js',
  '/assets/vendor/bootstrap/bootstrap.min.css', '/assets/vendor/bootstrap/bootstrap.bundle.min.js',
  '/assets/vendor/bootstrap-icons/bootstrap-icons.min.css', '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
  '/assets/vendor/fonts/fonts.css', '/assets/vendor/fonts/barlow-latin-400-normal.woff2', '/assets/vendor/fonts/barlow-latin-600-normal.woff2',
  '/assets/vendor/fonts/bebas-neue-latin-400-normal.woff2', '/assets/pwa/icon-192.png'].map(p => BASE + p);

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSAO).then(c => Promise.all(PRECACHE.map(u => c.add(new Request(u)).catch(() => null)))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== VERSAO).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('message', e => { if (e.data === 'pular-espera') self.skipWaiting(); });

self.addEventListener('fetch', e => {
  const r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== self.location.origin) return;          // POST/PUT etc. e terceiros: direto à rede
  const p = u.pathname.slice(BASE.length);
  if (p.startsWith('/api/') || p === '/sw.js' || p === '/manifest.php') return;  // dados e o próprio SW: sempre rede
  if (r.mode === 'navigate') {
    e.respondWith(fetch(r).catch(() => caches.match(BASE + '/offline.html')));    // sem rede: página offline (HTML nunca é guardado)
    return;
  }
  if (p.startsWith('/assets/')) {                                                 // estáticos: cache primeiro, atualiza em segundo plano
    const chave = new Request(u.origin + u.pathname);                             // chave sem ?v= (muda a cada deploy)
    e.respondWith(caches.open(VERSAO).then(async c => {
      const hit = await c.match(chave);
      const rede = fetch(r).then(res => { if (res.ok) c.put(chave, res.clone()); return res; }).catch(() => hit);
      return hit || rede;
    }));
  }
});
