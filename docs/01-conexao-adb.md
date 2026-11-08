# 1. Preparar o PC e conectar via ADB

## 1.1 No projetor

1. **Configurações → Sobre** → clique **7 vezes** em **Número da versão** até aparecer "Você agora é um desenvolvedor".
2. Volte e abra **Opções do desenvolvedor** → ative **Depuração USB**.
3. Anote o **IP** do projetor em **Configurações → Rede → (sua rede)**. Ex.: `192.168.0.50`.

> No aparelho testado o ADB pela rede (porta **5555**) **já vinha ligado de fábrica**, como root e sem pedir autorização. A maior dificuldade costuma ser simplesmente **não ter o ADB instalado no PC**.

## 1.2 No PC (Windows)

1. Extraia este repositório em uma pasta (ex.: `C:\HY300`).
2. Abra `scripts\config.bat` no Bloco de Notas e troque o IP em `set PROJ_IP=` pelo IP do seu projetor. Salve.
3. Execute **`00_baixar_adb.bat`** (dois cliques). Ele baixa o **Android SDK Platform-Tools oficial do Google** para `platform-tools\` (versão portátil — nada é instalado no Windows).
4. Execute **`01_diagnostico_rede.bat`**. Ele testa ping e porta 5555 e grava em `logs\01_diagnostico_rede.txt`. Procure:
   - `5555 -> True` → ótimo, siga.
   - Ping falhando → PC e projetor em redes diferentes, rede de convidados ou "isolamento de clientes" no roteador.
5. Execute **`02_conectar.bat`**. Se aparecer no log:

```
192.168.0.50:5555   device product:t950s_be311 model:t950s_be311 device:t950s_be311
uid=0(root) ...
```

você está conectado (e como root).

## Problemas comuns

| Mensagem | O que fazer |
|---|---|
| `unauthorized` | Olhe a tela do projetor: aceite "Permitir depuração" marcando "Sempre permitir". |
| `connection refused` | A porta 5555 está fechada. Use **Depuração sem fio** (Android 11+): `adb pair IP:PORTA CÓDIGO` e depois `adb connect IP:PORTA` (as portas aparecem na tela do projetor). |
| `offline` | `adb disconnect`, `adb kill-server`, desligue e ligue a depuração e tente de novo. |
| Ping "Host de destino inacessível" na 1ª tentativa e depois responde | Normal: o Wi-Fi do projetor estava em economia de energia. |
