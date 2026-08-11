(function () {
  'use strict';

  var root = document.getElementById('pwa-admin');
  if (!root) return;

  root.querySelectorAll('[data-color-for]').forEach(function (picker) {
    var text = document.getElementById(picker.getAttribute('data-color-for'));
    if (!text) return;
    picker.addEventListener('input', function () { text.value = picker.value; });
    text.addEventListener('input', function () {
      if (/^#[0-9a-f]{6}$/i.test(text.value)) picker.value = text.value;
    });
  });

  function updatePreview(field, url) {
    var preview = field.querySelector('.pwa-icon-preview');
    if (!preview) return;
    preview.textContent = '';
    if (!url) {
      var empty = document.createElement('span');
      empty.textContent = field.getAttribute('data-optional-label') || '';
      preview.appendChild(empty);
      return;
    }
    var image = document.createElement('img');
    image.src = url;
    image.alt = '';
    preview.appendChild(image);
  }

  function reportFieldError(input, message) {
    if (!input) return;
    input.setCustomValidity(message);
    input.reportValidity();
  }

  root.querySelectorAll('[data-icon-field]').forEach(function (field) {
    var idInput = field.querySelector('[data-media-id]');
    var urlInput = field.querySelector('[data-media-url]');
    var choose = field.querySelector('[data-choose-media]');
    var clear = field.querySelector('[data-clear-media]');
    var expected = parseInt(field.getAttribute('data-size'), 10);

    if (urlInput) urlInput.addEventListener('input', function () {
      urlInput.setCustomValidity('');
      if (idInput) idInput.value = '0';
      updatePreview(field, urlInput.value.trim());
    });

    if (choose) choose.addEventListener('click', function () {
      if (typeof window.openMediaSelector !== 'function') return;
      var adminPath = window.ADMIN_PATH || '/adiwira';
      window.openMediaSelector({url: adminPath + '/admin/modal_img/list_modal.php?embedded=1&visibility=public'}).then(function (detail) {
        var media = detail && detail.media && typeof detail.media === 'object' ? detail.media : detail;
        if (!media || !media.url) return;
        var mime = String(media.mime || '').toLowerCase();
        var url = String(media.url || '');
        var width = parseInt(media.width, 10) || 0;
        var height = parseInt(media.height, 10) || 0;
        if ((mime && mime !== 'image/png') || !/\.png(?:\?|$)/i.test(url)) {
          reportFieldError(urlInput, field.getAttribute('data-png-error') || 'Invalid image.');
          return;
        }
        if (width && height && (width !== expected || height !== expected)) {
          reportFieldError(urlInput, field.getAttribute('data-size-error') || 'Invalid image dimensions.');
          return;
        }
        urlInput.setCustomValidity('');
        idInput.value = String(parseInt(media.id, 10) || 0);
        urlInput.value = url;
        updatePreview(field, url);
      });
    });

    if (clear) clear.addEventListener('click', function () {
      urlInput.setCustomValidity('');
      idInput.value = '0';
      urlInput.value = '';
      updatePreview(field, '');
    });
  });

  var status = document.getElementById('pwa-worker-status');
  if (status) {
    var value = status.querySelector('strong');
    if (!('serviceWorker' in navigator)) {
      value.textContent = root.getAttribute('data-worker-unsupported') || '';
      status.classList.add('is-bad');
    } else {
      navigator.serviceWorker.getRegistration('/').then(function (registration) {
        value.textContent = registration
          ? (root.getAttribute('data-worker-registered') || '')
          : (root.getAttribute('data-worker-visit') || '');
        status.classList.add(registration ? 'is-good' : 'is-bad');
      }).catch(function () {
        value.textContent = root.getAttribute('data-worker-error') || '';
        status.classList.add('is-bad');
      });
    }
  }
})();
