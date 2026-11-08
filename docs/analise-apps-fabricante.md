# Análise técnica dos apps do fabricante

APKs extraídos com `adb pull /system/app/<pasta>` e analisados por strings do `classes.dex` e por desmontagem simples com `tools/dexdump.py`. Todos rodam com `sharedUserId=android.uid.system` (acesso total ao sistema).

## `com.abc.ninja` — `/system/app/NetflixNinja`

- Classe principal: `GuardService` com `NetworkStateReceiver`.
- Lê `ro.soc.model` via `android.os.SystemProperties` e usa `PowerManager.reboot`.
- Textos: **"Your Device is invalid!"**, **"Device will reboot in 10 minutes!"**.
- Referencia `com.netflix.ninja`.
- Conclusão: trava antiadulteração/kill switch sem função para o usuário. **Desativado.**

## `com.mbox.accessibility` — `/system/app/NetFocus`

- `MyAccessibilityService`, `MainService`, `BootBroadcastReceiver`, OkHttp.
- Referencia `com.netflix.mediaclient` e usa `findAccessibilityNodeInfosByViewId` / `getLeanbackLaunchIntentForPackage`.
- Nenhum domínio externo encontrado nas strings.
- Conclusão: adapta a Netflix de celular ao controle remoto. **Mantido.**

## `com.fb` — `/system/app/JMFactory`

- `functiontestapp.*`, `openg.OpenGLESActivity`, `LedReceiver`, `CpuManager`.
- Escreve em `/sys/class/leds/led_en/brightness`.
- Conclusão: teste de fábrica + LED. **Mantido.**

## `com.jm.jmlauncher` — `/system/app/JmLauncher`

Desmontagem (resumida) de `com.jm.jmlauncher.Launcher`:

```
onCreate:
  src = SystemProperties.get("persist.sys.poweron_source", "Android")
  if (!"".equals(SystemProperties.get("vendor.sys.disable.launcher","")) && "HDMI1".equals(src)) { ...contador de ligações... }
  startCustomHomeActivity()

startCustomHomeActivity:
  if (!src.equals("Android"))  -> abre android.media.tv.action.SETUP_INPUTS (HDMI/AV)
  else if (boot_app != "")     -> startActivity(pm.getLaunchIntentForPackage(boot_app))
  else                         -> startHomeActivity()

startHomeActivity:
  for (ri : pm.queryIntentActivities(MAIN + HOME)) -> startActivity(ri) ; break

onResume:
  if (!mIsFirst) startHomeActivity()
```

Conclusão: é o trampolim do botão Início. **Nunca desativar.**

## `com.google.android.leanbacklauncher`

`dumpsys package` mostra apenas `.BootCompletedReceiver`; `query-activities -p com.google.android.leanbacklauncher` → *No activities found*. É um stub.

## Como reproduzir

```bash
adb pull /system/app/JmLauncher
python3 tools/dexdump.py JmLauncher/JmLauncher.apk
```
