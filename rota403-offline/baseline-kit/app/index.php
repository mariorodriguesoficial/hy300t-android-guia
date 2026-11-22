<?php
require_once __DIR__ . '/config/config.php';
$u = Auth::exigir();
redirect($u['nivel'] === 'garcom' ? 'garcom.php' : 'comandas.php');
