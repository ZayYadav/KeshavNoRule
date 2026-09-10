'use strict';
(function () {
  var body = document.body;
  if (!body) return;

  var allowed = ['obsidian', 'crimson', 'cyber', 'emerald', 'royal', 'snow'];
  var userKey = (body.getAttribute('data-theme-user') || 'guest').replace(/[^A-Za-z0-9_.-]/g, '').slice(0, 80) || 'guest';
  var storageKey = 'teamdark.theme.v2.' + userKey;
  var select = document.querySelector('[data-theme-select]');
  var metaTheme = document.querySelector('meta[name="theme-color"]');
  var themeColors = {
    obsidian: '#05070b',
    crimson: '#0b0508',
    cyber: '#020817',
    emerald: '#03100c',
    royal: '#090611',
    snow: '#edf3fb'
  };

  function valid(value) {
    return allowed.indexOf(value) !== -1 ? value : 'obsidian';
  }

  function readSaved() {
    try {
      return valid(localStorage.getItem(storageKey) || 'obsidian');
    } catch (e) {
      return 'obsidian';
    }
  }

  function apply(theme, persist) {
    theme = valid(theme);
    body.setAttribute('data-theme', theme);
    document.documentElement.setAttribute('data-theme', theme);
    if (select && select.value !== theme) select.value = theme;
    if (metaTheme) metaTheme.setAttribute('content', themeColors[theme] || themeColors.obsidian);

    if (persist) {
      try { localStorage.setItem(storageKey, theme); } catch (e) {}
    }

    try {
      window.dispatchEvent(new CustomEvent('teamdark:themechange', { detail: { theme: theme } }));
    } catch (e) {}
  }

  apply(readSaved(), false);

  if (select) {
    select.addEventListener('change', function () {
      apply(select.value, true);
    });
  }

  document.addEventListener('keydown', function (event) {
    if (!event.altKey || !event.shiftKey || event.key.toLowerCase() !== 't') return;
    event.preventDefault();
    var current = valid(body.getAttribute('data-theme') || 'obsidian');
    var next = allowed[(allowed.indexOf(current) + 1) % allowed.length];
    apply(next, true);
  });
})();
