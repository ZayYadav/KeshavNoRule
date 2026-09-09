<?php
declare(strict_types=1);

namespace TeamDark\Panel;

final class View
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars(
            (string)$v,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    private static function icon(string $name): string
    {
        $paths = [
            'dashboard'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
            'keys'=>'<circle cx="8" cy="15" r="4"/><path d="m11 12 8.5-8.5M15 8l3 3M17 6l2 2"/>',
            'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'telegram'=>'<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
            'activity'=>'<path d="M3 12h4l3-8 4 16 3-8h4"/>',
            'logout'=>'<path d="M10 17l5-5-5-5M15 12H3"/><path d="M21 19V5a2 2 0 0 0-2-2h-6"/>',
            'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
            'chevron'=>'<path d="m9 18 6-6-6-6"/>',
            'spark'=>'<path d="m12 3-1.6 4.4L6 9l4.4 1.6L12 15l1.6-4.4L18 9l-4.4-1.6Z"/><path d="m5 16-.8 2.2L2 19l2.2.8L5 22l.8-2.2L8 19l-2.2-.8Z"/>',
        ];

        return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            .($paths[$name] ?? $paths['spark']).'</svg>';
    }

    private static function navLink(
        string $href,
        string $label,
        string $icon,
        string $path
    ): string {
        $active = $path === $href
            || ($href === '/keys' && str_starts_with($path, '/keys'))
            || ($href === '/users' && str_starts_with($path, '/users'));

        return '<a href="'.$href.'"'.($active ? ' class="active" aria-current="page"' : '').'>'
            .self::icon($icon).'<span>'.$label.'</span>'
            .($active ? '<i></i>' : '').'</a>';
    }

    public static function page(string $title, string $body, ?array $user = null): void
    {
        $app = self::e(Config::get('app_name'));
        $safeTitle = self::e($title);
        $path = (string)(
            parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)
            ?: '/'
        );
        $csrf = self::e(Security::csrfToken());

        $head = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            .'<title>'.$safeTitle.' • '.$app.'</title>'
            .'<link rel="stylesheet" href="/assets/app.css?v=20260909-3">'
            .'<meta name="theme-color" content="#05070b">'
            .'<meta name="color-scheme" content="dark"></head>';

        if (!$user) {
            echo $head.'<body data-teamdark-ui="3" class="guest-body">'
                .'<div class="ambient ambient-one"></div><div class="ambient ambient-two"></div>'
                .'<main class="guest-main"><a class="guest-brand" href="/" aria-label="'.$app.' home">'
                .'<span class="brand-mark"><b>TD</b><i></i></span>'
                .'<span><strong>'.$app.'</strong><small>Secure control plane</small></span></a>'
                .(in_array($path, ['/login','/register'], true)
                    ? '<div class="auth-layout"><aside class="auth-intro"><div class="eyebrow">TEAM DARK / ACCESS</div><h2>Your network.<br>Your control.</h2><p>Manage licenses, users and access from one secure workspace.</p><div class="auth-capabilities"><span>01 <strong>License management</strong></span><span>02 <strong>Account controls</strong></span><span>03 <strong>Activity visibility</strong></span></div></aside>'.$body.'</div>'
                    : $body).'</main><script src="/assets/app.js?v=20260909-3" defer></script></body></html>';
            return;
        }

        $displayName = trim((string)($user['name'] ?? '')) ?: (string)$user['username'];
        $initial = strtoupper(substr($displayName, 0, 1));
        $role = strtoupper((string)$user['role']);

        $nav = '<div class="nav-group"><span class="nav-label">Workspace</span>'
            .self::navLink('/dashboard', 'Overview', 'dashboard', $path)
            .self::navLink('/keys', 'License keys', 'keys', $path)
            .'</div>';

        if (in_array($user['role'], ['owner','admin'], true)) {
            $nav .= '<div class="nav-group"><span class="nav-label">Management</span>'
                .self::navLink('/users', 'Users & invites', 'users', $path);
            if ($user['role'] === 'owner') {
                $nav .= self::navLink('/telegram-users', 'Telegram guests', 'telegram', $path)
                    .self::navLink('/activity', 'All activity', 'activity', $path)
                    .self::navLink('/owner/users', 'User insights', 'users', $path)
                    .self::navLink('/owner/settings', 'Server controls', 'dashboard', $path);
            }
            $nav .= '</div>';
        }

        $settings = PanelControl::settings();
        $notice = $settings['announcement'] !== '' ? '<div class="announcement" role="status">'.self::e($settings['announcement']).'</div>' : '';
        if (!$settings['panel_online']) $notice .= '<div class="alert">Panel is OFF for non-owner users. <a href="/owner/settings">Server controls</a></div>';
        echo $head.'<body data-teamdark-ui="3">'
            .'<div class="ambient ambient-one"></div><div class="ambient ambient-two"></div>'
            .'<div class="app-layout"><aside class="sidebar" id="app-sidebar" aria-label="Primary navigation">'
            .'<a class="sidebar-brand" href="/dashboard"><span class="brand-mark"><b>TD</b><i></i></span>'
            .'<span><strong>'.$app.'</strong><small>'.self::e(ucfirst($user['role'])).' console</small></span></a>'
            .'<nav>'.$nav.'</nav>'
            .'<div class="sidebar-foot"><div class="system-state"><span></span><div><strong>Panel '.($settings['panel_online'] ? 'online' : 'offline').'</strong><small>Signed in as '.self::e($user['role']).'</small></div></div>'
            .'<form method="post" action="/logout" data-action="Signing out" data-busy="Closing secure session…">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'"><button class="nav-logout" type="submit">'
            .self::icon('logout').'<span>Sign out</span></button></form></div></aside>'
            .'<button class="sidebar-scrim" type="button" data-sidebar-close aria-label="Close navigation"></button>'
            .'<div class="workspace"><header class="topbar"><div class="topbar-left">'
            .'<button class="mobile-menu" type="button" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="false">'
            .self::icon('menu').'<span>Menu</span></button><div class="breadcrumb"><span>TeamDark</span>'
            .self::icon('chevron').'<strong>'.$safeTitle.'</strong></div></div>'
            .'<div class="topbar-right"><div class="secure-pill"><span></span>Protected</div>'
            .'<div class="profile-chip"><span class="avatar">'.self::e($initial).'</span><div><strong>'.self::e($displayName).'</strong><small>'.$role.'</small></div></div></div></header>'
            .'<main class="page-content">'.$notice.$body.'</main>'
            .'<footer><span>TeamDark secure control plane</span><span>Session encrypted</span></footer>'
            .'</div></div><script src="/assets/app.js?v=20260909-3" defer></script></body></html>';
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="'
            .self::e(Security::csrfToken()).'">';
    }
}
