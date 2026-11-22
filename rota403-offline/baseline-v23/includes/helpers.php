<?php
require_once __DIR__ . "/fotos_produtos.php";
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function brl($v): string { return 'R$ ' . number_format((float) $v, 2, ',', '.'); }
// Quantidade: inteiros como "2", pesos (kg) como "0,450 kg"
function qtdFmt($q, ?string $un = null): string {
    $q = (float) $q;
    if ($un === 'kg' || abs($q - round($q)) > 0.0001) return number_format($q, 3, ',', '.') . ' kg';
    return (string) (int) round($q);
}
// Unidade de venda do produto (un ou kg) e quantidades fracionadas — criado automaticamente
function garantirUnidade(): void
{
    static $ok = false;
    if ($ok) return;
    if (!Database::one("SHOW COLUMNS FROM produtos LIKE 'unidade'")) {
        Database::query("ALTER TABLE produtos ADD unidade VARCHAR(3) NOT NULL DEFAULT 'un'");
    }
    $c = Database::one("SHOW COLUMNS FROM comanda_itens LIKE 'quantidade'");
    if ($c && stripos($c['Type'], 'decimal') === false) Database::query("ALTER TABLE comanda_itens MODIFY quantidade DECIMAL(10,3) NOT NULL DEFAULT 1");
    $c = Database::one("SHOW COLUMNS FROM produtos LIKE 'estoque'");
    if ($c && stripos($c['Type'], 'decimal') === false) Database::query("ALTER TABLE produtos MODIFY estoque DECIMAL(10,3) NOT NULL DEFAULT 0");
    $ok = true;
}
function produtosLancador(): array
{
    garantirUnidade();
    return array_map(fn($p) => ['id' => (int) $p['id'], 'nome' => $p['nome'], 'preco' => (float) $p['preco'], 'codigo' => $p['codigo_barras'], 'categoria' => $p['categoria'], 'estoque' => (float) $p['estoque'], 'controlar' => (int) $p['controlar_estoque'], 'unidade' => $p['unidade'] ?? 'un', 'foto' => fotoProduto($p)], listarProdutos(true));
}
function dataHora($d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '-'; }
function url(string $p = ''): string { return BASE_URL . '/' . ltrim((string) preg_replace('/\.php(?=[?#]|$)/', '', $p), '/'); }
function redirect(string $p): never { header('Location: ' . url($p)); exit; }
function codigoComanda(int $id): string { return 'CMD' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
function nomeComanda(array $c): string { return ($c['tipo'] === 'mesa' ? 'Mesa ' : '') . $c['identificacao']; }
function erro_central(string $titulo, string $mensagem, int $codigo = 404): never
{
    http_response_code($codigo);
    die('<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($titulo) . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;background:#f8f4ec;font-family:Arial,sans-serif}.aviso{width:min(440px,calc(100vw - 32px));box-sizing:border-box;padding:22px;border-radius:10px;background:#d9530f;color:#fff;box-shadow:0 18px 48px rgba(31,24,19,.35);text-align:center}.aviso b{display:block;font-size:20px;margin-bottom:8px}</style></head><body><div class="aviso"><b>' . e($titulo) . '</b><span>' . e($mensagem) . '</span></div><script>setTimeout(function(){var aviso=document.querySelector(".aviso");if(aviso)aviso.remove();},2000);</script></body></html>');
}

function flash(?string $msg = null, string $tipo = 'success')
{
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $tipo]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function check_csrf(): void
{
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals(csrf_token(), (string) $t)) {
        if (isset($_SERVER['HTTP_X_CSRF'])) json_out(['erro' => 'Sessão expirada. Recarregue a página.'], 419);
        flash('Sessão expirada. Recarregue a página e tente novamente.', 'danger');
        redirect(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    }
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function listarProdutos(bool $apenasAtivos = false): array
{
    return Database::all('SELECT p.*, COALESCE(c.nome, "Outros") AS categoria, COALESCE(c.ordem, 99) AS cat_ordem
        FROM produtos p LEFT JOIN categorias c ON c.id = p.categoria_id ' . ($apenasAtivos ? 'WHERE p.ativo = 1 ' : '') .
        'ORDER BY cat_ordem, p.nome');
}

function caixaAberto(): array|false
{
    return Database::one("SELECT * FROM caixas WHERE status = 'aberto' ORDER BY id DESC LIMIT 1");
}

function recalcularTotal(int $comandaId): void
{
    Database::query('UPDATE comandas SET total = (SELECT COALESCE(SUM(subtotal),0) FROM comanda_itens WHERE comanda_id = ?) WHERE id = ?', [$comandaId, $comandaId]);
}

/**
 * Lança itens na comanda, baixa estoque e gera pedidos para cozinha/balcão.
 * $linhas = [['produto_id'=>int,'quantidade'=>int,'observacao'=>string], ...]
 */
function lancarItens(int $comandaId, array $linhas, string $garcom = '', bool $gerarPedidos = true): void
{
    $pdo = Database::pdo();
    // Criação/ajuste de tabelas ANTES da transação: no MySQL, CREATE/ALTER encerra a transação
    garantirPreparos();
    garantirColunaImpressa();
    garantirUnidade();
    // Produto por kg lançado pelo caixa/supervisor/admin é pesado e já pronto: entra na comanda, mas não vai para impressão
    $kgSemImpressao = (Auth::user()['nivel'] ?? 'garcom') !== 'garcom';
    // Pedidos lançados por caixa/supervisor/admin ficam registrados como já impressos (não saem na impressora); só o garçom imprime
    $pedidoImpresso = $kgSemImpressao ? 1 : 0;
    $pdo->beginTransaction();
    try {
        $c = Database::one("SELECT * FROM comandas WHERE id = ? AND status = 'aberta' FOR UPDATE", [$comandaId]);
        if (!$c) throw new RuntimeException('Comanda não encontrada ou fechada.');
        $porDestino = [];
        foreach ($linhas as $l) {
            $p = Database::one('SELECT * FROM produtos WHERE id = ? AND ativo = 1', [(int) $l['produto_id']]);
            if (!$p) continue;
            if (($p['unidade'] ?? 'un') === 'kg') {
                $qtd = round((float) str_replace(',', '.', (string) ($l['quantidade'] ?? 0)), 3);
                if ($qtd <= 0 || $qtd > 100) throw new RuntimeException('Peso inválido para ' . $p['nome'] . '.');
            } else {
                $qtd = max(1, (int) ($l['quantidade'] ?? 1));
            }
            $obs = trim((string) ($l['observacao'] ?? '')) ?: null;
            Database::query('INSERT INTO comanda_itens (comanda_id, produto_id, nome, quantidade, preco_unitario, subtotal, observacao, garcom) VALUES (?,?,?,?,?,?,?,?)',
                [$comandaId, $p['id'], $p['nome'], $qtd, $p['preco'], round($p['preco'] * $qtd, 2), $obs, $garcom ?: null]);
            if ($p['controlar_estoque']) Database::query('UPDATE produtos SET estoque = estoque - ? WHERE id = ?', [$qtd, $p['id']]);
            if ($kgSemImpressao && ($p['unidade'] ?? 'un') === 'kg') continue;
            $porDestino[$p['destino']][] = ['nome' => $p['nome'], 'quantidade' => $qtd, 'unidade' => $p['unidade'] ?? 'un', 'observacao' => $obs];
        }
        if (!$gerarPedidos) $porDestino = []; // lançado na frente de caixa: não envia pedido para cozinha/bar
        foreach ($porDestino as $destino => $itens) {
            if ($itens) Database::query('INSERT INTO pedidos (comanda_id, destino, itens, garcom, impresso) VALUES (?,?,?,?,?)',
                [$comandaId, $destino, json_encode($itens, JSON_UNESCAPED_UNICODE), $garcom ?: null, $pedidoImpresso]);
        }
        recalcularTotal($comandaId);
        if ($pdo->inTransaction()) $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $t;
    }
}

// Coluna que marca se a comanda já saiu na impressora térmica (criada automaticamente)
function garantirColunaImpressa(): void
{
    static $ok = false;
    if ($ok) return;
    if (!Database::one("SHOW COLUMNS FROM comandas LIKE 'impressa'")) {
        Database::query("ALTER TABLE comandas ADD impressa TINYINT(1) NOT NULL DEFAULT 1");
    }
    $ok = true;
}

// ===== Preparos (onde o pedido é impresso: cozinha, balcão, bar...) =====
function garantirPreparos(): void
{
    static $ok = false;
    if ($ok) return;
    Database::query("CREATE TABLE IF NOT EXISTS preparos (
        slug VARCHAR(30) NOT NULL PRIMARY KEY,
        nome VARCHAR(40) NOT NULL,
        ordem INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!(int) Database::val('SELECT COUNT(*) FROM preparos')) {
        Database::query("INSERT INTO preparos (slug, nome, ordem) VALUES ('cozinha','Cozinha',1),('balcao','Balcão',2)");
    }
    foreach (['produtos', 'pedidos'] as $t) {
        $col = Database::one("SHOW COLUMNS FROM $t LIKE 'destino'");
        if ($col && stripos($col['Type'], 'enum') === 0) {
            Database::query("ALTER TABLE $t MODIFY destino VARCHAR(30) NOT NULL DEFAULT 'cozinha'");
        }
    }
    $ok = true;
}

/** @return array<string,string> slug => nome */
function listarPreparos(): array
{
    garantirPreparos();
    $r = [];
    foreach (Database::all('SELECT slug, nome FROM preparos ORDER BY ordem, nome') as $p) $r[$p['slug']] = $p['nome'];
    return $r;
}

function nomePreparo(string $slug): string
{
    return listarPreparos()[$slug] ?? ucfirst($slug);
}

function slugPreparo(string $nome): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome) ?: $nome;
    $s = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');
    return substr($s ?: 'preparo', 0, 30);
}

// ===== Fila de impressão direta (conta / comprovante) — impressa pelo Monitor =====
function garantirFilaImpressao(): void
{
    static $ok = false;
    if ($ok) return;
    Database::query("CREATE TABLE IF NOT EXISTS fila_impressao (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(20) NOT NULL,
        ref_id INT NOT NULL,
        impresso TINYINT(1) NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pend (impresso, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
}

function enfileirarImpressao(string $tipo, int $refId): int
{
    garantirFilaImpressao();
    Database::query('INSERT INTO fila_impressao (tipo, ref_id) VALUES (?, ?)', [$tipo, $refId]);
    return (int) Database::one('SELECT LAST_INSERT_ID() id')['id'];
}

// ===== Configurações da empresa =====
function garantirTabelaConfig(): void
{
    static $ok = false;
    if ($ok) return;
    Database::query("CREATE TABLE IF NOT EXISTS configuracoes (chave VARCHAR(50) PRIMARY KEY, valor TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
}

const EMPRESA_CAMPOS = [
    'nome' => 'Nome da empresa (aparece na comanda)', 'slogan' => 'Frase / atividade', 'razao' => 'Razão social',
    'cnpj' => 'CNPJ', 'endereco' => 'Endereço completo', 'telefone' => 'Telefone(s)', 'whatsapp' => 'WhatsApp',
    'email' => 'E-mail', 'instagram' => 'Instagram', 'cidade' => 'Cidade', 'rodape' => 'Mensagem no rodapé dos cupons',
];

function empresa(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = array_fill_keys(array_keys(EMPRESA_CAMPOS), '');
    $cfg['nome'] = EMPRESA_NOME; $cfg['slogan'] = EMPRESA_SUB; $cfg['rodape'] = 'Obrigado pela preferência!'; $cfg['logo'] = '';
    try {
        garantirTabelaConfig();
        foreach (Database::all('SELECT chave, valor FROM configuracoes') as $r) $cfg[$r['chave']] = (string) $r['valor'];
    } catch (Throwable $t) { }
    // Usa sempre a logo mais recente da pasta uploads (logo padrão do pacote ou a enviada em Configurações)
    $logos = glob(BASE_PATH . '/uploads/logo.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
    if ($logos) { usort($logos, fn($a, $b) => filemtime($b) <=> filemtime($a)); $cfg['logo'] = 'uploads/' . basename($logos[0]); }
    return $cfg;
}

function salvarConfig(string $chave, string $valor): void
{
    garantirTabelaConfig();
    Database::query('INSERT INTO configuracoes (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$chave, $valor]);
}

// Logo embutida (base64) para sair na impressão térmica e no QZ Tray sem depender de carregamento
function logoDataUri(): string
{
    $l = empresa()['logo'];
    if ($l === '') return '';
    $f = BASE_PATH . '/' . $l;
    if (!is_file($f)) return '';
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($f));
}

// Cabeçalho padrão de todos os documentos impressos para o cliente
function cabecalhoCupom(bool $completo = true, string $destaque = '', bool $semNome = false): string
{
    $c = empresa();
    $h = '';
    if ($logo = logoDataUri()) $h .= '<div class="c"><img class="logo" src="' . $logo . '" alt=""></div>';
    if (!$semNome) $h .= '<h1>' . e($c['nome']) . '</h1>';
    if ($c['slogan'] !== '') $h .= '<div class="c">' . e($c['slogan']) . '</div>';
    if ($completo) {
        if ($destaque !== '') $h .= '<div class="c" style="font-size:22px;font-weight:900;margin:4px 0">' . e($destaque) . '</div>';
        elseif ($c['razao'] !== '') $h .= '<div class="c"><small>' . e($c['razao']) . '</small></div>';
        if ($c['cnpj'] !== '') $h .= '<div class="c"><small>CNPJ: ' . e($c['cnpj']) . '</small></div>';
        if ($c['endereco'] !== '') $h .= '<div class="c"><small>' . e($c['endereco']) . '</small></div>';
        $ct = array_filter([$c['telefone'] ? 'Tel: ' . $c['telefone'] : '', $c['whatsapp'] ? 'WhatsApp: ' . $c['whatsapp'] : '']);
        if ($ct) $h .= '<div class="c"><small>' . e(implode(' • ', $ct)) . '</small></div>';
        $red = array_filter([$c['instagram'], $c['email']]);
        if ($red) $h .= '<div class="c"><small>' . e(implode(' • ', $red)) . '</small></div>';
    }
    return $h . '<hr>';
}

// ===== Pagamentos múltiplos =====
function garantirPagamentos(): void
{
    static $ok = false;
    if ($ok) return;
    Database::query("CREATE TABLE IF NOT EXISTS comanda_pagamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        comanda_id INT NOT NULL,
        forma VARCHAR(40) NOT NULL,
        valor DECIMAL(10,2) NOT NULL,
        recebido DECIMAL(10,2) NOT NULL DEFAULT 0,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (comanda_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!Database::one("SHOW COLUMNS FROM comandas LIKE 'troco'")) {
        Database::query("ALTER TABLE comandas ADD troco DECIMAL(10,2) NOT NULL DEFAULT 0");
    }
    $ok = true;
}

/** Soma dos pagamentos já feitos (parciais) de uma comanda ainda aberta. */
function valorPagoParcial(int $comandaId): float
{
    garantirPagamentos();
    return round((float) Database::val('SELECT COALESCE(SUM(valor),0) FROM comanda_pagamentos WHERE comanda_id = ?', [$comandaId]), 2);
}

/** Soma por forma de pagamento das comandas fechadas que atendem $where (alias c). Retorna [forma => [n, t]]. */
function vendasPorForma(string $where, array $params): array
{
    garantirPagamentos();
    $sql = "SELECT forma f, COUNT(DISTINCT cid) n, SUM(v) t FROM (
        SELECT p.forma, p.valor v, c.id cid FROM comanda_pagamentos p JOIN comandas c ON c.id = p.comanda_id WHERE c.status='fechada' AND $where
        UNION ALL
        SELECT c.forma_pagamento, c.total, c.id FROM comandas c WHERE c.status='fechada' AND $where
          AND NOT EXISTS (SELECT 1 FROM comanda_pagamentos p2 WHERE p2.comanda_id = c.id)
    ) x GROUP BY forma ORDER BY t DESC";
    return Database::all($sql, array_merge($params, $params));
}

/** Condições de pagamento cadastradas em Configurações (tabela criada automaticamente). */
function garantirFormasPagamento(): void
{
    static $ok = false;
    if ($ok) return;
    Database::query("CREATE TABLE IF NOT EXISTS formas_pagamento (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(40) NOT NULL UNIQUE,
        ordem INT NOT NULL DEFAULT 0,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!(int) Database::val('SELECT COUNT(*) FROM formas_pagamento')) {
        foreach (['Dinheiro', 'PIX', 'Cartão de débito', 'Cartão de crédito', 'Voucher', 'Crédito loja'] as $i => $n)
            Database::query('INSERT IGNORE INTO formas_pagamento (nome, ordem) VALUES (?,?)', [$n, $i + 1]);
    }
    $ok = true;
}

function formasPagamento(): array
{
    garantirFormasPagamento();
    return array_column(Database::all('SELECT nome FROM formas_pagamento ORDER BY ordem, id'), 'nome');
}
