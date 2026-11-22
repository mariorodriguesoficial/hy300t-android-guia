# 04 — Arquitetura de sincronização (v1)

Modelo: **hub online (Hostinger) ⇄ N dispositivos locais**. Os dois bancos são ativos e independentes. Nada é copiado por tabela inteira; não há `DROP/TRUNCATE`. O PDV grava só no banco local; triggers enfileiram; um worker separado envia/recebe. A venda nunca espera a rede.

## 1. Captura de mudanças (migrações 001 e 002)
- Cada tabela sincronizável ganhou `uuid`, `rev` (versão), `updated_at_utc`, `origin_device`, `deleted_at_utc`; PKs inteiras **intactas**.
- Triggers (valem para o código legado, sem editar cada página): `rev` só sobe quando muda coluna **de negócio** (ver `includes/SyncSpec.php`; estoque, total, `impressa`, `impresso` não contam). INSERT/UPDATE/DELETE gravam em `sync_outbox`.
- **Estoque é livro-razão**: `estoque_movimentos` (delta, uuid). O saldo nunca trafega; trafegam movimentos, aplicados uma vez (`INSERT IGNORE` por uuid) — soma correta com vendas simultâneas.
- `sync_outbox.via`: `NULL` = originado aqui; senão = dispositivo de quem recebemos. Permite *relay* no hub (N dispositivos) sem eco ao remetente.

## 2. Protocolo (`/api/sync/v1/`)
| Rota | Função |
|---|---|
| `GET health` | público, só `{ok,api,esquema}` |
| `POST push` | `{eventos:[…≤200]}` → resultado por evento (**é o ACK do push**) |
| `GET pull?cursor=N&limite=M` | eventos do outbox com `seq>N` (o cursor enviado confirma o recebimento até N) |

(Divergência intencional do rascunho do prompt: não há `/ack` separado; ACK = resposta do push / cursor do pull.)

Evento: `{event_id, tabela, uuid, op U|I|D, rev, origin_device, data, [forcar]}`. FKs trafegam como `<coluna>_uuid`, nunca como id. Status por evento: `aceito | duplicado | conflito | rejeitado | retry`.

**Autenticação**: por dispositivo (`sync_devices`, revogável). Cabeçalhos `X-Device, X-Timestamp, X-Nonce, X-Signature` = HMAC‑SHA256 de `MÉTODO\nURI\nts\nnonce\nsha256(corpo)`. Janela ±5 min, nonce de uso único (15 min), 120 req/min/dispositivo, corpo ≤ 1 MB, ≤ 200 eventos, HTTPS obrigatório (`SYNC_REQUIRE_HTTPS`, padrão `true`), segredo guardado cifrado (`cifrar()`), mostrado uma vez na criação. A origem do evento é o dispositivo **autenticado**, não o declarado. Validação de esquema por lista branca de colunas; tabelas fora do escopo (ex.: `usuarios`) são rejeitadas.

## 3. Regras por entidade
| Entidade | Regra |
|---|---|
| `comanda_pagamentos` | imutável: só insere (uuid); nunca atualiza. Soma > total → conflito `pagamento_excede_total` (revisão humana) |
| `estoque_movimentos` | livro-razão idempotente por uuid |
| `comanda_itens`, `pedidos` | upsert por uuid; edição concorrente → conflito, mantém local |
| `comandas` | itens/pagamentos mesclados por uuid; `total` é **derivado** (recalculado, não sincronizado). Estado terminal (fechada/cancelada) vence `aberta`; **nunca reabre**; fechada×cancelada → conflito `estado_divergente`. Item chegando em comanda já fechada é **aplicado** (nada se perde) e sinalizado |
| `produtos`, `categorias`, `formas_pagamento` | edição concorrente → conflito, **nenhum valor é escolhido sozinho**. Mesma chave natural (nome / código de barras) com uuids diferentes → adota o uuid remoto |
| `caixas` | espelhadas; `usuario_id` local := 1º admin local (usuários não sincronizam) |
| `usuarios`, `configuracoes`, `preparos`, logs, `fila_impressao` | **não sincronizam** (política de segurança / local) |

Detecção de concorrência: `sync_state.synced_rev` = última rev comum. Conflito ⇔ `local.rev > synced_rev` **e** `evento.rev > synced_rev` **e** dados diferentes. Relógio de parede **não** decide nada (só a janela de autenticação).
Comandas: nº próprio por dispositivo (`bin/sync-admin.php id-inicial 500000` no local) + `codigo` (CMD…) preservado; se um código colidir, recebe sufixo e abre conflito `codigo_duplicado`. Pedidos/comandas importados entram **como já impressos** (não imprimem em dobro).

## 4. Conflitos e painel
`sync_conflicts` + página **Sincronização** (admin): tipo, campos, valor local/remoto, decisão, quem, quando, justificativa obrigatória. Resolver `local`/`remoto` gera evento `forcar` que o outro lado aplica e usa para fechar o conflito correspondente. Tipos informativos só admitem "manter".

## 5. Worker (`bin/sync-worker.php`)
`GET_LOCK` (um worker por vez) → envia (lote ordenado por dependência, mudanças da mesma linha colapsadas, `retry` mantém pendente) → baixa (lote ordenado; pai ausente é **adiado** em `sync_deferred`, sem travar) → cursor só avança após o lote inteiro. Sem rede: registra erro e espera com backoff exponencial + jitter; o PDV não é afetado. Qualquer queda no meio é segura (tudo idempotente).

## 6. Limitações conhecidas (não são bugs ocultos)
1. **Nada disto foi testado contra a Hostinger nem sobre HTTPS real**; os testes usam dois bancos locais e HTTP em loopback com `SYNC_REQUIRE_HTTPS=false`.
2. Requer privilégio `TRIGGER` e MariaDB ≥ 10.2.1 (expressões em DEFAULT) no online. Não verificado.
3. Carga inicial: o online precisa rodar `sync-admin semear` (enfileira linhas existentes + saldo inicial de estoque); o local deve nascer **vazio** e receber tudo por pull (restaurar dump no local geraria uuids diferentes).
4. Fotos de produtos (`assets/produtos`) e logo não sincronizam (arquivos).
5. Horários trafegam como texto no fuso America/Sao_Paulo (ambos os lados usam `-03:00`).
6. Exclusão em cascata (FK) não dispara trigger; o sistema atual não exclui comandas.
7. Resolver "remoto/forçado" não reverte automaticamente efeitos de estoque de um cancelamento; ajuste manual por movimento.
8. Worker como serviço Windows, indicador de status no cabeçalho e backup antes de sync: **pendentes** (Fase 1/7).
9. Sem teste de carga; lotes de 200.

## 7. Cobertura dos testes exigidos (§6.6 do prompt) — `tests/test_sync_engine.py` (20 testes)
| # | Cenário | Teste | Resultado |
|---|---|---|---|
| 1 | Operação local sem internet | T02 | PASS |
| 2 | Operação online com local offline | T03 | PASS |
| 3 | Volta da internet, nos dois sentidos | T02/T03 | PASS |
| 4 | Mesma mensagem repetida | T04 (evento, requisição/nonce, movimento de estoque) | PASS |
| 5 | Timeout após gravar, antes do ACK | T04 | PASS |
| 6 | Reinício no meio da fila | T05 (lote=2) | PASS |
| 7 | Dois dispositivos simultâneos | T07 | PASS |
| 8 | Itens nos dois ambientes | T06 | PASS |
| 9 | Pagamentos concorrentes | T06 | PASS |
| 10 | Estoque concorrente | T06 | PASS |
| 11 | Preço simultâneo | T08 | PASS |
| 12 | Cancelar × fechar | T08 | PASS |
| 13 | Token revogado/expirado | T09 | PASS |
| 14 | Payload adulterado | T09 | PASS |
| 15 | Restauração de backup / retomada por cursor | T10 (cursor zerado) — **não** testa restore real de dump | PASS parcial |
| 16 | Relógios divergentes | T09 (±4000 s recusado com mensagem; 120 s tolerado) | PASS |

Mutações verificadas (a suíte falha quando o recurso é removido): assinatura, anti-replay, idempotência do livro-razão, política de estado terminal.
