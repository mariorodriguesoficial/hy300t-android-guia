# 8. Solução de problemas

## Tela preta depois de reiniciar

Quase sempre é o `com.jm.jmlauncher` desativado ou a `persist.sys.boot_app` preenchida.

```bat
platform-tools\adb.exe -s IP:5555 shell pm enable com.jm.jmlauncher
platform-tools\adb.exe -s IP:5555 shell "setprop persist.sys.boot_app ''"
platform-tools\adb.exe -s IP:5555 shell input keyevent KEYCODE_HOME
```

Apertar **Início** no controle também traz o launcher. Em último caso, `reverter_limpeza.bat`.

## Ver a tela do projetor no PC

`11_tela.bat` executa os comandos listados em `scripts\cmd.txt` (um por linha, sem o `adb shell`) e salva uma captura em `logs\telas\atual.png`. Exemplos de `cmd.txt`:

```
input keyevent KEYCODE_HOME
am start -n com.spocky.projengmenu/.ui.guidedActions.activities.settings.AppSettingsActivity
input keyevent KEYCODE_DPAD_DOWN
input keyevent KEYCODE_DPAD_CENTER
```

Apps com proteção (Netflix) aparecem pretos na captura — é normal.

## Estado atual

`12_status.bat` mostra o app em primeiro plano, o launcher padrão, os apps desativados e se o Projectivy/keystone estão rodando.

## Brilho

- O LED já fica no máximo (`/sys/class/backlight/aml-bl/brightness` = 255/255) e os ajustes de imagem (`/sys/class/amvecm/brightness|contrast`) ficam neutros. O firmware não tem menu de "modo de imagem".
- O brilho do Android (`settings put system screen_brightness 255`) não altera o LED neste aparelho.
- Perda de brilho com o tempo costuma ser física: lente suja, poeira interna, saídas de ar obstruídas. Imagem menor (projetor mais perto) e parede branca/tela ajudam muito em ambiente claro.

## Fechar o ADB da rede

O ADB vem aberto na porta 5555 **como root**. Quando terminar, você pode desligar a **Depuração USB/ADB** nas Opções do desenvolvedor. Se precisar de novo, ligue-a temporariamente. Mantenha o projetor numa rede de confiança.
