(function () {
  'use strict';

  var root = document.getElementById('pwa-admin');
  if (!root) return;
  var regenerateIcons = root.querySelector('[data-regenerate-icons]');
  function requestIconGeneration() {
    if (regenerateIcons) regenerateIcons.value = '1';
  }

  root.querySelectorAll('[data-color-for]').forEach(function (picker) {
    var text = document.getElementById(picker.getAttribute('data-color-for'));
    var affectsIcons = picker.getAttribute('data-color-for') === 'background_color';
    if (!text) return;
    picker.addEventListener('input', function () { text.value = picker.value; if (affectsIcons) requestIconGeneration(); });
    text.addEventListener('input', function () {
      if (/^#[0-9a-f]{6}$/i.test(text.value)) picker.value = text.value;
      if (affectsIcons) requestIconGeneration();
    });
  });

  root.querySelectorAll('[data-icon-source]').forEach(function (field) {
    var idInput = field.querySelector('[data-media-id]');
    var urlInput = field.querySelector('[data-media-url]');
    var preview = field.querySelector('[data-source-preview]');
    var choose = field.querySelector('[data-choose-media]');
    var clear = field.querySelector('[data-clear-media]');

    if (urlInput) urlInput.addEventListener('input', function () {
      if (idInput) idInput.value = '0';
      if (preview) preview.src = urlInput.value.trim() || field.getAttribute('data-default-preview') || '';
      requestIconGeneration();
    });

    if (choose) choose.addEventListener('click', function () {
      if (typeof window.openMediaSelector !== 'function') return;
      var adminPath = window.ADMIN_PATH || '/adiwira';
      window.openMediaSelector({url: adminPath + '/admin/modal_img/list_modal.php?embedded=1&visibility=public'}).then(function (detail) {
        var media = detail && detail.media && typeof detail.media === 'object' ? detail.media : detail;
        if (!media || !media.url) return;
        var url = String(media.url || '');
        idInput.value = String(parseInt(media.id, 10) || 0);
        urlInput.value = url;
        if (preview) preview.src = url;
        requestIconGeneration();
      });
    });

    if (clear) clear.addEventListener('click', function () {
      idInput.value = '0';
      urlInput.value = '';
      if (preview) preview.src = field.getAttribute('data-default-preview') || '';
      requestIconGeneration();
    });

    field.querySelectorAll('input[name="icon_mode"]').forEach(function (input) {
      input.addEventListener('change', requestIconGeneration);
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
