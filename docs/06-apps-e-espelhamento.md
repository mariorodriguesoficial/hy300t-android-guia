# 6. Apps de streaming e espelhamento do iPhone

## Atualizar pela Play Store

Pela Play Store do próprio projetor (o script `11_tela.bat` com `cmd.txt` também abre páginas, ver [solução de problemas](08-solucao-de-problemas.md)):

| App | Pacote | Versão de fábrica → atualizada |
|---|---|---|
| Disney+ | `com.disney.disneyplus` | 4.21 → 26.20 |
| HBO Max | `com.wbd.stream` | 6.11 → 22.0 |
| Prime Video | `com.amazon.amazonvideo.livingroom` | 6.16 → 6.24 |
| AirScreen | `com.ionitech.airscreen` | 2.3 → 2.18 |

Para abrir a página de um app direto no projetor:
```bat
platform-tools\adb.exe -s IP:5555 shell am start -a android.intent.action.VIEW -d market://details?id=com.disney.disneyplus
```

## Netflix

- O projetor **não é certificado** pela Netflix: a Play Store diz "não é mais compatível com seu dispositivo", e a versão de TV (`com.netflix.ninja`) não funciona.
- De fábrica vem a versão **de celular** `com.netflix.mediaclient` **6.26.1 (de 2019)**, que dá **erro -16** ("Não foi possível acessar o serviço Netflix").
- Solução usada: desinstalar a antiga e instalar a versão de celular atual para **armeabi-v7a** (no teste: 9.89.0, variante "arm-v7a, nodpi, Android 9.0+", baixada do **APKMirror**, que publica os APKs originais).
- **Sempre confira a assinatura** antes de instalar. O certificado correto é `O=Netflix, Inc., L=Los Gatos, CN=PPD Builder`:
  ```bash
  # dentro do APK, extraia META-INF/*.RSA e:
  openssl pkcs7 -inform DER -in BNDLTOOL.RSA -print_certs -noout
  ```
- Instale com **`10_instalar_apk.bat`** (arraste o APK sobre o script).
- O app `com.mbox.accessibility` (NetFocus) do sistema faz a Netflix de celular funcionar com o controle.
- Qualidade esperada: SD (sem certificação). Atualizações serão manuais.

## Espelhar o iPhone (AirPlay)

1. Atualize o **AirScreen** (a versão de fábrica mostra "Upgrade required").
2. Abra o AirScreen no projetor; ele mostra o nome do receptor (ex.: `AS-t950sbe311`).
3. No iPhone (mesma rede Wi-Fi): **Central de Controle → Espelhar Tela** → escolha o nome.

> **Netflix não funciona por espelhamento do iPhone** ("DRM não suportado"/tela preta). É bloqueio da própria Netflix desde 2019, não do projetor. Use a Netflix instalada no projetor, um cabo HDMI do iPhone (adaptador oficial da Apple) ou um aparelho certificado (Chromecast/Fire TV/Apple TV) na entrada HDMI.

> Se o espelhamento travar, conecte o projetor no Wi-Fi de **5 GHz**.

## Apps que não abrem

Alguns apps de vídeo com proteção anticópia (ex.: empacotador Jiagu) detectam este projetor como **emulador/root** e se fecham sozinhos alguns segundos depois de abrir (`wm_finish_activity ... app-request`). Não há correção segura: disfarçar a identidade do sistema pode quebrar Play Store, Netflix e o boot.
