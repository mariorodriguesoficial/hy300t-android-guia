<?php
// Manifesto do PWA do garçom. Dinâmico para respeitar BASE_URL (online: /rota403, local: raiz).
require_once __DIR__ . '/config/config.php';
header('Content-Type: application/manifest+json; charset=utf-8'); header('Cache-Control: no-cache');
$e = empresa(); $b = BASE_URL;
echo json_encode([
    'id' => $b . '/garcom',
    'name' => $e['nome'] . ' — Garçom', 'short_name' => 'Rota 403',
    'description' => 'Lançamento de pedidos pelo celular.',
    'lang' => 'pt-BR', 'dir' => 'ltr',
    'start_url' => $b . '/garcom', 'scope' => $b . '/',
    'display' => 'standalone', 'orientation' => 'portrait',
    'background_color' => '#f8f4ec', 'theme_color' => '#d9530f',
    'icons' => [
        ['src' => $b . '/assets/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $b . '/assets/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $b . '/assets/pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [['name' => 'Comandas abertas', 'url' => $b . '/garcom']],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
