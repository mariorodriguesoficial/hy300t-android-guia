# 7. Firmware: existe atualização?

Resumo: **até nov/2026 não há firmware público e confiável para a placa `t950s_be311`**. O sistema de fábrica (Android 13, build de jan/2025) foi mantido, apenas limpo.

## O que foi verificado

- Os firmwares de "HY300" disponíveis na internet (androidpctv, GitHub etc.) são para **Allwinner H713/H726** e usam PhoenixSuit. **São incompatíveis** com esta placa Amlogic — gravá-los pode inutilizar o aparelho.
- O atualizador do sistema (`com.droidlogic.updater`, `update_engine` A/B) não aponta para nenhum servidor OTA do fabricante.
- Há um registro público no Geekbench de uma placa `t950s_be311` com **Android 14**, ou seja, o fabricante pode ter um build mais novo — mas ele não está publicado.

## Se você conseguir um firmware (ex.: com o vendedor)

Só grave se **todos** baterem com o seu backup (`backup\props\getprop.txt`):

- `ro.product.device` / `ro.product.board` = `t950s_be311`
- `ro.board.platform` = `txhd2`
- Modelo do device tree (`/proc/device-tree/model`) — no aparelho testado: `txhd2_t950s_be301-2g`
- Layout de partições igual

Ferramenta: **Amlogic USB Burning Tool** (modo burn: botão + cabo USB A-A). **Nunca marque "Erase bootloader"** e entenda o efeito de "Erase flash" nas chaves/calibração antes de usar. Tenha o backup do passo 3.
