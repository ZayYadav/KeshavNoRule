'use strict';
(function () {
  var canvas = document.querySelector('[data-gateway-particles]');
  var clock = document.querySelector('[data-gateway-time]');
  var stage = document.querySelector('.gw-stage');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (clock) {
    var start = performance.now();
    var tickClock = function () {
      var elapsed = Math.max(0, performance.now() - start);
      var sec = Math.floor(elapsed / 1000);
      var ms = Math.floor(elapsed % 1000);
      clock.textContent = '00:' + String(sec).padStart(2, '0') + ':' + String(ms).padStart(3, '0');
      requestAnimationFrame(tickClock);
    };
    requestAnimationFrame(tickClock);
  }

  if (stage && !reduce && window.matchMedia('(pointer:fine)').matches) {
    window.addEventListener('pointermove', function (event) {
      var x = (event.clientX / window.innerWidth - .5) * 2;
      var y = (event.clientY / window.innerHeight - .5) * 2;
      stage.style.transform = 'rotateY(' + (x * 1.5).toFixed(2) + 'deg) rotateX(' + (-y * 1.1).toFixed(2) + 'deg) translate3d(' + (x * 4).toFixed(1) + 'px,' + (y * 3).toFixed(1) + 'px,0)';
    }, { passive: true });
    window.addEventListener('pointerleave', function () {
      stage.style.transform = '';
    });
  }

  if (!canvas || reduce || !canvas.getContext) return;
  var ctx = canvas.getContext('2d');
  if (!ctx) return;

  var particles = [];
  var width = 0;
  var height = 0;
  var dpr = 1;

  function resize() {
    dpr = Math.min(window.devicePixelRatio || 1, 1.5);
    width = Math.max(1, window.innerWidth);
    height = Math.max(1, window.innerHeight);
    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);
    canvas.style.width = width + 'px';
    canvas.style.height = height + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    var count = Math.max(36, Math.min(90, Math.floor(width / 18)));
    particles = Array.from({ length: count }, function (_, i) {
      return {
        x: Math.random() * width,
        y: Math.random() * height,
        z: .35 + Math.random() * 1.65,
        r: .35 + Math.random() * 1.25,
        vx: (Math.random() - .5) * .08,
        vy: -.02 - Math.random() * .11,
        warm: i % 11 === 0,
        phase: Math.random() * Math.PI * 2
      };
    });
  }

  function frame(now) {
    ctx.clearRect(0, 0, width, height);
    for (var i = 0; i < particles.length; i++) {
      var p = particles[i];
      p.x += p.vx * p.z;
      p.y += p.vy * p.z;
      if (p.y < -8) p.y = height + 8;
      if (p.x < -8) p.x = width + 8;
      if (p.x > width + 8) p.x = -8;

      var pulse = .32 + (Math.sin(now * .0016 + p.phase) + 1) * .18;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r * p.z, 0, Math.PI * 2);
      ctx.fillStyle = p.warm
        ? 'rgba(245,213,140,' + Math.min(.7, pulse) + ')'
        : 'rgba(124,231,255,' + Math.min(.58, pulse) + ')';
      ctx.fill();

      if (p.z > 1.35) {
        ctx.beginPath();
        ctx.moveTo(p.x, p.y + 2);
        ctx.lineTo(p.x - p.vx * 42, p.y - p.vy * 55);
        ctx.strokeStyle = p.warm
          ? 'rgba(245,213,140,.08)'
          : 'rgba(124,231,255,.07)';
        ctx.lineWidth = .6;
        ctx.stroke();
      }
    }
    requestAnimationFrame(frame);
  }

  resize();
  window.addEventListener('resize', resize, { passive: true });
  requestAnimationFrame(frame);
})();
