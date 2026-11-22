<?php
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function brl($v): string { return 'R$ ' . number_format((float) $v, 2, ',', '.'); }
function dataHora($d): string { return $d ? date('d/m/Y H:i', strtotime($d)) : '-'; }
function url(string $p = ''): string { return BASE_URL . '/' . ltrim($p, '/'); }
function redirect(string $p): never { header('Location: ' . url($p)); exit; }
function codigoComanda(int $id): string { return 'CMD' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
function nomeComanda(array $c): string { return ($c['tipo'] === 'mesa' ? 'Mesa ' : '') . $c['identificacao']; }
function erro_central(string $titulo, string $mensagem, int $codigo = 404): never
{
    http_response_code($codigo);
    die('<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($titulo) . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;background:#f8f4ec;font-family:Arial,sans-serif}.aviso{width:min(440px,calc(100vw - 32px));box-sizing:border-box;padding:22px;border-radius:10px;background:#d9530f;color:#fff;box-shadow:0 18px 48px rgba(31,24,19,.35);text-align:center}.aviso b{display:block;font-size:20px;margin-bottom:8px}</style></head><body><div class="aviso"><b>' . e($titulo) . '</b><span>' . e($mensagem) . '</span></div></body></html>');
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
function lancarItens(int $comandaId, array $linhas, string $garcom = ''): void
{
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        $c = Database::one("SELECT * FROM comandas WHERE id = ? AND status = 'aberta' FOR UPDATE", [$comandaId]);
        if (!$c) throw new RuntimeException('Comanda não encontrada ou fechada.');
        $porDestino = ['cozinha' => [], 'balcao' => []];
        foreach ($linhas as $l) {
            $qtd = max(1, (int) ($l['quantidade'] ?? 1));
            $p = Database::one('SELECT * FROM produtos WHERE id = ? AND ativo = 1', [(int) $l['produto_id']]);
            if (!$p) continue;
            $obs = trim((string) ($l['observacao'] ?? '')) ?: null;
            Database::query('INSERT INTO comanda_itens (comanda_id, produto_id, nome, quantidade, preco_unitario, subtotal, observacao, garcom) VALUES (?,?,?,?,?,?,?,?)',
                [$comandaId, $p['id'], $p['nome'], $qtd, $p['preco'], $p['preco'] * $qtd, $obs, $garcom ?: null]);
            if ($p['controlar_estoque']) Database::query('UPDATE produtos SET estoque = estoque - ? WHERE id = ?', [$qtd, $p['id']]);
            $porDestino[$p['destino']][] = ['nome' => $p['nome'], 'quantidade' => $qtd, 'observacao' => $obs];
        }
        foreach ($porDestino as $destino => $itens) {
            if ($itens) Database::query('INSERT INTO pedidos (comanda_id, destino, itens, garcom) VALUES (?,?,?,?)',
                [$comandaId, $destino, json_encode($itens, JSON_UNESCAPED_UNICODE), $garcom ?: null]);
        }
        recalcularTotal($comandaId);
        $pdo->commit();
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
function cabecalhoCupom(bool $completo = true): string
{
    $c = empresa();
    $h = '';
    if ($logo = logoDataUri()) $h .= '<div class="c"><img class="logo" src="' . $logo . '" alt=""></div>';
    $h .= '<h1>' . e($c['nome']) . '</h1>';
    if ($c['slogan'] !== '') $h .= '<div class="c">' . e($c['slogan']) . '</div>';
    if ($completo) {
        if ($c['razao'] !== '') $h .= '<div class="c"><small>' . e($c['razao']) . '</small></div>';
        if ($c['cnpj'] !== '') $h .= '<div class="c"><small>CNPJ: ' . e($c['cnpj']) . '</small></div>';
        if ($c['endereco'] !== '') $h .= '<div class="c"><small>' . e($c['endereco']) . '</small></div>';
        $ct = array_filter([$c['telefone'] ? 'Tel: ' . $c['telefone'] : '', $c['whatsapp'] ? 'WhatsApp: ' . $c['whatsapp'] : '']);
        if ($ct) $h .= '<div class="c"><small>' . e(implode(' • ', $ct)) . '</small></div>';
        $red = array_filter([$c['instagram'], $c['email']]);
        if ($red) $h .= '<div class="c"><small>' . e(implode(' • ', $red)) . '</small></div>';
    }
    return $h . '<hr>';
}
