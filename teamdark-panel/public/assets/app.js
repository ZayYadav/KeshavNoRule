'use strict';
(function () {
  var body = document.body;
  var activeModal = null;
  var toastTimer = null;
  var lastFocused = null;

  var toast = document.createElement('div');
  toast.className = 'toast';
  toast.setAttribute('role', 'status');
  toast.setAttribute('aria-live', 'polite');
  body.appendChild(toast);

  var dialog = document.createElement('div');
  dialog.className = 'ui-dialog-backdrop';
  dialog.setAttribute('aria-hidden', 'true');
  dialog.innerHTML =
    '<div class="ui-dialog" role="dialog" aria-modal="true" aria-labelledby="td-dialog-title">' +
      '<div class="ui-dialog-icon" data-dialog-icon>TD</div>' +
      '<div class="ui-dialog-copy">' +
        '<div class="eyebrow">TEAM DARK CONTROL</div>' +
        '<h3 id="td-dialog-title" data-dialog-title>Confirm action</h3>' +
        '<p class="muted" data-dialog-message></p>' +
      '</div>' +
      '<div class="ui-dialog-actions">' +
        '<button type="button" class="ghost" data-dialog-cancel>Cancel</button>' +
        '<button type="button" class="primary" data-dialog-ok>Continue</button>' +
      '</div>' +
    '</div>';
  body.appendChild(dialog);

  var busy = document.createElement('div');
  busy.className = 'ui-busy-backdrop';
  busy.setAttribute('aria-hidden', 'true');
  busy.innerHTML =
    '<div class="ui-busy-card" role="status" aria-live="assertive">' +
      '<span class="ui-spinner" aria-hidden="true"></span>' +
      '<div><strong data-busy-title>Processing…</strong><span>Please keep this page open.</span></div>' +
    '</div>';
  body.appendChild(busy);

  function showToast(message) {
    toast.textContent = message || 'Done';
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toast.classList.remove('show');
    }, 1800);
  }

  function copyText(value) {
    if (!value) return;
    var done = function () { showToast('Copied to clipboard'); };
    var failed = function () { showToast('Copy failed'); };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(value).then(done).catch(failed);
      return;
    }

    try {
      var input = document.createElement('textarea');
      input.value = value;
      input.setAttribute('readonly', '');
      input.style.position = 'fixed';
      input.style.opacity = '0';
      input.style.pointerEvents = 'none';
      body.appendChild(input);
      input.focus();
      input.select();
      if (document.execCommand('copy')) done(); else failed();
      input.remove();
    } catch (e) {
      failed();
    }
  }

  function openModal(name) {
    var modal = document.querySelector('[data-modal="' + name + '"]');
    if (!modal) return;
    lastFocused = document.activeElement;
    activeModal = modal;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    body.classList.add('modal-open');

    try {
      if (window.location.hash !== '#' + name) {
        history.replaceState(null, document.title, window.location.pathname + window.location.search + '#' + name);
      }
    } catch (e) {}

    setTimeout(function () {
      var focusable = modal.querySelector('input,select,button,a[href]');
      if (focusable) focusable.focus();
    }, 20);
  }

  function closeModal(modal) {
    modal = modal || activeModal;
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    body.classList.remove('modal-open');
    activeModal = null;

    try {
      if (window.location.hash) {
        history.replaceState(null, document.title, window.location.pathname + window.location.search);
      }
    } catch (e) {}

    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  function showBusy(message) {
    var title = busy.querySelector('[data-busy-title]');
    if (title) title.textContent = message || 'Processing…';
    busy.classList.add('show');
    busy.setAttribute('aria-hidden', 'false');
    body.classList.add('modal-open');
  }

  function hideBusy() {
    busy.classList.remove('show');
    busy.setAttribute('aria-hidden', 'true');
    if (!activeModal && !dialog.classList.contains('show')) body.classList.remove('modal-open');
  }

  function ask(options) {
    options = options || {};
    return new Promise(function (resolve) {
      var title = dialog.querySelector('[data-dialog-title]');
      var message = dialog.querySelector('[data-dialog-message]');
      var icon = dialog.querySelector('[data-dialog-icon]');
      var cancel = dialog.querySelector('[data-dialog-cancel]');
      var ok = dialog.querySelector('[data-dialog-ok]');

      title.textContent = options.title || 'Confirm action';
      message.textContent = options.message || 'Continue with this action?';
      icon.textContent = options.icon || 'TD';
      ok.textContent = options.okText || 'Continue';
      cancel.textContent = options.cancelText || 'Cancel';
      cancel.hidden = options.cancel === false;

      dialog.classList.add('show');
      dialog.setAttribute('aria-hidden', 'false');
      body.classList.add('modal-open');
      lastFocused = document.activeElement;

      var finish = function (result) {
        dialog.classList.remove('show');
        dialog.setAttribute('aria-hidden', 'true');
        ok.onclick = null;
        cancel.onclick = null;
        if (!activeModal && !busy.classList.contains('show')) body.classList.remove('modal-open');
        if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
        resolve(result);
      };

      ok.onclick = function () { finish(true); };
      cancel.onclick = function () { finish(false); };
      setTimeout(function () { ok.focus(); }, 20);
    });
  }

  function actionText(form, submitter) {
    return (form && form.getAttribute('data-action')) ||
      (submitter && submitter.getAttribute && submitter.getAttribute('data-action')) ||
      (submitter && submitter.textContent && submitter.textContent.trim()) ||
      'Continue';
  }

  document.addEventListener('click', function (event) {
    var raw = event.target;
    var target = raw && raw.nodeType === 1 ? raw : (raw && raw.parentElement);
    if (!target) return;

    var copy = target.closest('[data-copy]');
    if (copy) {
      event.preventDefault();
      copyText(copy.getAttribute('data-copy') || '');
      return;
    }

    var opener = target.closest('[data-open-modal]');
    if (opener) {
      event.preventDefault();
      openModal(opener.getAttribute('data-open-modal'));
      return;
    }

    var closer = target.closest('[data-close-modal]');
    if (closer) {
      event.preventDefault();
      closeModal(closer.closest('[data-modal]'));
      return;
    }

    if (target.matches('[data-modal]')) closeModal(target);
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.getAttribute('data-confirmed') === '1') return;

    var submitter = event.submitter || document.activeElement;
    var explicit =
      (submitter && submitter.getAttribute && submitter.getAttribute('data-confirm')) ||
      form.getAttribute('data-confirm');

    if (explicit) {
      event.preventDefault();
      ask({
        title: actionText(form, submitter),
        message: explicit,
        okText: 'Confirm'
      }).then(function (confirmed) {
        if (!confirmed) return;
        form.setAttribute('data-confirmed', '1');
        showBusy(form.getAttribute('data-busy') || actionText(form, submitter) + '…');
        form.submit();
      });
      return;
    }

    if ((form.getAttribute('method') || 'get').toLowerCase() === 'post') {
      showBusy(form.getAttribute('data-busy') || actionText(form, submitter) + '…');
    }
  }, true);

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    if (dialog.classList.contains('show')) {
      var cancel = dialog.querySelector('[data-dialog-cancel]');
      if (cancel && !cancel.hidden) cancel.click();
      return;
    }
    if (activeModal) closeModal(activeModal);
  });

  var unlimited = document.querySelector('[data-unlimited-toggle]');
  if (unlimited) {
    var generationForm = unlimited.closest('form');
    var days = generationForm ? generationForm.querySelector('[name="duration_days"]') : null;
    var sync = function () {
      if (!days) return;
      days.disabled = false;
      days.setAttribute('aria-disabled', unlimited.checked ? 'true' : 'false');
      var dayField = days.closest('.field');
      if (dayField) dayField.classList.toggle('field-disabled', unlimited.checked);
    };
    unlimited.addEventListener('change', sync);
    sync();
  }

  var flashes = document.querySelectorAll('.alert');
  if (flashes.length) {
    var flash = flashes[0];
    var okFlash = flash.classList.contains('ok');
    ask({
      title: okFlash ? 'Success' : 'Action failed',
      message: flash.textContent.trim(),
      icon: okFlash ? '✓' : '!',
      okText: 'OK',
      cancel: false
    });
  }

  if (window.location.hash) {
    var hashName = window.location.hash.slice(1);
    if (hashName && document.querySelector('[data-modal="' + hashName + '"]')) openModal(hashName);
  }

  window.addEventListener('pageshow', function () {
    hideBusy();
  });
})();