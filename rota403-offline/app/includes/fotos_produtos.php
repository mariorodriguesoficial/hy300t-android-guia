<?php
// Fotos ilustrativas locais; fotos enviadas pelo administrador têm prioridade.
function fotoProduto(array $p): string
{
    $id = (int) ($p['id'] ?? 0);
    foreach (['jpg', 'png', 'webp'] as $ext) {
        $rel = 'uploads/produtos/produto-' . $id . '.' . $ext;
        if ($id > 0 && is_file(BASE_PATH . '/' . $rel)) return url($rel) . '?v=' . filemtime(BASE_PATH . '/' . $rel);
    }
    $n = mb_strtoupper((string) ($p['nome'] ?? ''), 'UTF-8');
    $n = strtr($n, ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C']);
    $regras = [
        'PASTEL|BOMBA DA|FRANGO MILHO|FRANGO COM CHOCOLATE' => 'pastel',
        'MARMITA' => 'marmita', 'PRATO' => 'prato', 'SALADA' => 'salada',
        'BATATA.*BAC' => 'batata-bacon', 'BATATA' => 'batata', 'PASSARINHO' => 'frango',
        'STROGONO.*CARNE' => 'strogonofe-carne', 'STROGONO' => 'strogonofe-frango',
        'CARRETEIRO|PANELINHA' => 'panelinha', 'ARROZ' => 'arroz', 'MACARRAO' => 'macarrao', 'LASANHA' => 'lasanha',
        'MOU[S]?SE.*MORANGO' => 'morango', 'MOU[S]?SE.*LIMAO' => 'limao', 'MOU[S]?SE.*ABACAXI' => 'abacaxi', 'MOU[S]?SE' => 'maracuja',
        'CUPUACU' => 'cupuacu', 'CREME.*MORANGO' => 'creme-morango', 'ACAI' => 'acai',
        'CACHORRO' => 'hotdog', 'PUDIM' => 'pudim', 'PAVE' => 'pave',
        'COPO SUCO' => 'copo', 'SUCO.*ABACAXI.*JARRA' => 'abacaxi-jarra', 'SUCO.*ABACAXI' => 'abacaxi-copo',
        'SUCO.*JARRA' => 'laranja-jarra', 'SUCO' => 'laranja-copo', 'LAFRUIT' => 'suco-caixa',
        'AGUA' => 'agua',
        'HEINEKEN.*LONG' => 'long-neck-verde', 'HEINEKEN' => 'cerveja-verde-600',
        '\\bSOL\\b|LONG NECK' => 'long-neck-clara', '(ANTARC?TICA|BRAHMA|SKOL|CERVEJA).*600' => 'cerveja-garrafa',
        'REFRI.*LATA' => 'refri-lata', 'REFRI.*KS' => 'refri-ks', 'REFRI.*1 ?L' => 'refri-1l', 'REFRI.*600' => 'refri-600',
        'MINEIRO|GUARANA.*2 ?L' => 'guarana-2l', 'CERVEJA|BRAHMA|SKOL' => 'cerveja-lata',
        'ENER.*MANGO' => 'energetico-manga', 'ENER' => 'energetico',
        'H2O|H2OH|SODA' => 'h2o', 'SUKITA.*UVA' => 'uva-lata', 'SUKITA|FANTA|TAMPICO' => 'laranja-garrafa',
        '(COCA|PEPSI).*?(ZERO|BLACK).*?(LATA|269|350|355)' => 'zero-lata',
        '(COCA|PEPSI).*?(ZERO|BLACK)' => 'zero-garrafa',
        '(COCA|PEPSI).*?(LATA|269|350|355)' => 'cola-lata', 'COCA|PEPSI' => 'cola-garrafa',
        'GUARANA.*?(LATA|350)' => 'guarana-lata', 'GUARANA|BARE' => 'guarana-garrafa',
        'MOEDAS' => 'moedas', 'NUTRY' => 'cereal', 'FIGURINHA' => 'figurinha', 'OLEO' => 'oleo',
        'TRENTO' => 'wafer', 'BANAN' => 'banana', 'PACO' => 'pacoca', 'CROCANTE' => 'pe-crocante',
        'LEITE.*(TABLETE|REAL|CASADINHO)|DUCREM' => 'doce-leite', 'COCADA' => 'cocada',
        'QUEIJADINHA' => 'queijadinha', 'BRIGADEIRO' => 'brigadeiro', 'BOM[B]?OM|TRUFAS' => 'bombom',
        'PIRULITO|FLOPITO' => 'pirulito', 'FINI|DOCIGOMA' => 'goma',
        'TRIDENT|MENTOS|HALLS|ICEKISS' => 'menta', 'GOMA|CHIC|BUBBA|PLUTONITA|HAPPY' => 'chiclete',
        'BALA' => 'bala', 'BRANCO' => 'chocolate-branco', 'BATON|CHOCOLATE|PRESTIGIO|BEIJO|TORTUGUITA' => 'chocolate'
    ];
    foreach ($regras as $regex => $foto) {
        if (preg_match('~' . $regex . '~u', $n)) return url('assets/produtos/' . $foto . '.jpg');
    }
    return url('assets/produtos/prato.jpg');
}

function salvarFotoProduto(int $id): void
{
    $file = $_FILES['foto'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível enviar a foto. Tente novamente.');
    if ($file['size'] > 2 * 1024 * 1024) throw new RuntimeException('A foto deve ter no máximo 2 MB.');
    $info = @getimagesize($file['tmp_name']);
    $ext = $info ? (['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']] ?? null) : null;
    if (!$ext || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Envie uma foto JPG, PNG ou WEBP válida.');
    $dir = BASE_PATH . '/uploads/produtos';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Não foi possível criar a pasta de fotos.');
    if (!move_uploaded_file($file['tmp_name'], $dir . '/produto-' . $id . '.' . $ext)) throw new RuntimeException('Não foi possível salvar a foto. Verifique a permissão da pasta uploads/produtos.');
    foreach (['jpg', 'png', 'webp'] as $old) if ($old !== $ext && is_file($dir . '/produto-' . $id . '.' . $old)) unlink($dir . '/produto-' . $id . '.' . $old);
}
