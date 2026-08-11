'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Events {
  constructor() { this.listeners = new Map(); }
  addEventListener(type, listener) {
    const listeners = this.listeners.get(type) || [];
    listeners.push(listener);
    this.listeners.set(type, listeners);
  }
  removeEventListener(type, listener) {
    const listeners = this.listeners.get(type) || [];
    this.listeners.set(type, listeners.filter((item) => item !== listener));
  }
  dispatch(type) {
    for (const listener of [...(this.listeners.get(type) || [])]) listener({type});
  }
}

class Worker extends Events {
  constructor(state) {
    super();
    this.state = state;
    this.messages = [];
    this.failures = 0;
  }
  postMessage(message) {
    if (this.failures > 0) {
      this.failures--;
      throw new Error('simulated postMessage failure');
    }
    this.messages.push(message);
  }
  transition(state) {
    this.state = state;
    this.dispatch('statechange');
  }
}

class Registration extends Events {
  constructor(active) {
    super();
    this.active = active;
    this.waiting = null;
    this.installing = null;
  }
}

const active = new Worker('activated');
const registration = new Registration(active);
const serviceWorker = new Events();
serviceWorker.controller = active;
serviceWorker.register = () => Promise.resolve(registration);
serviceWorker.ready = Promise.resolve(registration);

const windowEvents = new Events();
windowEvents.location = {hostname: 'example.test', protocol: 'https:'};
const warnings = [];
const context = {
  window: windowEvents,
  navigator: {serviceWorker},
  console: {warn: (...args) => warnings.push(args)},
  WeakSet,
  Promise,
};

const source = fs.readFileSync(path.join(__dirname, '../assets/js/register.js'), 'utf8');
vm.runInNewContext(source, context, {filename: 'register.js'});

const settle = () => new Promise((resolve) => setImmediate(resolve));

(async () => {
  windowEvents.dispatch('load');
  await settle();
  await settle();
  assert.equal(active.messages.length, 1, 'initial active worker warms exactly once');

  const updated = new Worker('installing');
  registration.installing = updated;
  registration.dispatch('updatefound');
  registration.dispatch('updatefound');
  updated.transition('installed');
  assert.equal(updated.messages.length, 0, 'installing worker is not warmed early');
  updated.transition('activated');
  assert.equal(updated.messages.length, 1, 'newly activated update is warmed');

  serviceWorker.controller = updated;
  serviceWorker.dispatch('controllerchange');
  updated.dispatch('statechange');
  assert.equal(updated.messages.length, 1, 'state and controller events are deduplicated');

  const controller = new Worker('activated');
  serviceWorker.controller = controller;
  serviceWorker.dispatch('controllerchange');
  serviceWorker.dispatch('controllerchange');
  assert.equal(controller.messages.length, 1, 'new controller warms exactly once');
  assert.equal(controller.messages[0].type, 'JY_PWA_WARM_CACHE');

  const retry = new Worker('activated');
  retry.failures = 1;
  serviceWorker.controller = retry;
  serviceWorker.dispatch('controllerchange');
  serviceWorker.dispatch('controllerchange');
  assert.equal(retry.messages.length, 1, 'failed warm message is retried');
  assert.equal(warnings.length, 1, 'failed warm attempt is reported once');
  console.log('RESULT: REGISTER FLOW PASS');
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
