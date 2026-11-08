# 2. Inventário (somente leitura)

Execute **`03_inventario.bat`**. Ele **não altera nada** no projetor; apenas salva em `backup\props\`:

| Arquivo | Conteúdo |
|---|---|
| `getprop.txt` | Todas as propriedades do sistema |
| `cpuinfo.txt`, `meminfo.txt`, `uname.txt` | Hardware e kernel |
| `particoes_tamanhos.txt` | Partições `by-name` com tamanho em bytes |
| `offsets.txt` | Início/tamanho de cada `mmcblk0pN` (setores de 512 bytes) |
| `pacotes_sistema.txt`, `pacotes_usuario.txt` | Apps instalados |
| `home.txt` | Launcher padrão atual |
| `root.txt` | Se o shell é root e se existe `su` |

## O que foi encontrado no aparelho testado

- `ro.build.type=userdebug`, `ro.build.tags=test-keys`, `ro.debuggable=1`, `service.adb.root=1` → **o ADB já é root**.
- Existe `/system/xbin/su` e o app `com.koushikdutta.superuser`.
- `ro.boot.flash.locked=0`, `verifiedbootstate=orange` → **bootloader desbloqueado**.
- Partições A/B, slot ativo `_a`, `super` de 2 GB (contém system/vendor/product/odm).
- Launcher padrão: `com.htc.htclauncherhighenglishd08` (o "HTC" no nome é falso; não é da HTC).
- O `com.google.android.leanbacklauncher` existe, mas **não tem nenhuma activity** — é um app vazio.
- Os pacotes maliciosos documentados em outros HY300 (`com.hotack.silentsdk`, `com.htc.eventuploadservice`, `com.htc.expandsdk`, `com.htc.htcotaupdate`, `com.htc.storeos`) **não estavam presentes** neste build. Mesmo assim, procure por eles no seu (o script já lista).

### Tabela de partições (aparelho testado)

| Partição | Tamanho | Observação |
|---|---|---|
| `reserved` (p1) | 64 MB | Dados de fábrica/chaves (Amlogic) |
| `env` | 8 MB | Variáveis do U-Boot |
| `frp` | 2 MB | Proteção de redefinição |
| `factory` | 8 MB | Calibração de fábrica |
| `vendor_boot_a/b` | 64 MB | |
| `bootloader_a/b` | 8 MB | |
| `tee` | 32 MB | |
| `logo` | 8 MB | Logo de boot |
| `misc` | 2 MB | |
| `dtbo_a/b` | 2 MB | |
| `cri_data` | 8 MB | |
| `param` | 16 MB | Inclui `pq/pq.db` (qualidade de imagem) |
| `odm_ext_a/b` | 16 MB | |
| `oem_a/b` | 32 MB | |
| `boot_a/b` | 64 MB | |
| `init_boot_a/b` | 8 MB | |
| `metadata` | 64 MB | |
| `vbmeta_a/b`, `vbmeta_system_a/b` | 2 MB | |
| `super` (p29) | 2000 MB | Partições dinâmicas |
| `rsv` (p30) | 128 MB | |
| `userdata` (p31) | ~4,5 GB | Seus dados/apps |
| `mmcblk0boot0/1` | 4 MB | Áreas de boot do eMMC |
