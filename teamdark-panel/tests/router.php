<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = '/'.ltrim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');

if ($path === '/connect' || $path === '/connect/') {
    require $root.'/public/connect.php';
    return true;
}

if ($path === '/telegram/webhook' || $path === '/telegram/webhook/') {
    require $root.'/public/telegram-webhook.php';
    return true;
}

if ($path === '/register' || $path === '/register/') {
    require $root.'/public/register-relaxed.php';
    return true;
}

if (in_array($path, ['/security/password','/security/password/','/users/password','/users/password/'], true)) {
    require $root.'/public/password-actions.php';
    return true;
}

if ($path === '/key-edit' || $path === '/key-edit/') {
    require $root.'/public/key-edit.php';
    return true;
}

if ($path === '/files/download' || $path === '/files/download/') {
    require $root.'/public/file-download.php';
    return true;
}

if ($path === '/files' || $path === '/files/' || str_starts_with($path, '/files/')) {
    require $root.'/public/vault.php';
    return true;
}

$publicFile = $root.'/public'.$path;
if ($path !== '/' && is_file($publicFile)) {
    return false;
}

require $root.'/public/index.php';
return true;
