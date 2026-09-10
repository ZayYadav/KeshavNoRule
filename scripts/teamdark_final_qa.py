from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    if old not in text:
        raise SystemExit(f"marker not found in {path}: {old[:100]!r}")
    p.write_text(text.replace(old, new, 1))


# Make all visible shell branding follow the Owner rebranding settings.
replace_once(
    'teamdark-panel/app/View.php',
    '<div class="eyebrow">TEAM DARK / ACCESS</div>',
    '<div class="eyebrow">\'.$app.\' / ACCESS</div>',
)
replace_once(
    'teamdark-panel/app/View.php',
    '<a href="/owner/settings">Server controls</a>',
    '<a href="/owner/server">Server controls</a>',
)

# Existing cached owner-tools.js recognizes this marker and will not inject a duplicate Edit link.
replace_once(
    'teamdark-panel/public/index.php',
    '<a class="ghost compact" href="/key-edit?id=\'.(int)$row[\'id\'].\'">Extend / Edit</a>',
    '<a class="ghost compact" data-key-edit-link="1" href="/key-edit?id=\'.(int)$row[\'id\'].\'">Extend / Edit</a>',
)

# Manual Panel Update purge covers both canonical and currently-versioned UI asset URLs.
p = Path('teamdark-panel/app/OwnerSystem.php')
text = p.read_text()
marker = """                $base.'/assets/app.css',
                $base.'/assets/themes.css',
                $base.'/assets/owner-tools.css',
                $base.'/assets/system-console.css',
                $base.'/assets/app.js',
                $base.'/assets/owner-tools.js',
"""
versioned = """                $base.'/assets/app.css?v=20260910-2',
                $base.'/assets/themes.css?v=20260910-2',
                $base.'/assets/owner-tools.css?v=20260910-2',
                $base.'/assets/system-console.css?v=20260910-1',
                $base.'/assets/app.js?v=20260910-2',
                $base.'/assets/owner-tools.js?v=20260910-2',
"""
if marker not in text:
    raise SystemExit('CDN asset marker not found')
p.write_text(text.replace(marker, marker + versioned, 1))

# Finish public-facing File Manager terminology. Internal class/storage names intentionally stay stable.
p = Path('teamdark-panel/public/vault.php')
text = p.read_text()
for old, new in [
    ('Open vault', 'Open File Manager'),
    ('Your vault is empty', 'Your File Manager is empty'),
    ('Upload to private vault', 'Upload to File Manager'),
    ("View::page('File vault unavailable'", "View::page('File Manager unavailable'"),
    ('<h1>File vault unavailable</h1>', '<h1>File Manager unavailable</h1>'),
]:
    text = text.replace(old, new)
p.write_text(text)
