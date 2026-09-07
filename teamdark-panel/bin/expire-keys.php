<?php
declare(strict_types=1);

use TeamDark\Panel\{Config,Database,LicenseService};

$root = dirname(__DIR__);

require $root.'/app/Config.php';
require $root.'/app/Database.php';
require $root.'/app/LicenseService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

Config::load($root);
LicenseService::expireDue();

echo "TeamDark expired-key sweep completed.\n";
