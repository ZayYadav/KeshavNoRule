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
        $hrefPath = (string)(parse_url($href, PHP_URL_PATH) ?: $href);
        $qualified = str_contains($href, '?') || str_contains($href, '#');
        $active = !$qualified && $path === $hrefPath;

        return '<a href="'.$href.'"'.($active ? ' class="active" aria-current="page"' : '').'>'
            .self::icon($icon).'<span>'.$label.'</span>'
            .($active ? '<i></i>' : '').'</a>';
    }

    private static function splash(array $settings): string
    {
        if (!(bool)($settings['splash_enabled'] ?? false)) {
            return '';
        }

        $version = (string)max(1, (int)($settings['splash_version'] ?? 1));
        if ((string)($_COOKIE['TD_SPLASH'] ?? '') === $version) {
            return '';
        }

        $duration = (int)($settings['splash_duration_ms'] ?? 2400);
        if (!in_array($duration, [1400,2000,2400,3200,4200], true)) {
            $duration = 2400;
        }

        $brandName = self::e(trim((string)($settings['brand_name'] ?? '')) ?: (string)Config::get('app_name'));
        $brandMark = self::e(strtoupper(trim((string)($settings['brand_mark'] ?? 'NR'))) ?: 'NR');
        $title = self::e((string)($settings['splash_title'] ?? 'NO RULE'));
        $subtitle = self::e((string)($settings['splash_subtitle'] ?? 'Secure control plane'));
        $stars = str_repeat('<i></i>', 18);

        return '<div class="td-splash" data-site-splash data-splash-version="'.self::e($version).'" data-splash-duration="'.$duration.'" role="dialog" aria-modal="true" aria-label="'.$brandName.' cinematic opening">'
            .'<div class="td-cinema-letterbox top" aria-hidden="true"></div>'
            .'<div class="td-cinema-letterbox bottom" aria-hidden="true"></div>'
            .'<div class="td-cinema-stars" aria-hidden="true">'.$stars.'</div>'
            .'<div class="td-splash-grid" aria-hidden="true"></div>'
            .'<div class="td-splash-glow td-splash-glow-a" aria-hidden="true"></div>'
            .'<div class="td-splash-glow td-splash-glow-b" aria-hidden="true"></div>'
            .'<div class="td-cinema-beam a" aria-hidden="true"></div>'
            .'<div class="td-cinema-beam b" aria-hidden="true"></div>'
            .'<div class="td-cinema-flare" aria-hidden="true"></div>'
            .'<div class="td-cinema-vignette" aria-hidden="true"></div>'
            .'<div class="td-cinema-grain" aria-hidden="true"></div>'
            .'<div class="td-splash-stage">'
            .'<div class="td-cinema-overline"><span>'.$brandName.'</span><i></i><span>SECURE PREMIERE</span></div>'
            .'<div class="td-splash-logo"><span>'.$brandMark.'</span><i></i></div>'
            .'<div class="td-splash-kicker"><i></i> ACCESS SEQUENCE INITIALIZING <i></i></div>'
            .'<h1>'.$title.'</h1>'
            .'<p>'.$subtitle.'</p>'
            .'<div class="td-cinema-status"><span>TLS CHANNEL</span><span>CONTROL PLANE</span><span>SESSION READY</span></div>'
            .'<div class="td-splash-progress"><span></span></div>'
            .'<div class="td-splash-foot"><span><i></i> Protected workspace</span>'
            .'<button type="button" data-splash-skip>Enter now</button></div>'
            .'</div></div>';
    }

    private static function themePicker(): string
    {
        return '<div class="theme-control" data-theme-control title="Personal theme • Alt+Shift+T to cycle">'
            .'<span class="theme-control-mark" aria-hidden="true">FX</span>'
            .'<select data-theme-select aria-label="Choose your personal panel theme">'
            .'<option value="obsidian">Obsidian</option>'
            .'<option value="crimson">Crimson</option>'
            .'<option value="cyber">Cyber Blue</option>'
            .'<option value="emerald">Emerald</option>'
            .'<option value="royal">Royal Purple</option>'
            .'<option value="snow">Snow Light</option>'
            .'</select></div>';
    }

    private static function codeCard(
        string $id,
        string $badge,
        string $title,
        string $subtitle,
        string $code
    ): string {
        return '<article class="owner-code-card">'
            .'<div class="owner-code-head"><div class="owner-code-title">'
            .'<span class="owner-code-lang">'.self::e($badge).'</span><div><strong>'.self::e($title).'</strong><small>'.self::e($subtitle).'</small></div></div>'
            .'<button class="owner-copy-btn" type="button" data-copy-target="'.self::e($id).'">Copy code</button></div>'
            .'<pre><code id="'.self::e($id).'">'.self::e($code).'</code></pre></article>';
    }

    private static function ownerConnectSection(): string
    {
        $base = rtrim((string)Config::get('app_url', ''), '/');
        if ($base === '') {
            $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: '';
            $base = $host !== ''
                ? (Security::isHttpsRequest() ? 'https://' : 'http://').$host
                : '';
        }
        $endpoint = $base !== '' ? $base.'/connect' : '/connect';

        $curl = str_replace('{{ENDPOINT}}', $endpoint, <<<'CURL'
curl --request POST '{{ENDPOINT}}' \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'game=PUBG' \
  --data-urlencode 'user_key=USER_KEY' \
  --data-urlencode 'serial=DEVICE_UUID'
CURL);

        $cpp = str_replace('{{ENDPOINT}}', $endpoint, <<<'CPP'
#include <curl/curl.h>
#include <string>

std::string NoRuleLogin(const std::string& userKey,
                        const std::string& serial) {
    const std::string endpoint = "{{ENDPOINT}}";
    CURL* curl = curl_easy_init();
    if (!curl) return {};

    char* keyEsc = curl_easy_escape(curl, userKey.c_str(), 0);
    char* serialEsc = curl_easy_escape(curl, serial.c_str(), 0);
    if (!keyEsc || !serialEsc) {
        if (keyEsc) curl_free(keyEsc);
        if (serialEsc) curl_free(serialEsc);
        curl_easy_cleanup(curl);
        return {};
    }

    std::string body = "game=PUBG&user_key=" + std::string(keyEsc)
                     + "&serial=" + std::string(serialEsc);
    curl_free(keyEsc);
    curl_free(serialEsc);

    std::string response;
    struct curl_slist* headers = nullptr;
    headers = curl_slist_append(headers, "Accept: application/json");
    headers = curl_slist_append(headers,
        "Content-Type: application/x-www-form-urlencoded");

    curl_easy_setopt(curl, CURLOPT_URL, endpoint.c_str());
    curl_easy_setopt(curl, CURLOPT_POST, 1L);
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, body.c_str());
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 1L);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYHOST, 2L);
    curl_easy_setopt(curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 8L);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 15L);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION,
        +[](char* p, size_t s, size_t n, void* out) -> size_t {
            static_cast<std::string*>(out)->append(p, s * n);
            return s * n;
        });
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &response);

    CURLcode result = curl_easy_perform(curl);
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);
    return result == CURLE_OK ? response : std::string{};
}
CPP);

        $java = str_replace('{{ENDPOINT}}', $endpoint, <<<'JAVA'
import javax.net.ssl.HttpsURLConnection;
import java.io.*;
import java.net.*;
import java.nio.charset.StandardCharsets;

public final class NoRuleApi {
    private static final String ENDPOINT = "{{ENDPOINT}}";

    public static String login(String userKey, String serial) throws Exception {
        String body = "game=" + enc("PUBG")
                + "&user_key=" + enc(userKey)
                + "&serial=" + enc(serial);

        HttpsURLConnection con = (HttpsURLConnection)
                new URL(ENDPOINT).openConnection();
        // HttpsURLConnection keeps certificate + hostname verification enabled.
        con.setRequestMethod("POST");
        con.setConnectTimeout(8000);
        con.setReadTimeout(15000);
        con.setDoOutput(true);
        con.setRequestProperty("Accept", "application/json");
        con.setRequestProperty("Content-Type",
                "application/x-www-form-urlencoded; charset=UTF-8");

        try (OutputStream out = con.getOutputStream()) {
            out.write(body.getBytes(StandardCharsets.UTF_8));
        }

        InputStream stream = con.getResponseCode() < 400
                ? con.getInputStream() : con.getErrorStream();
        return read(stream);
    }

    private static String enc(String value) throws Exception {
        return URLEncoder.encode(value, StandardCharsets.UTF_8.name());
    }

    private static String read(InputStream in) throws Exception {
        if (in == null) return "";
        try (BufferedReader r = new BufferedReader(
                new InputStreamReader(in, StandardCharsets.UTF_8))) {
            StringBuilder b = new StringBuilder();
            String line;
            while ((line = r.readLine()) != null) b.append(line);
            return b.toString();
        }
    }
}
JAVA);

        $kotlin = str_replace('{{ENDPOINT}}', $endpoint, <<<'KOTLIN'
import java.net.URL
import java.net.URLEncoder
import javax.net.ssl.HttpsURLConnection

object NoRuleApi {
    private const val ENDPOINT = "{{ENDPOINT}}"

    fun login(userKey: String, serial: String): String {
        val body = listOf(
            "game" to "PUBG",
            "user_key" to userKey,
            "serial" to serial
        ).joinToString("&") { (k, v) ->
            "${enc(k)}=${enc(v)}"
        }

        val con = (URL(ENDPOINT).openConnection() as HttpsURLConnection).apply {
            // Default Android TLS certificate + hostname verification stays ON.
            requestMethod = "POST"
            connectTimeout = 8_000
            readTimeout = 15_000
            doOutput = true
            setRequestProperty("Accept", "application/json")
            setRequestProperty(
                "Content-Type",
                "application/x-www-form-urlencoded; charset=UTF-8"
            )
        }

        con.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
        val stream = if (con.responseCode < 400) con.inputStream else con.errorStream
        return stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
    }

    private fun enc(value: String): String =
        URLEncoder.encode(value, Charsets.UTF_8.name())
}
KOTLIN);

        return '<section class="premium-section owner-connect-api" id="connect-api">'
            .'<div class="owner-connect-shell">'
            .'<div class="owner-connect-head"><div><span class="eyebrow">OWNER DEVELOPER CONSOLE</span>'
            .'<h2>Connect API</h2><p>Runtime-generated integration samples for the live No Rule Panel endpoint. The URL is resolved from the current server APP_URL every time this page renders, so this UI does not carry a second hardcoded domain.</p></div>'
            .'<span class="owner-connect-live"><i></i> Live endpoint</span></div>'
            .'<div class="owner-endpoint-card"><div class="owner-endpoint-top"><span>Resolved endpoint</span>'
            .'<button class="owner-copy-btn" type="button" data-copy-target="td-connect-endpoint">Copy URL</button></div>'
            .'<div class="owner-endpoint-value" id="td-connect-endpoint">'.self::e($endpoint).'</div>'
            .'<div class="owner-contract-grid">'
            .'<span><b>METHOD</b>POST</span><span><b>CONTENT TYPE</b>Form URL encoded</span><span><b>FIELDS</b>game • user_key • serial</span><span><b>TRANSPORT</b>HTTPS / TLS verified</span>'
            .'</div></div>'
            .'<div class="owner-code-grid">'
            .self::codeCard('td-code-curl', 'CLI', 'cURL request', 'Exact request contract', $curl)
            .self::codeCard('td-code-cpp', 'C++', 'C++ / libcurl', 'TLS peer + hostname verification ON', $cpp)
            .self::codeCard('td-code-java', 'JAVA', 'Java / Android', 'HttpsURLConnection with standard TLS validation', $java)
            .self::codeCard('td-code-kotlin', 'KT', 'Kotlin / Android', 'HttpsURLConnection with standard TLS validation', $kotlin)
            .'</div>'
            .'<div class="owner-connect-note"><span>i</span><div><b>Owner-only reference.</b> Samples contain placeholders only; no panel password, Bot token, APP_KEY or native secret is exposed. If APP_URL changes, reload this page and the displayed endpoint/snippets update automatically.</div></div>'
            .'</div></section>';
    }

    public static function page(string $title, string $body, ?array $user = null): void
    {
        $settings = PanelControl::settings();
        $rawApp = trim((string)($settings['brand_name'] ?? '')) ?: (string)Config::get('app_name');
        $app = self::e($rawApp);
        $brandSubtitle = self::e(trim((string)($settings['brand_subtitle'] ?? '')) ?: 'Secure control plane');
        $brandFooter = self::e(trim((string)($settings['brand_footer'] ?? '')) ?: $rawApp.' secure control plane');
        $brandMark = self::e(strtoupper(trim((string)($settings['brand_mark'] ?? 'NR'))) ?: 'NR');
        $safeTitle = self::e($title);
        $path = (string)(
            parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)
            ?: '/'
        );
        $csrf = self::e(Security::csrfToken());
        $splash = self::splash($settings);

        $head = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            .'<title>'.$safeTitle.' • '.$app.'</title>'
            .'<link rel="stylesheet" href="/assets/app.css?v=20260911-1">'
            .'<link rel="stylesheet" href="/assets/themes.css?v=20260910-2">'
            .'<link rel="stylesheet" href="/assets/owner-tools.css?v=20260910-2">'
            .'<link rel="stylesheet" href="/assets/cinematic.css?v=20260910-2">'
            .'<link rel="stylesheet" href="/assets/system-console.css?v=20260911-1">'
            .'<link rel="stylesheet" href="/assets/mobile-fix.css?v=20260911-1">'
            .'<meta name="theme-color" content="#020305">'
            .'<meta name="color-scheme" content="dark light"></head>';

        if (!$user) {
            echo $head.'<body data-teamdark-ui="5" data-theme="obsidian" data-theme-user="guest" class="guest-body">'.$splash
                .'<div class="fx-grid" aria-hidden="true"></div><div class="fx-noise" aria-hidden="true"></div>'
                .'<div class="ambient ambient-one"></div><div class="ambient ambient-two"></div><div class="ambient ambient-three"></div>'
                .'<div class="cursor-aura" aria-hidden="true"></div>'
                .'<main class="guest-main"><a class="guest-brand" href="/" aria-label="'.$app.' home">'
                .'<span class="brand-mark"><b>'.$brandMark.'</b><i></i></span>'
                .'<span><strong>'.$app.'</strong><small>'.$brandSubtitle.'</small></span></a>'
                .(in_array($path, ['/login','/login/2fa','/register','/register/success'], true)
                    ? '<div class="auth-layout"><aside class="auth-intro"><div class="eyebrow">'.$app.' / ACCESS</div><h2>Your network.<br>Your control.</h2><p>Manage licenses, users and access from one secure workspace.</p><div class="auth-capabilities"><span>01 <strong>License management</strong></span><span>02 <strong>Account controls</strong></span><span>03 <strong>Activity visibility</strong></span></div></aside>'.$body.'</div>'
                    : $body).'</main><script src="/assets/themes.js?v=20260910-2" defer></script><script src="/assets/owner-tools.js?v=20260910-2" defer></script><script src="/assets/app.js?v=20260910-2" defer></script></body></html>';
            return;
        }

        $displayName = trim((string)($user['name'] ?? '')) ?: (string)$user['username'];
        $initial = strtoupper(substr($displayName, 0, 1));
        $role = strtoupper((string)$user['role']);
        $themeUser = self::e((string)($user['id'] ?? $user['username'] ?? 'user'));

        $nav = '<div class="nav-group"><span class="nav-label">Main</span>'
            .self::navLink('/dashboard', 'Dashboard', 'dashboard', $path)
            .self::navLink('/keys', 'All Keys', 'keys', $path)
            .self::navLink('/keys#key-generator', 'Generate Key', 'spark', $path)
            .self::navLink('/keys?generator=random#key-generator', 'Random Keys', 'spark', $path)
            .self::navLink('/files', 'File Manager', 'spark', $path)
            .self::navLink('/my-apps', 'My App APIs', 'spark', $path)
            .'</div>';

        if (in_array($user['role'], ['owner','admin'], true)) {
            $nav .= '<div class="nav-group"><span class="nav-label">Management</span>'
                .self::navLink('/keys/extend', 'Extend Duration', 'keys', $path)
                .self::navLink('/users', 'Manage Users', 'users', $path)
                .self::navLink('/users#referral-center', 'Referral Codes', 'users', $path)
                .self::navLink('/users#balance-center', 'Balance', 'spark', $path);
            if ($user['role'] === 'owner') {
                $nav .= self::navLink('/owner/users', 'User Insights', 'users', $path)
                    .self::navLink('/telegram-users', 'Telegram Users', 'telegram', $path);
            }
            $nav .= '</div>';
        }

        if (($user['role'] ?? '') === 'owner') {
            $nav .= '<div class="nav-group system-group"><span class="nav-label">System</span>'
                .self::navLink('/owner/system', 'System Overview', 'dashboard', $path)
                .self::navLink('/owner/server', 'Server & Maint.', 'dashboard', $path)
                .self::navLink('/owner/apps', 'App APIs', 'spark', $path)
                .self::navLink('/owner/device-policy', 'One Device', 'keys', $path)
                .self::navLink('/owner/key-format', 'Key Format', 'keys', $path)
                .self::navLink('/owner/pricing', 'Pricing', 'spark', $path)
                .self::navLink('/owner/ip-management', 'IP Management', 'activity', $path)
                .self::navLink('/owner/rebranding', 'Manage Rebranding', 'spark', $path)
                .self::navLink('/owner/security', 'Heartbeat & Security', 'activity', $path)
                .self::navLink('/owner/session-controls', 'Heartbeat Kicks', 'activity', $path)
                .self::navLink('/owner/packages', 'Package Manager', 'spark', $path)
                .self::navLink('/owner/alerts', 'Panel Alert', 'telegram', $path)
                .self::navLink('/owner/update', 'Panel Update', 'spark', $path)
                .self::navLink('/owner/settings', 'Settings', 'dashboard', $path)
                .self::navLink('/activity', 'Activity Logs', 'activity', $path)
                .self::navLink('/owner/developer', 'Developer API', 'spark', $path)
                .'</div>';
        }

        $notice = '';

        if (($settings['announcement'] ?? '') !== '') {
            $notice .= '<section class="announcement premium-announcement" role="status">'
                .'<div class="announcement-mark">'.$brandMark.'</div>'
                .'<div class="announcement-copy"><span class="eyebrow">OFFICIAL ANNOUNCEMENT</span>'
                .'<strong>'.self::e((string)$settings['announcement']).'</strong>'
                .'<small>'.$app.' • '.self::e((string)($settings['announcement_published_at'] ?: 'Official update')).'</small></div>'
                .'<span class="announcement-live"><i></i> LIVE</span></section>';
        }

        if (!$settings['panel_online']) {
            $notice .= '<div class="alert">Panel is OFF for non-owner users. <a href="/owner/server">Server controls</a></div>';
        }

        if (($user['role'] ?? '') === 'owner' && $path === '/owner/developer') {
            $body .= self::ownerConnectSection();
        }

        echo $head.'<body data-teamdark-ui="5" data-theme="obsidian" data-theme-user="'.$themeUser.'">'.$splash
            .'<div class="fx-grid" aria-hidden="true"></div><div class="fx-noise" aria-hidden="true"></div>'
            .'<div class="ambient ambient-one"></div><div class="ambient ambient-two"></div><div class="ambient ambient-three"></div>'
            .'<div class="cursor-aura" aria-hidden="true"></div>'
            .'<div class="app-layout"><aside class="sidebar" id="app-sidebar" aria-label="Primary navigation">'
            .'<a class="sidebar-brand" href="/dashboard"><span class="brand-mark"><b>'.$brandMark.'</b><i></i></span>'
            .'<span><strong>'.$app.'</strong><small>'.$brandSubtitle.'</small></span></a>'
            .'<nav>'.$nav.'</nav>'
            .'<div class="sidebar-foot"><div class="system-state"><span></span><div><strong>Panel '.($settings['panel_online'] ? 'online' : 'offline').'</strong><small>Signed in as '.self::e($user['role']).'</small></div></div>'
            .'<form method="post" action="/logout" data-action="Signing out" data-busy="Closing secure session…">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'"><button class="nav-logout" type="submit">'
            .self::icon('logout').'<span>Sign out</span></button></form></div></aside>'
            .'<button class="sidebar-scrim" type="button" data-sidebar-close aria-label="Close navigation"></button>'
            .'<div class="workspace"><header class="topbar"><div class="topbar-left">'
            .'<button class="mobile-menu" type="button" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="false">'
            .self::icon('menu').'<span>Menu</span></button><div class="breadcrumb"><span>'.$app.'</span>'
            .self::icon('chevron').'<strong>'.$safeTitle.'</strong></div></div>'
            .'<div class="topbar-right">'.self::themePicker().'<div class="secure-pill"><span></span><b>Protected</b><i>Live security</i></div>'
            .'<div class="profile-chip"><span class="avatar">'.self::e($initial).'</span><div><strong>'.self::e($displayName).'</strong><small>'.$role.'</small></div></div></div></header>'
            .'<main class="page-content">'.$notice.$body.'</main>'
            .'<footer><span><i class="footer-dot"></i> '.$brandFooter.'</span><span>Session encrypted • Personal theme enabled</span></footer>'
            .'</div></div><script src="/assets/themes.js?v=20260910-2" defer></script><script src="/assets/owner-tools.js?v=20260910-2" defer></script><script src="/assets/app.js?v=20260910-2" defer></script></body></html>';
    }

    public static function csrf(): string
    {
        return '<input type="hidden" name="csrf" value="'
            .self::e(Security::csrfToken()).'">';
    }
}
