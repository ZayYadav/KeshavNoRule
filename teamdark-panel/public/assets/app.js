'use strict';
(function () {
  var body = document.body;
  var activeModal = null;
  var toastTimer = null;
  var lastFocused = null;
  var dialogFocused = null;

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
    var modal = document.getElementById(name);
    if (modal && modal.getAttribute('data-modal') !== name) return;
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
      dialogFocused = document.activeElement;

      var finish = function (result) {
        dialog.classList.remove('show');
        dialog.setAttribute('aria-hidden', 'true');
        ok.onclick = null;
        cancel.onclick = null;
        if (!activeModal && !busy.classList.contains('show')) body.classList.remove('modal-open');
        if (dialogFocused && typeof dialogFocused.focus === 'function') dialogFocused.focus();
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

  function hydrateOwnerUser(trigger) {
    var modal = document.querySelector('[data-modal="owner-user"]');
    if (!modal || !trigger) return;

    var data = trigger.dataset;
    modal.querySelectorAll('[data-owner-form]').forEach(function (form) { form.reset(); form.removeAttribute('data-confirmed'); });
    var historyLink = modal.querySelector('[data-owner-history]');
    if (historyLink) historyLink.href = '/owner/users?user_id=' + encodeURIComponent(data.userId);
    var initial = (data.userName || data.userUsername || 'U').slice(0, 1).toUpperCase();
    var put = function (selector, value) {
      var node = modal.querySelector(selector);
      if (node) node.textContent = value || '—';
    };

    put('[data-owner-user-name]', data.userName);
    put('[data-owner-user-handle]', '@' + (data.userUsername || 'unknown'));
    put('[data-owner-user-role]', (data.userRole || '').toUpperCase());
    put('[data-owner-user-balance]', data.userBalance);
    put('[data-owner-user-telegram]', data.userTelegram || 'Not linked');
    put('[data-owner-initial]', initial);

    modal.querySelectorAll('[data-owner-form]').forEach(function (form) {
      var idInput = form.querySelector('[name="user_id"]');
      if (idInput) idInput.value = data.userId || '';
    });

    var statusForm = modal.querySelector('[data-owner-status-form]');
    if (statusForm) {
      var action = data.userStatus === 'active' ? 'disable' : 'enable';
      var statusInput = statusForm.querySelector('[name="action"]');
      var statusButton = statusForm.querySelector('button[type="submit"]');
      if (statusInput) statusInput.value = action;
      if (statusButton) {
        statusButton.textContent = action === 'disable' ? 'Disable account' : 'Enable account';
        statusButton.classList.toggle('danger', action === 'disable');
      }
      statusForm.setAttribute(
        'data-confirm',
        (action === 'disable' ? 'Disable' : 'Enable') + ' @' + data.userUsername + '?'
      );
    }

    var role = modal.querySelector('[data-owner-role-select]');
    if (role) role.value = data.userRole || 'user';

    var telegramForm = modal.querySelector('[data-owner-telegram-form]');
    if (telegramForm) telegramForm.hidden = !data.userTelegram;
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
      if (opener.hasAttribute('data-user-manage')) hydrateOwnerUser(opener);
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
    if (form.hasAttribute('data-bulk-form') && !form.querySelector('[name="user_ids"]').value) {
      event.preventDefault();
      showToast('Select at least one user');
      return;
    }
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
        if (submitter && submitter.name) {
          var value = document.createElement('input');
          value.type = 'hidden'; value.name = submitter.name; value.value = submitter.value;
          form.appendChild(value);
        }
        HTMLFormElement.prototype.submit.call(form);
      });
      return;
    }

    if ((form.getAttribute('method') || 'get').toLowerCase() === 'post') {
      showBusy(form.getAttribute('data-busy') || actionText(form, submitter) + '…');
    }
  }, true);

  document.addEventListener('keydown', function (event) {
    var focusSurface = dialog.classList.contains('show') ? dialog : activeModal;
    if (event.key === 'Tab' && focusSurface) {
      var focusables = Array.prototype.slice.call(focusSurface.querySelectorAll('button,a[href],input:not([type="hidden"]),select,textarea,[tabindex="0"]')).filter(function (el) { return !el.disabled && !el.hidden && el.getClientRects().length; });
      var first = focusables[0], last = focusables[focusables.length-1];
      if (event.shiftKey && (document.activeElement === first || !focusSurface.contains(document.activeElement))) { event.preventDefault(); if (last) last.focus(); }
      else if (!event.shiftKey && (document.activeElement === last || !focusSurface.contains(document.activeElement))) { event.preventDefault(); if (first) first.focus(); }
    }
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

  var flashes = document.querySelectorAll('[data-flash]');
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
    if (hashName && hashName !== 'owner-user' && document.getElementById(hashName)) openModal(hashName);
  }

  var sidebar = document.querySelector('.sidebar');
  var sidebarToggle = document.querySelector('[data-sidebar-toggle]');
  var sidebarScrim = document.querySelector('[data-sidebar-close]');
  var closeSidebar = function () {
    if (sidebar) sidebar.classList.remove('open');
    if (sidebarScrim) sidebarScrim.classList.remove('show');
    if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', 'false');
  };

  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', function () {
      var opening = !sidebar.classList.contains('open');
      sidebar.classList.toggle('open', opening);
      if (sidebarScrim) sidebarScrim.classList.toggle('show', opening);
      sidebarToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });
  }
  if (sidebarScrim) sidebarScrim.addEventListener('click', closeSidebar);
  window.addEventListener('resize', function () {
    if (window.innerWidth > 860) closeSidebar();
  });

  var userSearch = document.querySelector('[data-user-search]');
  var userRole = document.querySelector('[data-user-role-filter]');
  var userStatus = document.querySelector('[data-user-status-filter]');
  var userRows = Array.prototype.slice.call(document.querySelectorAll('[data-user-row]'));
  var userEmpty = document.querySelector('[data-user-empty]');
  var filterUsers = function () {
    var query = userSearch ? userSearch.value.trim().toLowerCase() : '';
    var role = userRole ? userRole.value : 'all';
    var status = userStatus ? userStatus.value : 'all';
    var shown = 0;

    userRows.forEach(function (row) {
      var matches = (!query || (row.getAttribute('data-search') || '').indexOf(query) !== -1) &&
        (role === 'all' || row.getAttribute('data-role') === role) &&
        (status === 'all' || row.getAttribute('data-status') === status);
      row.hidden = !matches;
      if (matches) shown++;
    });
    if (userEmpty) userEmpty.style.display = shown ? 'none' : 'table-row';
  };
  [userSearch, userRole, userStatus].forEach(function (control) {
    if (control) control.addEventListener(control.tagName === 'INPUT' ? 'input' : 'change', filterUsers);
  });

  var selectAll = document.querySelector('[data-select-all-users]');
  var userChecks = Array.prototype.slice.call(document.querySelectorAll('[data-user-check]'));
  var bulkForm = document.querySelector('[data-bulk-form]');
  var selectedCount = document.querySelector('[data-selected-count]');
  var syncSelection = function () {
    var selected = userChecks.filter(function (box) { return box.checked; });
    if (selectedCount) selectedCount.textContent = selected.length + ' selected';
    if (bulkForm) {
      var ids = bulkForm.querySelector('[name="user_ids"]');
      if (ids) ids.value = selected.map(function (box) { return box.value; }).join(',');
      var submit = bulkForm.querySelector('button[type="submit"]');
      if (submit) submit.disabled = selected.length === 0;
    }
    if (selectAll) {
      selectAll.checked = userChecks.length > 0 && selected.length === userChecks.length;
      selectAll.indeterminate = selected.length > 0 && selected.length < userChecks.length;
    }
  };
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      userChecks.forEach(function (box) {
        if (!box.closest('tr').hidden) box.checked = selectAll.checked;
      });
      syncSelection();
    });
  }
  userChecks.forEach(function (box) { box.addEventListener('change', syncSelection); });
  if (bulkForm) {
    bulkForm.addEventListener('submit', function (event) {
      var ids = bulkForm.querySelector('[name="user_ids"]');
      if (!ids || !ids.value) {
        event.preventDefault();
        showToast('Select at least one user');
      }
    });
  }
  syncSelection();

  document.querySelectorAll('[data-table-search]').forEach(function (input) {
    var target = document.querySelector(input.getAttribute('data-table-search'));
    if (!target) return;
    input.addEventListener('input', function () {
      var query = input.value.trim().toLowerCase();
      target.querySelectorAll('tbody tr').forEach(function (row) {
        row.hidden = !!query && row.textContent.toLowerCase().indexOf(query) === -1;
      });
    });
  });

  var registrationCountdown = document.querySelector('[data-registration-countdown]');
  if (registrationCountdown) {
    var seconds = parseInt(registrationCountdown.getAttribute('data-registration-countdown') || '15', 10);
    var countdownOrb = document.querySelector('.countdown-panel .security-orb');
    var deadline = Date.now() + Math.max(1, seconds) * 1000;
    var timer = null;

    var renderCountdown = function () {
      var remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));

      if (remaining > 0) {
        registrationCountdown.textContent = 'OK • ' + remaining + 's';
        registrationCountdown.classList.add('is-disabled');
        registrationCountdown.setAttribute('aria-disabled', 'true');
        registrationCountdown.setAttribute('tabindex', '-1');
        if (countdownOrb) countdownOrb.textContent = String(remaining);
        return;
      }

      registrationCountdown.textContent = 'OK • Continue to login';
      registrationCountdown.classList.remove('is-disabled');
      registrationCountdown.setAttribute('aria-disabled', 'false');
      registrationCountdown.removeAttribute('tabindex');
      if (countdownOrb) countdownOrb.textContent = '✓';
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    };

    registrationCountdown.addEventListener('click', function (event) {
      if (registrationCountdown.classList.contains('is-disabled')) {
        event.preventDefault();
      }
    });

    renderCountdown();
    timer = setInterval(renderCountdown, 250);
  }

  window.addEventListener('pageshow', function () {
    hideBusy();
    document.querySelectorAll('[data-confirmed]').forEach(function (form) { form.removeAttribute('data-confirmed'); });
  });

  document.querySelectorAll('.field').forEach(function (field, index) {
    var label = field.querySelector('label'), input = field.querySelector('input,select,textarea');
    if (!label || !input) return;
    if (!input.id) input.id = 'td-field-' + index;
    label.htmlFor = input.id;
    if (input.type === 'password') {
      var wrapper = document.createElement('div'); wrapper.className = 'password-wrap';
      input.parentNode.insertBefore(wrapper, input); wrapper.appendChild(input);
      var toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'password-toggle';
      toggle.textContent = 'Show'; toggle.setAttribute('aria-label','Show password'); toggle.setAttribute('aria-pressed','false');
      toggle.addEventListener('click', function () {
        var reveal = input.type === 'password'; input.type = reveal ? 'text' : 'password';
        toggle.textContent = reveal ? 'Hide' : 'Show'; toggle.setAttribute('aria-pressed',String(reveal)); toggle.setAttribute('aria-label',reveal ? 'Hide password' : 'Show password');
      });
      wrapper.appendChild(toggle);
    }
  });
})();
