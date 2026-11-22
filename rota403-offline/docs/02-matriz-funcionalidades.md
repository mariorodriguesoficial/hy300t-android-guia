# 02 — Matriz de funcionalidades

Status: `OK-kit` = existe no PHP do kit; `AUSENTE` = existe no banco/dump mas não no PHP do kit; `FALTA` = a construir. Nenhum item foi testado em execução ainda (sem MariaDB neste ambiente até agora).

| Módulo | Arquivo(s) | Tabelas | Status | Dependências offline | Testes necessários |
|---|---|---|---|---|---|
| Login/sessão | login.php, Auth.php | usuarios | OK-kit (sem rate limit/log) | Bootstrap/fonts CDN | login ok/errado, bloqueio, 1º acesso |
| Usuários | usuarios.php | usuarios | OK-kit; nome cifrado no dump AUSENTE | — | CRUD, não rebaixar a si mesmo |
| Comandas | comandas.php, comanda.php | comandas, comanda_itens | OK-kit | — | criar, listar, ler código, remover item |
| Lançamento (garçom) | garcom.php, api/lancar.php, lancador.js | comanda_itens, pedidos, produtos | OK-kit, **sem idempotência** | html5-qrcode (CDN) | duplo toque, estoque, concorrência |
| Caixa | caixa.php | caixas, comandas | OK-kit; pagamentos divididos AUSENTE | — | abrir/receber/cancelar/fechar; bug cancelar-fechada |
| Pagamentos | — | comanda_pagamentos, formas_pagamento | **AUSENTE** | — | definir após ver PHP real |
| Estoque | produtos.php, helpers | produtos | OK-kit (saldo); ledger FALTA | — | baixa/estorno/edição concorrente |
| Produtos/categorias | produtos.php | produtos, categorias | OK-kit | — | CRUD, código de barras único |
| Impressão/monitor | impressao.php, api/pedidos.php, api/comandas_pendentes.php, imprimir/* | pedidos, comandas.impressa | OK-kit; `fila_impressao` AUSENTE | qz-tray, JsBarcode (CDN) | duas abas, sem duplicar, reimpressão |
| QZ certificado | api/qz_*.php | — | stub 503 | — | provisionar cert próprio |
| Relatórios | relatorios.php | comandas, comanda_itens, caixas | OK-kit | — | totais por período/forma |
| Configurações/logo | configuracoes.php | configuracoes, uploads/ | OK-kit | — | upload seguro, fuso |
| Auditoria de acesso | — | log_acesso, login_tentativas | **AUSENTE** | — | — |
| Sincronização | — | — | FALTA | — | matriz §6.6 do prompt |
| PWA garçom | — | — | FALTA | HTTPS local p/ câmera | Android real (BLOCKED aqui) |
| Instalador/serviços | installer/, windows/ | — | esqueleto com defeitos | runtimes | Windows limpo (BLOCKED aqui) |
| Backup/restauração | — | — | FALTA | — | restaurar em banco vazio |
| Painel de status | — | — | FALTA | — | — |
