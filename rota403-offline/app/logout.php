<?php
require_once __DIR__ . '/config/config.php';
if (Auth::user()) seg_log((int) Auth::user()['id'], 'logout');
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
session_destroy();
redirect('login.php');
