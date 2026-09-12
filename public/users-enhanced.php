<?php
declare(strict_types=1);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/users'), PHP_URL_PATH) ?: '/users'));

if (!in_array($path, ['/users','/users/'], true)) {
    http_response_code(404);
    exit;
}

if (!in_array($method, ['GET','HEAD'], true)) {
    require __DIR__.'/index.php';
    exit;
}

ob_start(static function (string $html): string {
    // Admin keeps the historical one-use referral flow. Only the Owner page
    // receives the multi-registration selector and management shortcut.
    if (!str_contains($html, 'Owner can create Admin, Reseller and User referrals.')) {
        return $html;
    }

    $html = str_replace(
        '<h3>One-time registration invite</h3>',
        '<h3>Registration referral</h3>',
        $html
    );

    $limitField = '<div class="field"><label>Maximum registrations</label>'
        .'<select name="max_registrations" required>'
        .'<option value="1">1 user</option>'
        .'<option value="2">2 users</option>'
        .'<option value="10">10 users</option>'
        .'<option value="100">100 users</option>'
        .'<option value="1000">1000 users</option>'
        .'</select><p class="hint">Owner-only control. The same referral remains valid until this many successful registrations are completed, or until it expires/revokes.</p></div>';

    $pattern = '#(<form method="post" action="/referrals/create" class="stack">.*?)(<button class="primary wide">Create referral</button>)#s';
    $replacement = '$1'.$limitField.'$2'
        .'<a class="ghost wide" href="/owner/referral-limits">Manage referral limits</a>';
    $updated = preg_replace($pattern, $replacement, $html, 1);

    return is_string($updated) ? $updated : $html;
});

require __DIR__.'/index.php';

if (ob_get_level() > 0) {
    ob_end_flush();
}
