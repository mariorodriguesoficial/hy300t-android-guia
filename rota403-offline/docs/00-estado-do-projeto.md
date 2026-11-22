# Estado do projeto (atualizado em 2026-11-22)

Estrutura: `baseline-v23/` (original, intocado) · `app/` (versão de trabalho) · `baseline-kit/` (scripts Windows do kit, a refazer + `schema-only.sql`) · `tests/` · `docs/`.

| Fase | Estado | Evidência |
|---|---|---|
| 0 Auditoria | PASS (docs 01, 02; revisão completa pelo v23 pendente) | docs/ |
| 2 Offline (assets) | PASS em navegador headless Linux: 0 requisições externas, fontes/ícones locais (`tests/offline_check.js`) | exige rodar: `BASE=... RUSER=... RPASS=... node tests/offline_check.js` |
| 3a Base de sync (migração 001) | PASS 10/10 testes (`tests/run_all.sh`), mutação confirma que os testes detectam regressão | uuid/rev/updated_at_utc/tombstones/livro-razão de estoque/idempotência |
| Correções | idempotência no lançamento (`chave`), cancelamento atômico | testes |
| 3b API sync, worker, conflitos | PASS local (20 testes, `tests/run_sync.sh`; 4 mutações detectadas). **Não** testado contra Hostinger/HTTPS real; worker ainda não é serviço Windows | docs/04 |
| 4 PWA do garçom | PASS no Chromium/Pixel 7 emulado (26 verificações) + Caddy/TLS validado em Linux. **Android físico, câmera real e Wi-Fi real: BLOCKED** | docs/07, `tests/run_pwa.sh` |
| 5 Impressão | NÃO INICIADO. QZ no v23 está travado ao domínio `mrinformatica.inf.br` (`impressao.php`, `includes/qz_security.php`): local cai no diálogo do navegador | — |
| 1/6/7/8/9 Instalador, serviços, backup, painel, segurança | NÃO INICIADO — `BLOCKED` para validação (sem Windows aqui) | — |

## Riscos abertos da migração 001 (precisam de decisão/validação na Hostinger)
1. **Triggers**: exigem privilégio `TRIGGER` no MariaDB da Hostinger. Não verificado. Se negado, a captura de rev/estoque precisa migrar para PHP (helpers).
2. **Defaults com expressão** (`DEFAULT (UUID())`) exigem MariaDB ≥ 10.2.1; testado em 10.11.14 (dump veio de 11.8.9, compatível).
3. `ALTER TABLE` em tabelas com dados online: rápido (tabelas pequenas) mas **faça backup e rode em horário de baixa**; nunca aplicar em produção sem homologação.
4. Exclusões em cascata (FK) não disparam triggers: `comanda_itens` removidos por exclusão de comanda não geram tombstone. Hoje o sistema não exclui comandas (exceto o script ZERAR_VENDAS, que deve ser tratado como reset explícito).
5. O código original ainda faz `garantir*()`/`ALTER` em runtime; coexistência testada só nas tabelas existentes.
6. Código novo foi aplicado só em `app/`. Para a Hostinger ele precisa ser publicado manualmente (não há acesso daqui).
