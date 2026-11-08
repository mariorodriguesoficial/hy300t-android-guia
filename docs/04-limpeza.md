# 4. Limpeza dos apps do fabricante

Tudo é feito com `pm disable-user --user 0` — o app **continua no sistema**, só fica desligado. Para desfazer: **`reverter_limpeza.bat`**.

Execute **`05_limpeza.bat`**. Depois, teste: imagem, controle remoto, HDMI, Wi-Fi, som e trapézio.

## O que o script desativa

| Pacote | O que é | Por quê |
|---|---|---|
| `com.master.apps` (AllApp) | App do fabricante | Localização **em segundo plano**, Bluetooth, sobreposição de tela, inicia com o sistema |
| `com.overseas.store.appstore` | Loja paralela | Mesmas permissões, inclusive localização em segundo plano |
| `com.master.tv.market` | Loja paralela | Bloatware |
| `com.abc.ninja` ("NetflixNinja") | App de sistema | Contém um **kill switch**: lê `ro.soc.model`, mostra "Your Device is invalid!" e "Device will reboot in 10 minutes!" e chama `reboot`. Nenhuma função para o usuário. Ver [análise](analise-apps-fabricante.md). |

O launcher do fabricante é tratado no [passo 5](05-launcher-projectivy.md).

## O que NÃO desativar

| Pacote | Função |
|---|---|
| **`com.jm.jmlauncher`** | **Atende o botão Início** (`HOME.JM`). Sem ele: tela preta. |
| `com.softwinner.tcorrection` | Trapézio/keystone e serviços do projetor |
| `com.droidlogic.*` | Imagem, entradas HDMI, configurações da Amlogic |
| `com.mbox.accessibility` (NetFocus) | Faz o Netflix (versão celular) funcionar com o controle remoto |
| `com.fb` (JMFactory) | Teste de fábrica e controle do LED (`/sys/class/leds/led_en`) |
| `com.htc.htcsettings` | Telas "Projeção" (zoom, método de projeção, keystone) |
| `com.android.*`, `com.google.android.*` | Sistema |

## Outros HY300: pacotes maliciosos conhecidos

Em outros firmwares de HY300 foram documentados `com.hotack.silentsdk`, `com.hotack.writesn`, `com.htc.eventuploadservice`, `com.htc.expandsdk`, `com.htc.htcotaupdate`, `com.htc.storeos`. Se o seu inventário mostrar algum, acrescente-o à lista `APPS` no início de `05_limpeza.bat` e em `reverter_limpeza.bat`.
