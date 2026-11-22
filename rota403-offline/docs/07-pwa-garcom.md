# 07 — PWA do garçom

O celular acessa o servidor local pela Wi-Fi do restaurante. O PC principal precisa ficar ligado. Banco e PHP **nunca** escutam na rede: só o Caddy (HTTPS) escuta, e repassa para o PHP em `127.0.0.1`.

## O que existe
| Requisito | Implementação |
|---|---|
| Instalável (nome/ícone) | `manifest.php` (standalone, `start_url=/garcom`, tema #d9530f), ícones 192/512 + maskable + apple-touch (`assets/pwa/`, gerados da logo) |
| Service worker | `sw.js` (escopo = BASE_URL). Só guarda **estáticos** e `offline.html`. Nunca guarda HTML autenticado nem `/api/` |
| Sem pedido "fantasma" | Não há fila offline. Sem rede: faixa vermelha + envio bloqueado + mensagem "NÃO enviado" |
| Login/perfil | Login existente; garçom só acessa comandas/pedidos (testado: caixa, produtos, usuários, configurações, sincronização negados) |
| Lista de comandas, busca, categorias, quantidade, observação | já existiam (`garcom.php`, `lancador.js`) |
| Resumo antes de enviar | Folha "Confirmar pedido" (itens, observações, total) → **Voltar** ou **Confirmar e enviar** |
| Confirmação do servidor | Só mostra "enviado" após `{"sucesso":true}`; erro de rede/timeout (15 s) → "pode NÃO ter sido enviado, toque de novo — não duplica" |
| Sem duplicar | Botão desabilita; chave idempotente por carrinho (reenvio reutiliza); servidor ignora chave repetida |
| Sessão expirada | Mensagem clara + volta ao login; nada gravado |
| Atualização | Polling de 3 s (`atualizar.js`) |
| Câmera (QR/código de barras) | Reativada (v23 a tinha desligado). Só com HTTPS/localhost; em HTTP o botão fica desabilitado explicando |
| Instalar | Botão "Instalar o app na tela inicial" (evento `beforeinstallprompt`, Android/Chrome) |

## HTTPS na LAN (obrigatório para SW, instalação e câmera no celular)
Em `http://192.168.x.x` o Chrome **não** é contexto seguro: não há service worker, nem "instalar", nem câmera.
1. `php bin/lan-https.php --dados=<pasta de dados> --porta-app=8080 --porta-https=8443` gera o `Caddyfile` (só IPs de rede privada; nunca IP público).
2. Executar `caddy run --config <dados>/Caddyfile --adapter caddyfile` (será serviço do Windows na Fase 1).
3. Caddy cria uma CA local; o celular baixa **só o certificado público** em `https://<IP-do-PC>:8443/rota403-ca.crt`.
4. Android: Configurações → Segurança → Criptografia e credenciais → Instalar certificado → **Certificado de CA** → escolher o arquivo. (Android avisa que a rede "pode ser monitorada" — é a CA do próprio restaurante.)
5. Abrir `https://<IP-do-PC>:8443/` no Chrome, entrar, tocar em **Instalar o app**.
6. Firewall do Windows: liberar **apenas** a porta 8443, **apenas perfil Privado**, escopo sub-rede local. Não liberar 8080 nem 3307.
7. Reservar o IP do PC no roteador (DHCP fixo): o certificado é emitido para o IP. Se o IP mudar, rodar o passo 1 de novo.

## Verificado aqui (Chromium com emulação de Pixel 7, Caddy 2.6.2, Linux)
- `tests/run_pwa.sh`: 26 verificações PASS — manifesto, ícones, SW ativo, **instalável (sem erros do Chromium)**, cache sem API/HTML autenticado, sem rolagem horizontal, permissões, polling, câmera (simulada), resumo, "Voltar" não envia, falha de rede, **resposta perdida + reenvio + toque duplo = 3 un. e estoque baixado uma vez**, sessão expirada, página offline, HTTP inseguro, 0 requisições externas, 0 erros JS.
- Mutações detectadas: remover a chave idempotente (3 falhas), remover o resumo (falha), cachear página autenticada (falha).
- Caddy: cadeia validada com a CA local (`verify=0`), SAN com o IP, cookie `Secure`+HSTS atrás do proxy, download do `root.crt` OK, chave privada da CA **não** exposta (403), app só em `127.0.0.1`.

## NÃO verificado (BLOCKED — exige aparelho/rede reais)
- Android físico: instalar a CA, abrir via IP da LAN, "Adicionar à tela inicial", câmera **real** lendo o código de barras da comanda impressa, ícone maskable no launcher.
- Wi-Fi real (queda e retorno, roaming), desempenho com vários garçons.
- iPhone/Safari: não é alvo (Android pedido); iOS exigiria passos diferentes.
- Firewall do Windows e Caddy como serviço (Fase 1).
- Em HTTPS pelo IP da LAN o Chromium só foi testado em `127.0.0.1` (também é contexto seguro); não havia IP privado neste ambiente.

## Limitações conhecidas
- Sem HTTPS confiável o app funciona como site comum (sem SW/instalação/câmera); pedidos continuam sendo enviados.
- O resumo é só para o perfil que usa `garcom.php` (caixa desktop mantém o fluxo rápido por Enter).
- Fotos de produtos entram no cache conforme uso (não pré-carregadas).
- Trocar a CA local (reinstalar Caddy/pasta de dados) exige reinstalar o certificado em cada celular.
- Alterações do v23 feitas aqui: câmera reativada; botão/contêiner da câmera restaurados; `lancador.js` (resumo, timeout, erros, chave); `garcom.php`, `header/footer`, `style.css`.
