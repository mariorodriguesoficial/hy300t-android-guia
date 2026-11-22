<?php
/**
 * Especificação única do que sincroniza (usada pela migração, pelo emissor e pelo aplicador).
 *  cols  : colunas de negócio — mudança nelas incrementa rev e gera evento
 *  extra : enviadas mas não comparadas (imutáveis/derivadas)
 *  fk    : coluna local => tabela pai (trafega como <coluna>_uuid, nunca como id)
 *  modo  : upsert | imutavel (só inserção; nunca atualiza/exclui) | ledger
 *  ord   : ordem de dependência para lotes de envio
 *  local : colunas só locais (nunca trafegam, não geram rev)
 * Fora do escopo por política: usuarios, configuracoes, preparos, login_tentativas, log_acesso, fila_impressao.
 */
return [
    'categorias'         => ['ord' => 1, 'modo' => 'upsert',  'cols' => ['nome', 'ordem'], 'extra' => [], 'fk' => [], 'natural' => 'nome'],
    'formas_pagamento'   => ['ord' => 2, 'modo' => 'upsert',  'cols' => ['nome', 'ordem'], 'extra' => ['criado_em'], 'fk' => [], 'natural' => 'nome'],
    'produtos'           => ['ord' => 3, 'modo' => 'upsert',  'cols' => ['codigo_barras', 'nome', 'preco', 'categoria_id', 'controlar_estoque', 'ativo', 'imprimir_na_comanda', 'destino', 'unidade'],
                             'extra' => ['criado_em'], 'fk' => ['categoria_id' => 'categorias'], 'natural' => 'codigo_barras'],
    'caixas'             => ['ord' => 4, 'modo' => 'upsert',  'cols' => ['saldo_inicial', 'saldo_final', 'fechado_em', 'status'], 'extra' => ['aberto_em'], 'fk' => []],
    'comandas'           => ['ord' => 5, 'modo' => 'upsert',  'cols' => ['codigo', 'identificacao', 'tipo', 'status', 'forma_pagamento', 'caixa_id', 'fechado_em', 'troco'],
                             'extra' => ['criado_em'], 'fk' => ['caixa_id' => 'caixas'], 'local' => ['impressa', 'total']],
    'comanda_itens'      => ['ord' => 6, 'modo' => 'upsert',  'cols' => ['comanda_id', 'produto_id', 'nome', 'quantidade', 'preco_unitario', 'subtotal', 'observacao', 'garcom'],
                             'extra' => ['adicionado_em'], 'fk' => ['comanda_id' => 'comandas', 'produto_id' => 'produtos']],
    'pedidos'            => ['ord' => 7, 'modo' => 'upsert',  'cols' => ['comanda_id', 'destino', 'itens', 'garcom'], 'extra' => ['criado_em'], 'fk' => ['comanda_id' => 'comandas'], 'local' => ['impresso']],
    'comanda_pagamentos' => ['ord' => 8, 'modo' => 'imutavel', 'cols' => ['comanda_id', 'forma', 'valor', 'recebido'], 'extra' => ['criado_em'], 'fk' => ['comanda_id' => 'comandas']],
    'estoque_movimentos' => ['ord' => 9, 'modo' => 'ledger', 'cols' => [], 'extra' => [], 'fk' => []],
];
