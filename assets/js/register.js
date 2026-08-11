(function () {
  'use strict';
  if (!('serviceWorker' in navigator)) return;
  var local = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(window.location.hostname);
  if (window.location.protocol !== 'https:' && !local) return;

  var observedWorkers = new WeakSet();
  var warmedWorkers = new WeakSet();

  function warmWorker(worker) {
    if (!worker || worker.state !== 'activated' || warmedWorkers.has(worker)) return;
    warmedWorkers.add(worker);
    try {
      worker.postMessage({type: 'JY_PWA_WARM_CACHE'});
    } catch (error) {
      warmedWorkers.delete(worker);
      console.warn('[pwa] Could not warm service worker cache:', error);
    }
  }

  function observeWorker(worker) {
    if (!worker) return;
    if (worker.state === 'activated') {
      warmWorker(worker);
      return;
    }
    if (observedWorkers.has(worker)) return;
    observedWorkers.add(worker);
    var onStateChange = function () {
      if (worker.state !== 'activated') return;
      worker.removeEventListener('statechange', onStateChange);
      warmWorker(worker);
    };
    worker.addEventListener('statechange', onStateChange);
  }

  function observeRegistration(registration) {
    observeWorker(registration.active);
    observeWorker(registration.waiting);
    observeWorker(registration.installing);
    registration.addEventListener('updatefound', function () {
      observeWorker(registration.installing);
    });
  }

  window.addEventListener('load', function () {
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      warmWorker(navigator.serviceWorker.controller);
    });
    navigator.serviceWorker.register('/sw.js', {scope: '/', updateViaCache: 'none'})
      .then(function (registration) {
        observeRegistration(registration);
        return navigator.serviceWorker.ready;
      })
      .then(function (registration) {
        warmWorker(registration.active);
      })
      .catch(function (error) {
        console.warn('[pwa] Service worker registration failed:', error);
      });
  }, {once: true});
})();
