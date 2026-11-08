# 0. Como identificar o seu modelo

Existem dezenas de projetores vendidos como "HY300", "HY300 Pro", "HY300 Pro+", "HY300-T", com **placas e chips diferentes**. Este guia só vale para o **HY300-T com Amlogic T950S (placa `t950s_be311`)**.

## Pela tela do projetor

Em **Configurações → Sobre** (às vezes "Sobre nós"):

- **Modelo do produto:** `HY300-T`
- **Versão Android:** `13.0`
- **Número da versão:** começa com **`be311-13`** (ex.: `be311-13 TQ2A.230405.003.E1-...`)

O prefixo `be311` é o sinal mais confiável.

## Pelo ADB (depois do passo 1)

```bat
platform-tools\adb.exe -s IP_DO_PROJETOR:5555 shell getprop ro.product.device
platform-tools\adb.exe -s IP_DO_PROJETOR:5555 shell getprop ro.board.platform
platform-tools\adb.exe -s IP_DO_PROJETOR:5555 shell getprop ro.hardware
```

Resultado esperado:

| Propriedade | Valor |
|---|---|
| `ro.product.device` | `t950s_be311` |
| `ro.board.platform` | `txhd2` |
| `ro.hardware` | `amlogic` |
| `ro.product.vendor.model` | `HY300-T` |
| `ro.product.cpu.abilist` | `armeabi-v7a,armeabi` (32 bits) |

> Curiosidade: `ro.product.model`/`brand` aparecem como **Google / Pixel 5 (redfin)** no fingerprint. É falso — o fabricante copia o fingerprint de um Pixel para "parecer" certificado. Não confie nisso para identificar o aparelho.

## Se o seu for diferente

| Se você vir… | Então é… | Este guia serve? |
|---|---|---|
| `sun50iw12p1`, `h713` | Allwinner H713 (HY300 Pro clássico) | **Não** (a parte de backup/limpeza ajuda como ideia, mas firmware e ferramentas são outras: PhoenixSuit) |
| `h726` | Allwinner H726 | **Não** |
| `rk3326`, `rk30sdk` | Rockchip | **Não** |
| `t950s_be311`, `txhd2`, `amlogic` | Amlogic T950S | **Sim** |
