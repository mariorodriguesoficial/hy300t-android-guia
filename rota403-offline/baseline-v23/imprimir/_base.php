<?php
require_once __DIR__ . '/../config/config.php';
Auth::exigir(['caixa', 'garcom']);

function cupom_inicio(string $titulo): void
{ ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title><?= e($titulo) ?></title>
<style>
  @page { size: 80mm auto; margin: 2mm; }
  * { box-sizing: border-box; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  html, body { background: #fff; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 600; line-height: 1.28; width: 76mm; margin: 0; color: #000; }
  h1 { font-size: 20px; text-align: center; margin: 0; }
  .c { text-align: center; } .b { font-weight: 800; } .r { text-align: right; } .big { font-size: 17px; }
  small { font-size: 11px; font-weight: 700; }
  hr { border: 0; border-top: 2px dashed #000; margin: 6px 0; }
  .cat { font-weight: 800; margin-top: 6px; text-transform: uppercase; border-bottom: 2px solid #000; }
  .row { display: flex; align-items: center; gap: 3px; padding: 3px 0; }
  .row .n { flex: 1; } .box { width: 13px; height: 13px; border: 2px solid #000; display: inline-block; flex: none; }
  table { width: 100%; border-collapse: collapse; } td { padding: 2px 0; vertical-align: top; font-weight: 600; }
  td.r, .r td, td.v { white-space: nowrap; padding-left: 6px; }
  .itens td { border-bottom: 1px dotted #000; padding: 3px 0; }
  .itens td.q { width: 1%; white-space: nowrap; padding-right: 5px; }
  .itens td.d { word-break: break-word; }
  .itens th { font-size: 11px; font-weight: 800; text-align: left; border-bottom: 2px solid #000; padding-bottom: 2px; }
  .itens th.r { text-align: right; }
  svg { max-width: 100%; }
  img.logo { display:block; margin:0 auto 4px; max-width:46mm; max-height:28mm; filter: grayscale(1) contrast(1.4); }
  svg[data-code] { display:block; margin:0 auto; background:#fff; }
  svg[data-code] > rect { fill:#fff !important; }
  svg[data-code] g rect { fill:#000 !important; }
  svg[data-code] text { fill:#000 !important; }
  @media print {
    * { color: #000 !important; text-shadow: none !important; opacity: 1 !important; }
    body { font-weight: 600; }
  }
</style>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
</head><body><?php }

function cupom_fim(): void
{ ?><script>
  window.addEventListener('load', function () {
    document.querySelectorAll('svg[data-code]').forEach(function (s) {
      JsBarcode(s, s.dataset.code, { format: 'CODE128', width: 2, height: 70, fontSize: 16, margin: 12, background: '#ffffff', lineColor: '#000000' });
    });
    if (/[?&]qz=1/.test(location.search) && window.parent !== window) {
      // Modo QZ Tray: troca o código de barras por imagem e envia o HTML ao monitor
      document.querySelectorAll('svg[data-code]').forEach(function (s) {
        var xml = new XMLSerializer().serializeToString(s);
        var img = new Image(); img.src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(xml)));
        var cv = document.createElement('canvas'); var w = s.width.baseVal.value, h = s.height.baseVal.value;
        cv.width = w * 2; cv.height = h * 2;
        s.setAttribute('data-w', w); s.setAttribute('data-h', h); s._img = img; s._cv = cv;
        s._pronto = new Promise(function (ok) { if (img.complete) ok(); else { img.onload = ok; img.onerror = ok; } });
      });
      // Espera as imagens do código de barras carregarem (antes saía em branco ou atrasava minimizado)
      Promise.all(Array.prototype.map.call(document.querySelectorAll('svg[data-code]'), function (s) { return s._pronto; })).then(function () {
        document.querySelectorAll('svg[data-code]').forEach(function (s) {
          var cx = s._cv.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, s._cv.width, s._cv.height);
          cx.drawImage(s._img, 0, 0, s._cv.width, s._cv.height);
          var out = document.createElement('img'); out.src = s._cv.toDataURL('image/png');
          out.style.cssText = 'display:block;margin:0 auto;width:' + s.getAttribute('data-w') + 'px;height:' + s.getAttribute('data-h') + 'px';
          s.parentNode.replaceChild(out, s);
        });
        document.querySelectorAll('script').forEach(function (x) { x.remove(); });
        window.parent.postMessage({ tipo: 'ROTA403_QZ_HTML', html: '<!doctype html>' + document.documentElement.outerHTML }, window.location.origin);
      });
      return;
    }
    window.print();
    if (window.parent !== window) window.parent.postMessage('ROTA403_PRINT_READY', window.location.origin);
  });
</script></body></html><?php }
