<?php
/**
 * 003 — Repara textos acentuados do seed (formas de pagamento) que o instalador antigo gravou como "?"
 * (o PowerShell 5.1 convertia o SQL para ASCII ao enviar para o mariadb). Idempotente; não toca dados corretos.
 */
return ['nome' => 'corrige_acentos_seed', 'up' => [
    "UPDATE formas_pagamento SET nome = 'Cartão de débito'  WHERE nome = 'Cart?o de d?bito'  AND NOT EXISTS (SELECT 1 FROM (SELECT nome FROM formas_pagamento) x WHERE x.nome = 'Cartão de débito')",
    "UPDATE formas_pagamento SET nome = 'Cartão de crédito' WHERE nome = 'Cart?o de cr?dito' AND NOT EXISTS (SELECT 1 FROM (SELECT nome FROM formas_pagamento) x WHERE x.nome = 'Cartão de crédito')",
]];
