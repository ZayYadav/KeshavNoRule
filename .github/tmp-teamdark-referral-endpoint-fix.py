from pathlib import Path

p = Path('teamdark-panel/public/index.php')
text = p.read_text(encoding='utf-8')
old = "Only keys generated for an assigned App API work on that app's Connect endpoint."
new = "Only keys generated for an assigned App API work on that App API Connect endpoint."
if old not in text:
    raise SystemExit('endpoint quote marker not found after main patch')
p.write_text(text.replace(old, new, 1), encoding='utf-8')
print('endpoint page quote fixed')
