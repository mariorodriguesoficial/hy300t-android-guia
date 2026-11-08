# 3. Backup completo do firmware

**Faça antes de qualquer mudança.** Execute **`04_backup_completo.bat`** e deixe a janela aberta (leva ~1 hora pelo Wi-Fi, ~3 MB/s).

O script:

1. Confere se há pelo menos **15 GB livres** no disco.
2. Copia **cada partição** (todas, exceto `userdata`) com `adb exec-out "dd if=/dev/block/by-name/X"` para `backup\partitions\X.img`, incluindo `mmcblk0boot0/1`.
3. Copia o **eMMC inteiro** (`/dev/block/mmcblk0`, ~7,8 GB) para `emmc_full_mmcblk0.img`.
4. Calcula o **SHA-256 de cada partição no projetor** e **no PC**, e grava a comparação em `backup\partitions\SHA256SUMS.txt`. Todas devem estar `OK`.

> ⚠️ Por que `cmd /c "... > arquivo"` e não PowerShell? O `>` do **Windows PowerShell 5.1** grava texto UTF-16 e **corrompe arquivos binários**. Os scripts usam o `cmd`, que é seguro.

> ⚠️ Se você conferir os hashes enquanto o Windows ainda está gravando, pode ver diferenças falsas. Rode a verificação depois que o backup terminar.

## Resultado no aparelho testado

- 32 de 32 partições com SHA-256 idêntico (projetor × PC).
- Imagem completa do eMMC com tamanho exato (7.818.182.656 bytes); as faixas de 12 partições estáticas dentro dela batem com os hashes do projetor. `userdata`/`metadata` mudam com o sistema ligado, então nessa parte a imagem não é 100% consistente — irrelevante para restaurar o firmware.

## 🔒 Privacidade

A pasta `backup\` contém **dados únicos do seu aparelho** (chaves em `reserved`/`factory`/`rsv`, MAC, número de série e, na imagem completa, suas contas). **Nunca publique.** Copie para um HD externo.

## Como restaurar

1. **Uma partição, com o sistema ligado** (ex.: `boot_a`):
   ```bat
   platform-tools\adb.exe -s IP:5555 exec-in "dd of=/dev/block/by-name/boot_a bs=4M" < backup\partitions\boot_a.img
   platform-tools\adb.exe -s IP:5555 shell sha256sum /dev/block/by-name/boot_a
   ```
   Não grave a `super` com o sistema rodando.
2. **Via fastboot** (`adb reboot bootloader`): `fastboot flash <particao> <arquivo>.img`.
3. **Aparelho que não liga:** modo de gravação da Amlogic (botão de reset/burn + cabo USB A-A) e ferramenta `update.exe` (aml-flash-tool) para gravar partições ou a imagem completa. Último recurso.
