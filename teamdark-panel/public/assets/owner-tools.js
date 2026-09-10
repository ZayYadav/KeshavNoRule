'use strict';
(function () {
  /*
   * Cinematic splash gate.
   * This script is loaded before app.js, so it takes ownership of the splash
   * and prevents the legacy auto-dismiss behaviour from app.js.
   */
  var splash = document.querySelector('[data-site-splash]');
  if (splash) {
    var splashVersion = splash.getAttribute('data-splash-version') || '1';
    var enterButton = splash.querySelector('[data-splash-skip]');
    var secureCookie = window.location.protocol === 'https:' ? '; Secure' : '';
    var gateDuration = 5500;
    var ready = false;

    /* app.js only manages elements that still carry data-site-splash. */
    splash.removeAttribute('data-site-splash');
    splash.setAttribute('data-splash-managed', 'cinematic-gate');
    splash.setAttribute('data-splash-duration', String(gateDuration));
    splash.style.setProperty('--splash-duration', gateDuration + 'ms');
    document.body.classList.add('splash-open');

    if (enterButton) {
      enterButton.disabled = true;
      enterButton.setAttribute('aria-disabled', 'true');
      enterButton.textContent = 'PREMIERE LOADING…';
      enterButton.style.opacity = '0';
      enterButton.style.visibility = 'hidden';
      enterButton.style.pointerEvents = 'none';
      enterButton.style.transform = 'translateY(8px)';
    }

    window.setTimeout(function () {
      ready = true;
      splash.classList.add('is-ready');

      if (enterButton) {
        enterButton.disabled = false;
        enterButton.removeAttribute('aria-disabled');
        enterButton.textContent = 'ENTER';
        enterButton.style.visibility = 'visible';
        enterButton.style.pointerEvents = 'auto';
        enterButton.style.opacity = '1';
        enterButton.style.transform = 'translateY(0)';
        try { enterButton.focus({ preventScroll: true }); } catch (e) {}
      }
    }, gateDuration);

    if (enterButton) {
      enterButton.addEventListener('click', function (event) {
        event.preventDefault();
        if (!ready) return;

        ready = false;
        enterButton.disabled = true;
        enterButton.setAttribute('aria-disabled', 'true');
        enterButton.textContent = 'ENTERING…';

        try {
          document.cookie =
            'TD_SPLASH=' + encodeURIComponent(splashVersion)
            + '; Path=/; SameSite=Lax' + secureCookie;
        } catch (e) {}

        splash.classList.add('is-leaving');
        splash.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('splash-open');

        window.setTimeout(function () {
          window.location.assign('/login');
        }, 460);
      });
    }
  }

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
