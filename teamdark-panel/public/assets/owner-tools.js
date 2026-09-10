'use strict';
(function () {
  // The deep premium visual system in app.css is currently scoped to UI v5.
  // Keep the rendered markup compatible until those selectors are versionless.
  if (document.body && document.body.getAttribute('data-teamdark-ui') !== '5') {
    document.body.setAttribute('data-teamdark-ui', '5');
  }

  function copyText(value) {
    if (!value) return Promise.reject(new Error('Empty value'));
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(value);
    }

    return new Promise(function (resolve, reject) {
      try {
        var area = document.createElement('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.left = '-9999px';
        document.body.appendChild(area);
        area.select();
        var ok = document.execCommand('copy');
        area.remove();
        if (ok) resolve(); else reject(new Error('Copy failed'));
      } catch (e) {
        reject(e);
      }
    });
  }

  document.addEventListener('click', function (event) {
    var raw = event.target;
    var target = raw && raw.nodeType === 1 ? raw : raw && raw.parentElement;
    if (!target) return;

    var button = target.closest('[data-copy-target]');
    if (!button) return;

    var id = button.getAttribute('data-copy-target') || '';
    var source = id ? document.getElementById(id) : null;
    if (!source) return;

    event.preventDefault();
    var original = button.textContent;

    copyText(source.textContent || '').then(function () {
      button.classList.add('is-copied');
      button.textContent = '✓ Copied';
      window.setTimeout(function () {
        button.classList.remove('is-copied');
        button.textContent = original;
      }, 1300);
    }).catch(function () {
      button.textContent = 'Copy failed';
      window.setTimeout(function () { button.textContent = original; }, 1300);
    });
  });
})();
