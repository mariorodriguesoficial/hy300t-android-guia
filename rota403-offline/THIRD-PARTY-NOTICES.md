# Avisos de terceiros

Bibliotecas servidas localmente em `app/assets/vendor/` (versões fixas, sem CDN):

| Componente | Versão | Licença | Origem |
|---|---|---|---|
| Bootstrap (CSS/JS) | 5.3.3 | MIT | npm `bootstrap` |
| Bootstrap Icons | 1.11.3 | MIT | npm `bootstrap-icons` |
| JsBarcode | 3.11.6 | MIT | npm `jsbarcode` (sem arquivo LICENSE no pacote; MIT declarado em package.json) |
| qz-tray.js | 2.2.4 | LGPL-2.1 | npm `qz-tray` |
| html5-qrcode | 2.3.8 | Apache-2.0 | npm `html5-qrcode` |
| Barlow (latin 400/500/600/700) | 5.3.0 | SIL OFL 1.1 | npm `@fontsource/barlow` |
| Bebas Neue (latin 400) | 5.3.0 | SIL OFL 1.1 | npm `@fontsource/bebas-neue` |

Textos de licença ficam ao lado de cada biblioteca quando fornecidos pelo pacote.
Os arquivos `app/assets/produtos/*.jpg` são imagens do projeto original; **a origem/licença delas não foi verificada** — confirmar com o proprietário.
PHP, MariaDB, Inno Setup e WebView2 serão acrescentados quando empacotados.

Previsto para empacotamento (Fase 1, ainda não incluído no repositório): **Caddy** 2.x (Apache-2.0) como proxy HTTPS da LAN — testado com o pacote Ubuntu 2.6.2.
