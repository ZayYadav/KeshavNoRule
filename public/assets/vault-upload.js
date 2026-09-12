(() => {
  'use strict';

  const CHUNK_BYTES = 256 * 1024;
  const MAX_FILE_BYTES = 50 * 1024 * 1024;

  const uploadId = () => {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  };

  const showError = (form, message) => {
    let box = form.querySelector('[data-vault-upload-error]');
    if (!box) {
      box = document.createElement('div');
      box.className = 'alert';
      box.setAttribute('data-vault-upload-error', '1');
      form.prepend(box);
    }
    box.textContent = message || 'Upload failed. Please retry.';
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  const clearError = (form) => {
    const box = form.querySelector('[data-vault-upload-error]');
    if (box) box.remove();
  };

  const parseResponse = async (response) => {
    let payload = null;
    try {
      payload = await response.json();
    } catch (_) {
      throw new Error(response.status === 413
        ? 'Server rejected this request size. Chunk upload could not start.'
        : 'Server returned an invalid upload response.');
    }
    if (!response.ok || !payload || payload.ok !== true) {
      throw new Error((payload && payload.error) || 'Upload failed.');
    }
    return payload;
  };

  const postForm = async (url, data) => {
    const response = await fetch(url, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    return parseResponse(response);
  };

  const runUpload = async (form) => {
    const input = form.querySelector('input[type="file"][name="file"]');
    const file = input && input.files ? input.files[0] : null;
    if (!file) throw new Error('Choose a .so or .zip file.');
    if (file.size <= 0) throw new Error('The selected file is empty.');
    if (file.size > MAX_FILE_BYTES) throw new Error('File is larger than the 50 MB application limit.');

    const extension = (file.name.split('.').pop() || '').toLowerCase();
    if (extension !== 'so' && extension !== 'zip') {
      throw new Error('Only .so and .zip files are allowed.');
    }

    const csrfInput = form.querySelector('input[name="csrf"]');
    const csrf = csrfInput ? csrfInput.value : '';
    if (!csrf) throw new Error('Security token is missing. Refresh the page and retry.');

    const mode = form.dataset.vaultChunk === 'replace' ? 'replace' : 'upload';
    const fileIdInput = form.querySelector('input[name="file_id"]');
    const fileId = mode === 'replace' && fileIdInput ? fileIdInput.value : '0';
    const id = uploadId();
    const totalChunks = Math.ceil(file.size / CHUNK_BYTES);
    const button = form.querySelector('button[type="submit"]');
    const originalText = button ? button.textContent : '';

    if (button) button.disabled = true;
    if (input) input.disabled = true;
    clearError(form);

    try {
      for (let index = 0; index < totalChunks; index += 1) {
        const start = index * CHUNK_BYTES;
        const end = Math.min(file.size, start + CHUNK_BYTES);
        const data = new FormData();
        data.append('csrf', csrf);
        data.append('upload_id', id);
        data.append('original_name', file.name);
        data.append('total_size', String(file.size));
        data.append('total_chunks', String(totalChunks));
        data.append('chunk_index', String(index));
        data.append('mode', mode);
        data.append('file_id', fileId);
        data.append('chunk', file.slice(start, end), 'chunk.bin');

        if (button) {
          const percent = Math.max(1, Math.floor(((index + 1) / totalChunks) * 95));
          button.textContent = `Uploading ${percent}%`;
        }
        await postForm('/files/chunk', data);
      }

      const finish = new FormData();
      finish.append('csrf', csrf);
      finish.append('upload_id', id);
      if (button) button.textContent = 'Finalizing 100%';
      await postForm('/files/chunk/finish', finish);

      window.location.assign('/files');
    } catch (error) {
      showError(form, error instanceof Error ? error.message : 'Upload failed. Please retry.');
      if (button) {
        button.disabled = false;
        button.textContent = originalText || (mode === 'replace' ? 'Upload new version' : 'Upload to File Manager');
      }
      if (input) input.disabled = false;
    }
  };

  document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form || !form.matches('form[data-vault-chunk]')) return;
    if (!window.fetch || !window.FormData || !window.crypto || !window.crypto.getRandomValues) return;

    event.preventDefault();
    runUpload(form).catch((error) => {
      showError(form, error instanceof Error ? error.message : 'Upload failed. Please retry.');
    });
  });
})();
