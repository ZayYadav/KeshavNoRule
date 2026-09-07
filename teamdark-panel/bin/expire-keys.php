<?php
declare(strict_types=1);

use TeamDark\Panel\{Config,Database,KeyManager};

$root = dirname(__DIR__);

require $root.'/app/Config.php';
require $root.'/app/Database.php';
require $root.'/app/Security.php';
require $root.'/app/Crypto.php';
require $root.'/app/KeyManager.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

Config::load($root);
KeyManager::expireDue();

echo "TeamDark expired-key sweep completed.\n";
