# 5. Launcher limpo (Projectivy) abrindo sozinho no boot

Esta foi a parte mais difícil do projeto, porque o fabricante **alterou o Android** para dificultar a troca de launcher.

## Como o botão Início funciona neste firmware

1. O sistema não chama a categoria padrão `android.intent.category.HOME`, e sim **`android.intent.category.HOME.JM`**.
2. Só dois apps respondem a ela: **`com.jm.jmlauncher/.Launcher`** e a tela de espera do sistema (`com.android.tv.settings/.system.FallbackHome`, prioridade -1000).
3. O `jmlauncher` não tem tela. Decompilando-o (ver `tools/dexdump.py`), a lógica é:
   - **No boot (`onCreate`)**:
     - se `persist.sys.poweron_source` ≠ `Android` → abre uma entrada HDMI/AV;
     - senão, se `persist.sys.boot_app` ≠ vazio → `getLaunchIntentForPackage(boot_app)` (**não funciona com launchers de TV**, que não têm categoria LAUNCHER);
     - senão → abre o **primeiro** app com categoria `HOME`.
   - **Ao apertar Início depois (`onResume`)** → abre o primeiro app com categoria `HOME`.
4. No boot, o Projectivy (instalado em `/data`) ainda não está disponível quando o `jmlauncher` procura, e por isso, sem o launcher do fabricante, a tela fica preta. A solução é deixar o **próprio Projectivy** se abrir no boot pelo serviço de acessibilidade.

## Erros que cometemos (para você não repetir)

| Tentativa | Resultado |
|---|---|
| Desativar `com.jm.jmlauncher` | Tela preta (FallbackHome: "User unlocked but no home") |
| Usar o Leanback do Google | Impossível: o APK é vazio |
| `setprop persist.sys.boot_app com.spocky.projengmenu` | Boot parado no `jmlauncher` (ver item 3) — **não use** |
| Só `cmd package set-home-activity` | Não basta no Android 13; também é preciso o papel `android.app.role.HOME` |

## Passo a passo que funcionou

1. **`06_instalar_projectivy.bat`** — abre a página do **Projectivy Launcher** na Play Store do projetor. Aperte **Instalar** no controle. O script espera a instalação e define o Projectivy como Início (`set-home-activity` + `cmd role add-role-holder android.app.role.HOME`).
2. Teste: botão Início, HDMI, trapézio, configurações.
3. **`07_projectivy_boot.bat`** — liga o serviço de acessibilidade do Projectivy (`com.spocky.projengmenu/.services.ProjectivyAccessibilityService`) e dá a permissão de sobreposição (`appops SYSTEM_ALERT_WINDOW allow`).
4. No Projectivy: **Configurações (engrenagem) → Geral → "Sobrepor o launcher atual" = LIGADO**. (Em "Energia → Iniciar automaticamente após ligar" pode deixar **Nenhum**; essa opção é para abrir uma entrada/app, não o launcher.)
5. **`08_desativar_launcher_fabricante.bat`** — desativa **somente** `com.htc.htclauncherhighenglishd08` (o `jmlauncher` continua ativo!).
6. **`09_teste_boot.bat`** — reinicia e confere. O log deve mostrar o Projectivy em primeiro plano, iniciado por ele mesmo:
   ```
   topResumedActivity=... com.spocky.projengmenu/.ui.home.MainActivity
   START u0 {... cat=[android.intent.category.HOME,android.intent.category.LEANBACK_LAUNCHER] ... pkg=com.spocky.projengmenu ...}
   ```

## Voltar ao launcher de fábrica

Execute **`reverter_limpeza.bat`**: reativa tudo, devolve o papel de Início ao launcher do fabricante e desliga a acessibilidade do Projectivy.
