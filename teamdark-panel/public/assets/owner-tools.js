'use strict';
(function () {
  var ultraCss = document.createElement('link');
  ultraCss.rel = 'stylesheet';
  ultraCss.href = '/assets/cinematic-ultra.css?v=20260910-1';
  document.head.appendChild(ultraCss);

  function installCinematicUltra(splash) {
    if (!splash || splash.querySelector('.td-cinema-ultra')) return;

    var ultra = document.createElement('div');
    ultra.className = 'td-cinema-ultra';
    ultra.setAttribute('aria-hidden', 'true');
    ultra.innerHTML =
      '<canvas class="td-cinema-particles" data-cinema-particles></canvas>' +
      '<div class="td-cinema-nebula a"></div>' +
      '<div class="td-cinema-nebula b"></div>' +
      '<div class="td-cinema-horizon"></div>' +
      '<div class="td-cinema-ringfield"><i></i><i></i><i></i><i></i></div>' +
      '<div class="td-cinema-scan"></div>' +
      '<div class="td-cinema-impact"></div>' +
      '<div class="td-cinema-corners"></div>' +
      '<div class="td-cinema-corner-bottom"></div>' +
      '<div class="td-cinema-sequence"><b>TEAM DARK // DIRECTOR CUT</b><span data-cinema-caption>ORIGIN SIGNAL ACQUIRED</span></div>' +
      '<div class="td-cinema-timecode"><span>SECURE PREMIERE</span><b data-cinema-timecode>00:00:000</b></div>';
    splash.insertBefore(ultra, splash.firstChild);

    var caption = ultra.querySelector('[data-cinema-caption]');
    var timecode = ultra.querySelector('[data-cinema-timecode]');
    var canvas = ultra.querySelector('[data-cinema-particles]');
    var ctx = canvas && canvas.getContext ? canvas.getContext('2d') : null;
    var started = performance.now();
    var running = true;
    var particles = [];
    var lastFrame = 0;

    function setCaption(text) {
      if (caption) caption.textContent = text;
    }

    function resizeCanvas() {
      if (!canvas || !ctx) return;
      var dpr = Math.min(window.devicePixelRatio || 1, 1.5);
      var w = Math.max(1, window.innerWidth);
      var h = Math.max(1, window.innerHeight);
      canvas.width = Math.floor(w * dpr);
      canvas.height = Math.floor(h * dpr);
      canvas.style.width = w + 'px';
      canvas.style.height = h + 'px';
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

      var count = Math.max(34, Math.min(82, Math.floor(w / 18)));
      particles = Array.from({ length: count }, function (_, i) {
        var warm = i % 9 === 0;
        return {
          x: Math.random() * w,
          y: Math.random() * h,
          z: .35 + Math.random() * 1.5,
          r: .35 + Math.random() * 1.3,
          vx: (Math.random() - .5) * .09,
          vy: -.03 - Math.random() * .16,
          a: .12 + Math.random() * .55,
          warm: warm
        };
      });
    }

    function drawParticles(now) {
      if (!running || !ctx || !canvas) return;
      if (now - lastFrame < 25) {
        requestAnimationFrame(drawParticles);
        return;
      }
      lastFrame = now;
      var w = window.innerWidth;
      var h = window.innerHeight;
      ctx.clearRect(0, 0, w, h);

      particles.forEach(function (p) {
        p.x += p.vx * p.z;
        p.y += p.vy * p.z;
        if (p.y < -8) { p.y = h + 8; p.x = Math.random() * w; }
        if (p.x < -8) p.x = w + 8;
        if (p.x > w + 8) p.x = -8;

        var pulse = .72 + Math.sin((now * .0012) + p.x * .01) * .28;
        var alpha = Math.max(.03, p.a * pulse);
        ctx.beginPath();
        ctx.fillStyle = p.warm
          ? 'rgba(246,213,138,' + alpha.toFixed(3) + ')'
          : 'rgba(124,231,255,' + alpha.toFixed(3) + ')';
        ctx.shadowBlur = p.r * 8;
        ctx.shadowColor = p.warm ? 'rgba(246,213,138,.55)' : 'rgba(124,231,255,.45)';
        ctx.arc(p.x, p.y, p.r * p.z, 0, Math.PI * 2);
        ctx.fill();
      });
      ctx.shadowBlur = 0;
      requestAnimationFrame(drawParticles);
    }

    resizeCanvas();
    window.addEventListener('resize', resizeCanvas, { passive: true });
    if (ctx && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      requestAnimationFrame(drawParticles);
    }

    var timeTimer = window.setInterval(function () {
      if (!timecode) return;
      var elapsed = Math.max(0, performance.now() - started);
      var sec = Math.floor(elapsed / 1000);
      var ms = Math.floor(elapsed % 1000);
      timecode.textContent = '00:' + String(sec).padStart(2, '0') + ':' + String(ms).padStart(3, '0');
    }, 43);

    splash.addEventListener('mousemove', function (event) {
      var x = ((event.clientX / Math.max(1, window.innerWidth)) - .5) * 2;
      var y = ((event.clientY / Math.max(1, window.innerHeight)) - .5) * 2;
      splash.style.setProperty('--cinema-px', x.toFixed(3));
      splash.style.setProperty('--cinema-py', y.toFixed(3));
    }, { passive: true });

    splash.addEventListener('mouseleave', function () {
      splash.style.setProperty('--cinema-px', '0');
      splash.style.setProperty('--cinema-py', '0');
    }, { passive: true });

    window.setTimeout(function () {
      splash.setAttribute('data-cinema-phase', 'ignition');
      setCaption('VOLUMETRIC ARRAY ONLINE');
    }, 420);
    window.setTimeout(function () {
      splash.setAttribute('data-cinema-phase', 'reveal');
      setCaption('IDENTITY REVEAL // TEAM DARK');
    }, 1350);
    window.setTimeout(function () {
      splash.setAttribute('data-cinema-phase', 'impact');
      setCaption('SECURE CORE SYNCHRONIZED');
    }, 2680);
    window.setTimeout(function () {
      splash.setAttribute('data-cinema-phase', 'lock');
      setCaption('TLS CHANNEL LOCKED');
    }, 4100);

    splash.addEventListener('cinematic-ready', function () {
      splash.setAttribute('data-cinema-phase', 'ready');
      setCaption('ACCESS GATE READY');
    });

    splash.addEventListener('cinematic-exit', function () {
      running = false;
      window.clearInterval(timeTimer);
    });
  }

  var splash = document.querySelector('[data-site-splash]');
  if (splash) {
    var splashVersion = splash.getAttribute('data-splash-version') || '1';
    var enterButton = splash.querySelector('[data-splash-skip]');
    var secureCookie = window.location.protocol === 'https:' ? '; Secure' : '';
    var gateDuration = 5500;
    var ready = false;

    splash.removeAttribute('data-site-splash');
    splash.setAttribute('data-splash-managed', 'cinematic-gate');
    splash.setAttribute('data-splash-duration', String(gateDuration));
    splash.setAttribute('data-cinema-phase', 'origin');
    splash.style.setProperty('--splash-duration', gateDuration + 'ms');
    document.body.classList.add('splash-open');
    installCinematicUltra(splash);

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
      splash.dispatchEvent(new CustomEvent('cinematic-ready'));

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

        splash.dispatchEvent(new CustomEvent('cinematic-exit'));
        splash.classList.add('is-leaving');
        splash.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('splash-open');

        window.setTimeout(function () {
          window.location.assign('/login');
        }, 460);
      });
    }

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && ready && enterButton) {
        event.preventDefault();
        enterButton.click();
      }
    });
  }

  if (document.body && document.body.getAttribute('data-teamdark-ui') !== '5') {
    document.body.setAttribute('data-teamdark-ui', '5');
  }

  function installPanelExtensions() {
    var path = window.location.pathname || '/';
    var nav = document.querySelector('.sidebar nav .nav-group');
    if (nav && !document.querySelector('[data-private-vault-link]')) {
      var vault = document.createElement('a');
      vault.href = '/files';
      vault.setAttribute('data-private-vault-link', '1');
      if (path.indexOf('/files') === 0) vault.className = 'active';
      vault.innerHTML = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16v13H4z"/><path d="M7 7V4h10v3"/><path d="M8 12h8M8 16h5"/></svg><span>Binary Vault</span>' + (path.indexOf('/files') === 0 ? '<i></i>' : '');
      nav.appendChild(vault);
    }

    if (path.indexOf('/key-edit') === 0) {
      var keyNav = document.querySelector('.sidebar nav a[href="/keys"]');
      if (keyNav) keyNav.classList.add('active');
    }

    document.querySelectorAll('input[name="new_password"],input[name="confirm_password"],form[action="/users/password"] input[name="password"]').forEach(function (input) {
      input.minLength = 1;
      input.maxLength = 200;
      input.removeAttribute('pattern');
      input.setAttribute('title', 'Any password from 1 to 200 characters');
      if (input.closest('form[action="/users/password"]')) input.setAttribute('placeholder', 'Any password');
    });

    document.querySelectorAll('.key-card').forEach(function (card) {
      var actions = card.querySelector('.key-actions');
      if (!actions || actions.querySelector('[data-key-edit-link]')) return;
      if (!actions.querySelector('[data-copy]')) return;
      var idInput = actions.querySelector('input[name="key_id"]');
      if (!idInput || !idInput.value) return;
      var edit = document.createElement('a');
      edit.className = 'ghost compact';
      edit.setAttribute('data-key-edit-link', '1');
      edit.href = '/key-edit?id=' + encodeURIComponent(idInput.value);
      edit.textContent = 'Edit';
      actions.insertBefore(edit, actions.firstChild ? actions.firstChild.nextSibling : null);
    });
  }

  installPanelExtensions();

  document.querySelectorAll('input[name="custom_key"]').forEach(function (input) {
    input.minLength = 5;
    input.maxLength = 80;
    input.removeAttribute('pattern');
    input.setAttribute('placeholder', 'Any custom key • 5–80 characters');
    input.setAttribute('title', 'Any text, spaces or symbols • 5–80 characters');
  });

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
