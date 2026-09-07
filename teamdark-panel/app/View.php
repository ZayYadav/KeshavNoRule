<?php
declare(strict_types=1);

namespace TeamDark\Panel;

final class View
{
    public static function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    public static function page(string $title, string $body, ?array $user = null): void
    {
        $app = self::e(Config::get('app_name'));
        $title = self::e($title);
        $nav = '';
        if ($user) {
            $nav = '<nav><a href="/dashboard">Dashboard</a><a href="/keys">Keys</a>'
                . (in_array($user['role'], ['owner','admin'], true) ? '<a href="/users">Users & Referrals</a>' : '')
                . ($user['role'] === 'owner' ? '<a href="/telegram-users">TG Users</a>' : '')
                . '<span class="spacer"></span><span class="pill">'.self::e(strtoupper($user['role'])).'</span>'
                . '<form method="post" action="/logout" class="inline"><input type="hidden" name="csrf" value="'.self::e(Security::csrfToken()).'"><button class="ghost">Logout</button></form></nav>';
        }
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>'.$title.' • '.$app.'</title><link rel="stylesheet" href="/assets/app.css"></head><body>'
            . '<div class="shell"><header><div class="brand"><span class="dot"></span><strong>'.$app.'</strong></div>'.$nav.'</header>'
            . '<main>'.$body.'</main><footer>TeamDark secure control plane</footer></div><script src="/assets/app.js" defer></script></body></html>';
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="'.self::e(Security::csrfToken()).'">';
    }
}
