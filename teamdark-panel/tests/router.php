<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($path === '/connect' || $path === '/connect/') {
    require $root.'/public/connect.php';
    return true;
}

$publicFile = $root.'/public'.$path;
if ($path !== '/' && is_file($publicFile)) {
    return false;
}

require $root.'/public/index.php';
return true;
