# HY300-T (Amlogic T950S) — Guia de limpeza, backup e launcher limpo

> Guia para deixar o projetor **HY300-T** (placa `t950s_be311`, Android TV 13) mais limpo, seguro e prático de usar, **sem trocar o firmware**.

Este repositório documenta, passo a passo, tudo o que foi feito num projetor HY300-T real: conexão ADB pela rede, inventário do sistema, **backup completo do firmware com verificação por hash**, remoção de apps de fabricante/rastreamento, troca do launcher por um **launcher limpo (Projectivy) que abre sozinho no boot**, atualização dos apps de streaming e espelhamento do iPhone.

Tudo é feito a partir de um PC com **Windows**, com scripts `.bat` prontos, e **tudo é reversível**.

---

## ⚠️ Antes de começar — leia

- **Confirme que o seu aparelho é o mesmo.** Existem vários "HY300" com chips diferentes (Allwinner H713/H726, Rockchip, Amlogic). Este guia vale **somente** para o HY300-T com **Amlogic T950S, placa `t950s_be311`**. Veja [Como identificar o seu modelo](docs/00-identificar-modelo.md).
- **Faça o backup (passo 3) antes de qualquer mudança.**
- **Não grave firmware de outro HY300** (H713, H726, Pro, Pro+ etc.): pode inutilizar o aparelho e quebrar foco, trapézio e ventoinha.
- Você é responsável pelo seu aparelho. Os procedimentos aqui funcionaram no aparelho testado; o seu pode ser diferente.

## O que você vai conseguir

| Antes | Depois |
|---|---|
| Launcher do fabricante com propaganda e lojas paralelas | Projectivy Launcher limpo, abrindo sozinho no boot |
| Apps de "loja" com localização em segundo plano | Desativados (reversível) |
| App de sistema com "kill switch" que pode reiniciar o aparelho | Desativado |
| Netflix de 2019 dando erro -16 | Versão atual instalada |
| AirScreen (espelhamento) pedindo atualização | Atualizado, AirPlay funcionando |
| Nenhum backup | Backup de todas as partições + imagem completa do eMMC, com SHA-256 conferido |

## Aparelho testado

| Item | Valor |
|---|---|
| Modelo (Configurações → Sobre) | HY300-T |
| Placa / SoC | `t950s_be311` — Amlogic T950S (plataforma `txhd2`), CPU ARMv7 32 bits |
| RAM / armazenamento | 2 GB / eMMC 8 GB |
| Android | 13 (Android TV, `leanback_only`) |
| Build | `be311-13 TQ2A.230405.003.E1-...` (compilado em jan/2025, patch de segurança abr/2023) |
| Tipo de build | `userdebug` / `test-keys` — **ADB pela rede já vem aberto e como root** |
| Partições | A/B + partições dinâmicas (`super`) |

## Passo a passo

| # | Etapa | Documento | Script |
|---|---|---|---|
| 0 | Identificar o modelo | [00-identificar-modelo.md](docs/00-identificar-modelo.md) | — |
| 1 | Preparar o PC e conectar via ADB | [01-conexao-adb.md](docs/01-conexao-adb.md) | `00_baixar_adb.bat`, `01_diagnostico_rede.bat`, `02_conectar.bat` |
| 2 | Inventário (somente leitura) | [02-inventario.md](docs/02-inventario.md) | `03_inventario.bat` |
| 3 | **Backup completo do firmware** | [03-backup.md](docs/03-backup.md) | `04_backup_completo.bat` |
| 4 | Limpeza dos apps do fabricante | [04-limpeza.md](docs/04-limpeza.md) | `05_limpeza.bat` / `reverter_limpeza.bat` |
| 5 | Launcher limpo (Projectivy) abrindo no boot | [05-launcher-projectivy.md](docs/05-launcher-projectivy.md) | `06_instalar_projectivy.bat`, `07_projectivy_boot.bat`, `08_desativar_launcher_fabricante.bat`, `09_teste_boot.bat` |
| 6 | Atualizar apps, Netflix e espelhamento do iPhone | [06-apps-e-espelhamento.md](docs/06-apps-e-espelhamento.md) | `10_instalar_apk.bat` |
| 7 | Firmware: existe atualização? | [07-firmware.md](docs/07-firmware.md) | — |
| 8 | Solução de problemas | [08-solucao-de-problemas.md](docs/08-solucao-de-problemas.md) | `11_tela.bat`, `12_status.bat` |
| — | Análise técnica dos apps do fabricante | [analise-apps-fabricante.md](docs/analise-apps-fabricante.md) | `tools/dexdump.py` |

## Início rápido

1. Baixe este repositório (botão **Code → Download ZIP**) e extraia, por exemplo, em `C:\HY300`.
2. No projetor: **Configurações → Sobre** → clique 7 vezes em **Número da versão** e ative a **Depuração USB** nas Opções do desenvolvedor.
3. Veja o IP do projetor em **Configurações → Rede**.
4. Edite `scripts\config.bat` e coloque o IP do projetor em `PROJ_IP`.
5. Execute os scripts da pasta `scripts` **na ordem**, com dois cliques, lendo o documento de cada etapa antes.

Cada script grava o que fez em `logs\`.

## As 3 descobertas mais importantes deste projetor

1. **O ADB vem aberto na rede e como root** (`service.adb.root=1`, porta 5555, sem autorização). Isso facilita o backup, mas significa que **qualquer aparelho na sua rede Wi-Fi tem controle total do projetor**. Veja como fechar em [08-solucao-de-problemas.md](docs/08-solucao-de-problemas.md#fechar-o-adb-da-rede).
2. **Nunca desative o `com.jm.jmlauncher`.** O firmware foi modificado: o botão Início chama a categoria `android.intent.category.HOME.JM`, atendida só por esse app, que repassa para o launcher. Sem ele o projetor fica numa tela preta (FallbackHome). Detalhes em [05-launcher-projectivy.md](docs/05-launcher-projectivy.md).
3. **O "launcher original do Google" que vem instalado é um app vazio** (só tem um receiver de boot, nenhuma tela). Por isso é preciso instalar um launcher de terceiros.

## O que NÃO está neste repositório (de propósito)

- Nenhum firmware, imagem de partição ou backup — eles contêm **chaves e dados únicos de cada aparelho**. Faça o seu.
- Nenhum APK (Netflix, AirScreen, apps do fabricante etc.) — baixe das fontes oficiais indicadas.
- O ADB (Android Platform-Tools) — o script `00_baixar_adb.bat` baixa a versão oficial do Google.

## Licença

Documentação e scripts sob a licença [MIT](LICENSE) (texto oficial em inglês, com tradução livre em português no mesmo arquivo). Marcas citadas pertencem aos seus donos. Este projeto não tem relação com os fabricantes do projetor nem com os apps citados.
