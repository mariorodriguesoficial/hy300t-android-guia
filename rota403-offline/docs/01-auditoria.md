# 01 — Auditoria técnica (Fase 0)

Data: 2026-11-22 · Escopo: kit `Rota403_Kit_ClaudeCode.zip` (adaptação preliminar do v.11) + `rota403.sql`.
Tudo abaixo foi lido no código/SQL enviados; o que não pôde ser verificado está marcado **[NÃO VERIFICADO]**.

## 0. Achado bloqueante — o código do kit NÃO corresponde ao banco

O SQL (dump da Hostinger, MariaDB 11.8.9) tem 14 tabelas. O PHP do kit usa apenas 9 delas.

| Tabela | Usada no PHP do kit? |
|---|---|
| caixas, categorias, comandas, comanda_itens, configuracoes, pedidos, produtos, usuarios | sim |
| **comanda_pagamentos** | **não** (caixa grava só `comandas.forma_pagamento`) |
| **formas_pagamento** | **não** (`FORMAS` fixo em `caixa.php`) |
| **fila_impressao** | **não** |
| **login_tentativas** | **não** (login sem rate limit) |
| **log_acesso** | **não** |
| **preparos** | **não** (destinos `cozinha`/`balcao` fixos) |

Além disso, `usuarios.nome` no dump está cifrado (`enc1:…`, valor com cifra reversível) e as senhas usam Argon2id; o código do kit lê `nome` em texto puro e usa `PASSWORD_DEFAULT`. Ou seja: o dump veio de uma versão **mais nova/diferente** do PHP em produção (pagamentos divididos, rate limit, auditoria de acesso, fila de impressão, cifra de campos). O PHP em `app/` é uma versão **mais simples**.

**Consequência:** projetar sincronização sobre este PHP seria sincronizar regras que não são as de produção (pagamentos, impressão, usuários). Preciso do **código-fonte real que roda na Hostinger** antes da Fase 3. Também preciso saber de onde vem a chave da cifra `enc1:` (provavelmente em `config.php` real) — sem ela, nomes de usuário importados ficam ilegíveis.

## 1. Estrutura

```
app/  index, login, logout, comandas, comanda, garcom, caixa, produtos, usuarios,
      relatorios, configuracoes, impressao  (12 páginas)
app/api/       lancar, pedidos, comandas_pendentes, qz_assinar*, qz_certificado*  (*stubs 503)
app/includes/  Auth, Database, helpers, header, footer
app/imprimir/  _base, comanda, conta, pedido, fechamento  (HTML 80 mm)
app/assets/    style.css, lancador.js
windows/       .bat/.ps1 de apoio · installer/Rota403.iss
```
Total ≈ 2.900 linhas (código pequeno, auditável por inteiro).

## 2. Requisitos de runtime

- **PHP ≥ 8.1** (tipo `never`, uniões `array|false`, `mixed`, `fn`). Testado aqui com 8.3.6: `php -l` OK nos 28 `.php`. (Prova só sintaxe.)
- Extensões efetivamente usadas: `pdo_mysql`, `mbstring` (`mb_substr`), `json`, `session`, `gd`/`getimagesize` (upload da logo — função nativa do core, sem `fileinfo`), `random_bytes`. `openssl`, `curl`, `intl` **não são usadas** pelo código atual (openssl será necessária para HMAC/TLS do sync e cifra `enc1`).
- MariaDB: dump gerado em 11.8.9. O script converte collation 11.x → `utf8mb4_unicode_ci`. Compatível com MariaDB 10.6+.
- Esquema: InnoDB, utf8mb4, `NO_AUTO_VALUE_ON_ZERO`. **Sem triggers.** FKs: `comanda_itens→comandas (CASCADE)`, `comanda_itens→produtos`, `pedidos→comandas (CASCADE)`, `produtos→categorias (SET NULL)`. Sem FK de `caixas`, `comandas.caixa_id`, `comanda_pagamentos.comanda_id`. UNIQUEs: `usuarios.login`, `produtos.codigo_barras`, `formas_pagamento.nome`. Colunas `timestamp` (UTC no servidor) misturadas com `datetime` (hora local) → **risco de fuso** (ver §9).
- PKs são `INT AUTO_INCREMENT` em todas as tabelas, sem UUID. **Colidirão** entre local e online.

## 3. Autenticação, sessão, CSRF

- Login: `SELECT … WHERE login=? AND ativo=1` + `password_verify`; `session_regenerate_id`. Sem rate limit, sem log (tabelas existem no banco, código não usa).
- Primeiro acesso: se `usuarios` vazia, `login.php` cria administrador (aceita senha de 6 caracteres). Bom para o instalador; **mas qualquer pessoa na LAN que abrir a página antes do dono vira admin** → o instalador deve criar o admin e o endpoint de primeiro acesso deve ser desabilitado depois.
- Níveis: `admin` (passa sempre), `caixa`, `garcom`. `Auth::exigir()` por página/API.
- CSRF: token de sessão em `$_POST['csrf']` ou header `X-CSRF`; `hash_equals`. Todos os POSTs verificam.
- Cookie `HttpOnly`, `SameSite=Lax`, `secure=false` (correto em HTTP local; deve ser `true` se HTTPS LAN).
- Sem headers de segurança (CSP, X-Frame-Options, etc.).
- SQL: 100 % prepared statements (`ATTR_EMULATE_PREPARES=false`). Exceção controlada: `produtos.php` toggle interpola nome de coluna, mas restrito a 2 valores fixos. OK.
- XSS: saída via `e()` consistentemente nas páginas lidas; `footer.php`/scripts montam JS com `json_encode`. **[Revisão linha-a-linha de `impressao.php`/`produtos.php` pendente]**.

## 4. Fluxos

**Comanda**: `comandas.php` cria (`INSERT` + `codigo = CMD%06d` do id). Abre (`comanda.php`), itens via `lancar.php`. `caixa.php` → `receber` faz `UPDATE … status='fechada'` (guardado por `status='aberta'`), `cancelar` devolve estoque item a item, `fechar` soma dinheiro. **Reabertura: não existe.**

**Lançamento do garçom** (`api/lancar.php` → `lancarItens`): transação, `SELECT … FOR UPDATE` na comanda aberta; para cada item grava `comanda_itens` (preço atual do produto), baixa `produtos.estoque`, agrupa em `pedidos` por destino (JSON em `itens`), recalcula total. **Não há chave de idempotência** → toque duplo/retry duplica itens e baixa estoque duas vezes. (Requisito do PWA e do sync.)

**Pagamento**: `receber` grava só o *nome da forma* na comanda; sem valor pago, sem pagamento parcial/dividido, sem troco persistido (coluna `troco` existe no banco). `saldo_final` do caixa = saldo inicial + total em dinheiro, ignorando sangrias/estornos.

**Estoque**: `estoque = estoque - qtd` direto no produto (saldo, não movimentação). `comanda.php` remover item e `caixa.php` cancelar devolvem saldo. `produtos.php` sobrescreve `estoque` ao editar (edição manual concorrente perde baixas). **Não há tabela de movimentações** → inviabiliza sync correto de estoque (precisa de ledger).

**Impressão**: monitor `impressao.php` (aba/janela aberta no PC do caixa) faz polling em `api/pedidos.php` e `api/comandas_pendentes.php`; “reserva” atômica por `UPDATE … WHERE impresso=0` + `rowCount()===1` (correto contra duplicidade entre monitores). Saída: QZ Tray (WebSocket local) ou `window.print` via iframe. QZ exigia certificado/assinatura (`qz_certificado.php`, `qz_assinar.php`) — **stubs 503 no kit**; sem eles QZ mostra prompt de confirmação a cada impressão. Impressão depende do **navegador estar aberto** (não é serviço).

## 5. Dependências externas (bloqueiam uso offline)

| Recurso | Onde | Versão |
|---|---|---|
| Bootstrap CSS+JS | header, footer, login | 5.3.3 |
| Bootstrap Icons | header, login | 1.11.3 |
| Google Fonts (Barlow, Bebas Neue) | header, login | — |
| JsBarcode | `imprimir/_base.php` | 3.11.6 |
| qz-tray.js | `impressao.php` | 2.2.4 |
| html5-qrcode | `garcom.php` (unpkg) | 2.3.8 |

Todos precisam ser baixados, fixados e licenciados localmente (Fase 2). Sem eles **o sistema abre sem estilo e sem ícones**, e a impressão de comanda (código de barras) falha.

## 6. Configuração

`BASE_URL=''`, `config.local.php` com DB_* (exemplo sem segredo), fuso `America/Sao_Paulo` no PHP, `SET time_zone='-03:00'` na conexão. Log de erros em `app/storage/php-error.log` (dentro da pasta do app → mover para `%ProgramData%`).

## 7. Dados sensíveis encontrados

- `rota403.sql` contém **dados reais**: hashes de senha, nomes cifrados, CNPJ e endereço do estabelecimento, comandas/pagamentos, e o nome do banco Hostinger. **Não foi commitado**; esta pasta guarda apenas `baseline-kit/schema-only.sql`. Recomendo rotacionar as senhas dos 3 usuários se o arquivo circulou por e-mail/zip.
- O instalador **não deve** importar dados de produção; deve criar banco vazio + admin novo. Carga inicial de dados = via sync autenticado.
- Chave privada QZ: removida do kit (correto). Provisionar certificado próprio (Fase 5).

## 8. Problemas nos scripts Windows do kit (corrigir na Fase 1)

1. `iniciar-rota403.bat` testa `config\config.local.php` na raiz; o arquivo fica em `app\config\`. Sempre falha.
2. `php-servidor.bat` escuta `0.0.0.0` (contraria a regra: loopback por padrão).
3. `configurar-banco.ps1`: `GRANT ALL PRIVILEGES` (ok no banco da app, mas conceder só o necessário), senha em texto no `.php` sem ACL, importa o dump de produção, `-P 3307` fixo sem checar porta, usuário limitado a `127.0.0.1`.
4. `parar-rota403.bat`: `taskkill /IM php.exe` derruba **qualquer** PHP da máquina.
5. `Rota403.iss`: sem serviços, sem pré-checagem, sem criação de admin, sem preservação de dados na desinstalação, pasta em `Program Files` com `storage` gravável por `users`.
6. Sem `php.ini` no pacote; `runtime/` vazio.

## 9. Riscos de concorrência e integridade

- Itens/estoque sem idempotência (§4). Dois garçons no mesmo item: baixa de estoque correta (UPDATE relativo), mas estoque pode ficar negativo sem aviso.
- `receber` e `cancelar` não verificam `rowCount()`: se a comanda já foi fechada, o fluxo segue mostrando “recebida”; `cancelar` **devolve estoque antes** de verificar status → cancelar comanda já fechada reabastece estoque indevidamente (bug real, pré-existente).
- `caixaAberto()` pega o último aberto; dois caixas abertos simultâneos são possíveis (sem UNIQUE parcial).
- Totais: `comandas.total` é cache recalculado; `subtotal` de itens guarda o preço no momento (bom para auditoria).
- Fusos: `timestamp` (UTC do servidor Hostinger? dump usa `+00:00`) vs `datetime` com `NOW()` (sessão -03:00). Em sync, **todos os instantes precisam ser convertidos para UTC explicitamente**.
- Lançamento por garçom guarda o **nome** (`garcom` varchar), não `usuario_id`.

## 10. Lacunas que impedem o modo offline

1. CDNs/fontes (§5).
2. Sem instalação/serviços/runtimes no Windows.
3. Impressão depende de QZ + certificado e de navegador aberto.
4. Câmera/QR exige contexto seguro (HTTPS ou localhost); no celular via IP da LAN em HTTP a câmera **não** funciona.
5. Sem UUIDs/versões/ledger → sem sincronização possível hoje.
6. Divergência código × banco (§0).

## 11. Plano de risco / ordem de execução proposta

1. **(bloqueio)** Obter o PHP real de produção + origem da cifra `enc1`.
2. Fase 1/2 (pode começar já, não depende de §0): empacotar PHP+MariaDB, loopback, assets locais, scripts corrigidos.
3. Migração 001: UUID/rev/updated_at/deleted_at nas tabelas sincronizáveis + ledger de estoque + `sync_*`.
4. Idempotência em `lancarItens` (`client_request_id`) — serve ao PWA e ao sync.
5. API sync na Hostinger (entregue como código + migração, **sem** deploy: não tenho acesso).
6. Worker, PWA, impressão, backup, instalador.

> O que **não** posso fazer neste ambiente (Linux, sem Windows/Hostinger/impressora/celular): compilar o `.exe`, instalar em Windows limpo, testar impressora térmica e PWA no Android, testar contra a Hostinger. Esses itens ficarão como `BLOCKED` no relatório, nunca como `PASS`.
