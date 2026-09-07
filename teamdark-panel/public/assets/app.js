'use strict';
(() => {
  const toast = document.createElement('div');
  toast.className = 'toast';
  document.body.appendChild(toast);
  let toastTimer;

  const showToast = (message) => {
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 1200);
  };

  const copyText = async (value) => {
    if (!value) return;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
      } else {
        const input = document.createElement('textarea');
        input.value = value;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
      }
      showToast('Copied');
    } catch (_) {
      showToast('Copy failed');
    }
  };

  const openModal = (name) => {
    const modal = document.querySelector('[data-modal="' + name + '"]');
    if (!modal) return;
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => modal.querySelector('input,select,button')?.focus());
  };

  const closeModal = (modal) => {
    if (!modal) return;
    modal.hidden = true;
    document.body.style.overflow = '';
  };

  document.addEventListener('click', (event) => {
    const copy = event.target.closest('[data-copy]');
    if (copy) {
      event.preventDefault();
      copyText(copy.getAttribute('data-copy') || '');
      return;
    }

    const opener = event.target.closest('[data-open-modal]');
    if (opener) {
      openModal(opener.getAttribute('data-open-modal'));
      return;
    }

    const closer = event.target.closest('[data-close-modal]');
    if (closer) {
      closeModal(closer.closest('[data-modal]'));
      return;
    }

    const backdrop = event.target.matches('[data-modal]') ? event.target : null;
    if (backdrop) {
      closeModal(backdrop);
      return;
    }

    const confirmButton = event.target.closest('[data-confirm]');
    if (confirmButton && !window.confirm(confirmButton.getAttribute('data-confirm') || 'Continue?')) {
      event.preventDefault();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const modal = document.querySelector('[data-modal]:not([hidden])');
    if (modal) closeModal(modal);
  });

  const unlimited = document.querySelector('[data-unlimited-toggle]');
  if (unlimited) {
    const days = unlimited.closest('form')?.querySelector('[name="duration_days"]');
    const sync = () => {
      if (days) days.disabled = unlimited.checked;
    };
    unlimited.addEventListener('change', sync);
    sync();
  }
})();