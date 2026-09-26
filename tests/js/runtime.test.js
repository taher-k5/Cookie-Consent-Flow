'use strict';

/**
 * Consent runtime regression harness.
 *
 * Run with `node tests/js/runtime.test.js` (or `composer test-js`).
 *
 * These cover the parts of cookie-banner.js where being wrong is silent: a
 * visitor whose optional content never loads, a request made before anyone was
 * asked anything, a queued sync that retries for ever, a withdrawal undone by a
 * request that was already in flight. None of those throw, and none of them are
 * visible on the page, so they are stated here rather than trusted to a
 * click-through.
 *
 * No dependencies and no build step, matching the runtime itself. The browser
 * is tests/js/dom-stub.js.
 */

const fs = require('fs');
const path = require('path');
const assert = require('assert');

const { createEnvironment } = require('./dom-stub');

const RUNTIME = fs.readFileSync(
  path.join(__dirname, '../../src/web/assets/banner/cookie-banner.js'),
  'utf8'
);

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

const BASE_CONFIG = {
  saveUrl: '/actions/cookie-consent-flow/consent/save',
  reportCookiesUrl: '/actions/cookie-consent-flow/cookie-detection/report',
  geoUrl: '/actions/cookie-consent-flow/consent/geo',
  csrfUrl: '/actions/users/session-info',
  csrfTokenName: 'CRAFT_CSRF_TOKEN',
  allCategories: ['necessary', 'analytics', 'marketing'],
  lockedCategories: ['necessary'],
  defaultCategories: ['necessary'],
  siteId: 1,
  consentExpiryDays: 180,
  policyVersion: '1',
  geoEnabled: false,
  respectGpc: true,
  respectDnt: false,
  consentMode: {
    enabled: true,
    type: 'advanced',
    signals: {
      analytics: ['analytics_storage'],
      marketing: ['ad_storage', 'ad_user_data', 'ad_personalization']
    }
  }
};

function jsonResponse(status, body) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status: status,
    json: function () { return Promise.resolve(body || {}); }
  });
}

/** A fetch that records every call and answers per-URL. */
function createFetch(routes) {
  const calls = [];

  const fetch = function (url, init) {
    calls.push({ url: url, init: init });

    const handler = Object.keys(routes).find(function (fragment) {
      return url.indexOf(fragment) !== -1;
    });

    if (handler) return routes[handler](url, init, calls.length);

    return jsonResponse(200, {});
  };

  fetch.calls = calls;
  fetch.to = function (fragment) {
    return calls.filter(function (call) { return call.url.indexOf(fragment) !== -1; });
  };
  fetch.bodyTo = function (fragment) {
    return fetch.to(fragment).map(function (call) { return JSON.parse(call.init.body); });
  };

  return fetch;
}

/** Lets a test decide when — and how — an in-flight request finishes. */
function deferred() {
  const control = {};

  control.promise = new Promise(function (resolve, reject) {
    control.resolve = resolve;
    control.reject = reject;
  });

  return control;
}

/** Drains the microtask queue, several promise chains deep. */
async function settle(turns) {
  for (let i = 0; i < (turns || 6); i++) {
    await new Promise(function (resolve) { setTimeout(resolve, 0); });
  }
}

/**
 * Builds a page, loads the runtime into it and returns everything a test needs
 * to inspect afterwards. The runtime self-initialises on load, exactly as it
 * does in a browser with a `defer`red script.
 */
function boot(options) {
  options = options || {};

  const config = Object.assign({}, BASE_CONFIG, options.config || {});
  const env = createEnvironment({
    navigator: options.navigator || {},
    config: config,
    localStorage: options.localStorage,
    readyState: options.readyState
  });
  const doc = env.document;

  // How the configuration reaches the page: the `window.cckConfig` global by
  // default, the inert JSON block the plugin now renders, or — for the
  // "runtime ran before its configuration" case — not yet at all.
  if (options.configAsBlock || options.noConfig) {
    delete env.window.cckConfig;
  }

  // A cookie has to exist for detection to have anything to report.
  env.setCookie('CraftSessionId', 'abc123');

  // Cookies already in the jar when the page loads — a previous page view's.
  Object.keys(options.cookies || {}).forEach(function (name) {
    env.setCookie(name, options.cookies[name]);
  });

  const banner = doc.createElement('div');
  banner.setAttribute('id', 'cck-banner');
  banner.setAttribute('role', options.modalBanner ? 'dialog' : 'region');
  banner.hidden = true;
  doc.body.appendChild(banner);

  const preferences = doc.createElement('div');
  preferences.setAttribute('id', 'cck-preferences');
  preferences.hidden = true;
  config.allCategories.forEach(function (key) {
    const box = doc.createElement('input');
    box.setAttribute('type', 'checkbox');
    box.setAttribute('data-category', key);
    box.disabled = config.lockedCategories.indexOf(key) !== -1;
    if (box.disabled) box.setAttribute('disabled', 'disabled');
    preferences.appendChild(box);
  });
  doc.body.appendChild(preferences);

  const gatedScript = doc.createElement('script');
  gatedScript.setAttribute('type', 'text/plain');
  gatedScript.setAttribute('data-cck-category', 'analytics');
  gatedScript.setAttribute('data-cck-src', 'https://analytics.test/a.js');
  doc.body.appendChild(gatedScript);

  const gatedFrame = doc.createElement('iframe');
  gatedFrame.setAttribute('data-cck-category', 'marketing');
  gatedFrame.setAttribute('data-cck-src', 'https://ads.test/embed');
  doc.body.appendChild(gatedFrame);

  if (options.configAsBlock) {
    const block = doc.createElement('script');
    block.setAttribute('type', 'application/json');
    block.setAttribute('id', 'cck-config');
    block.textContent = JSON.stringify(config);
    doc.body.appendChild(block);
  }

  if (options.extra) options.extra(doc, config);

  Object.keys(options.storage || {}).forEach(function (key) {
    env.window.localStorage.setItem(key, JSON.stringify(options.storage[key]));
  });

  Object.keys(options.session || {}).forEach(function (key) {
    env.window.sessionStorage.setItem(key, options.session[key]);
  });

  // Craft's session endpoint answers with a real token unless a test says
  // otherwise, so every POST below carries one the way it would in a browser.
  const fetch = createFetch(Object.assign(
    { '/users/session-info': () => jsonResponse(200, { csrfTokenValue: 'token-1' }) },
    options.routes || {}
  ));

  // The runtime feature-detects `window.fetch` before using the bare `fetch`
  // binding, so both have to be present or it silently degrades to doing
  // nothing — which would make every assertion below pass for the wrong reason.
  env.window.fetch = fetch;

  const load = function () {
    new Function('window', 'document', 'fetch', 'CustomEvent', RUNTIME)(
      env.window,
      doc,
      fetch,
      env.CustomEvent
    );
  };

  for (let i = 0; i < (options.loadTimes || 1); i++) load();

  return {
    /** Executes the runtime file again in the same page, as a second tag would. */
    loadAgain: load,
    env: env,
    document: doc,
    window: env.window,
    fetch: fetch,
    consent: env.window.CookieConsent,
    banner: banner,
    preferences: preferences,
    gatedFrame: gatedFrame,
    storage: env.window.localStorage._raw,
    session: env.window.sessionStorage._raw,

    /** The live <script> a gated placeholder was replaced by, if any. */
    activatedScript: function () {
      return doc.body.querySelectorAll('script').filter(function (node) {
        return node.getAttribute('type') !== 'text/plain' && node.getAttribute('src');
      })[0] || null;
    },

    /** Every `gtag('consent', 'update', …)` pushed, newest last. */
    consentUpdates: function () {
      return (env.window.dataLayer || []).filter(function (entry) {
        return entry && entry[0] === 'consent' && entry[1] === 'update';
      }).map(function (entry) { return entry[2]; });
    },

    events: function (name) {
      return doc.dispatched.filter(function (event) { return event.type === name; });
    },

    /** Clicks a control the way a visitor would: a click event reaching the document. */
    click: function (element) {
      return doc.dispatchEvent({ type: 'click', target: element });
    },

    /**
     * Presses a key with focus where it currently is. `element` is kept for
     * readability at the call site; as in a browser, the event reaches the
     * document's listeners, which is where the focus trap listens.
     */
    key: function (element, key, shiftKey) {
      return doc.dispatchEvent({ type: 'keydown', key: key, shiftKey: !!shiftKey, target: doc.activeElement });
    },

    /** Fires DOMContentLoaded, for pages booted in the `loading` state. */
    domReady: function () {
      doc.readyState = 'interactive';
      doc.dispatchEvent({ type: 'DOMContentLoaded' });
    },

    /** Every live <script> the runtime created from a placeholder, in document order. */
    activatedScripts: function () {
      return doc.body.querySelectorAll('script').filter(function (node) {
        const type = node.getAttribute('type');
        return type !== 'text/plain' && type !== 'application/json';
      });
    }
  };
}

const validDecision = (overrides) => Object.assign({
  v: 2,
  action: 'accept_all',
  categories: ['necessary', 'analytics', 'marketing'],
  timestamp: Date.now(),
  policyVersion: '1',
  source: 'banner'
}, overrides || {});

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

const tests = [];
const test = (name, fn) => tests.push({ name, fn });

// ---------------------------------------------------------------------------
// 1. Geo bypass — a suppressed banner must not mean permanently inert content
// ---------------------------------------------------------------------------

const geoConfig = { geoEnabled: true };

test('geo: a visitor outside the target countries gets no banner', async () => {
  const page = boot({
    config: geoConfig,
    routes: { '/consent/geo': () => jsonResponse(200, { show: false, country: 'US' }) }
  });
  await settle();

  assert.strictEqual(page.banner.hidden, true, 'banner must stay hidden');
  assert.strictEqual(page.events('cookieConsent:suppressed').length, 1);
  assert.strictEqual(page.events('cookieConsent:suppressed')[0].detail.country, 'US');
});

test('geo: optional scripts and iframes still activate outside the target countries', async () => {
  const page = boot({
    config: geoConfig,
    routes: { '/consent/geo': () => jsonResponse(200, { show: false, country: 'US' }) }
  });
  await settle();

  const script = page.activatedScript();

  assert.ok(script, 'the gated script must have been activated');
  assert.strictEqual(script.getAttribute('src'), 'https://analytics.test/a.js');
  assert.strictEqual(page.gatedFrame.getAttribute('src'), 'https://ads.test/embed');
  assert.strictEqual(page.gatedFrame.getAttribute('data-cck-activated'), 'true');
});

test('geo: Consent Mode is granted, not left on the denied default', async () => {
  const page = boot({
    config: geoConfig,
    routes: { '/consent/geo': () => jsonResponse(200, { show: false, country: 'US' }) }
  });
  await settle();

  const updates = page.consentUpdates();

  assert.strictEqual(updates.length, 1, 'exactly one consent update');
  assert.deepStrictEqual(updates[0], {
    analytics_storage: 'granted',
    ad_storage: 'granted',
    ad_user_data: 'granted',
    ad_personalization: 'granted'
  });
});

test('geo: a bypass is not a consent record', async () => {
  const page = boot({
    config: geoConfig,
    routes: { '/consent/geo': () => jsonResponse(200, { show: false, country: 'US' }) }
  });
  await settle();

  assert.deepStrictEqual(
    page.fetch.to('/consent/save'),
    [],
    'no consent may be posted for a decision the visitor never made'
  );
  assert.strictEqual(page.storage.cck_consent_1, undefined, 'nothing may be stored');
  assert.strictEqual(page.consent.hasStoredConsent(), false);
  assert.strictEqual(page.events('cookieConsent:changed').length, 0);
});

test('geo: the bypass is readable and reports content as permitted', async () => {
  const page = boot({
    config: geoConfig,
    routes: { '/consent/geo': () => jsonResponse(200, { show: false }) }
  });
  await settle();

  assert.strictEqual(page.consent.isGeoBypassed(), true);
  assert.strictEqual(page.consent.hasConsent(), true);
  assert.strictEqual(page.consent.hasConsent('analytics'), true);
  assert.strictEqual(page.consent.hasConsent('nonexistent'), false);
  assert.deepStrictEqual(page.consent.getConsentState(), {
    necessary: true,
    analytics: true,
    marketing: true
  });
});

test('geo: a visitor inside the target countries is asked as before', async () => {
  const page = boot({
    config: geoConfig,
    routes: { '/consent/geo': () => jsonResponse(200, { show: true, country: 'DE' }) }
  });
  await settle();

  assert.strictEqual(page.banner.hidden, false, 'banner must be shown');
  assert.strictEqual(page.activatedScript(), null, 'nothing may run before a decision');
  assert.strictEqual(page.gatedFrame.getAttribute('src'), null);
  assert.strictEqual(page.consent.isGeoBypassed(), false);
  assert.strictEqual(page.consent.hasConsent('analytics'), false);
  assert.deepStrictEqual(page.consentUpdates(), []);
});

test('geo: an unreachable geo endpoint still shows the banner', async () => {
  const page = boot({
    config: geoConfig,
    routes: { '/consent/geo': () => Promise.reject(new Error('offline')) }
  });
  await settle();

  assert.strictEqual(page.banner.hidden, false, 'a lookup failure must fail open');
  assert.strictEqual(page.activatedScript(), null);
});

test('geo: the answer is remembered for the tab', async () => {
  const page = boot({
    config: geoConfig,
    routes: { '/consent/geo': () => jsonResponse(200, { show: false, country: 'US' }) }
  });
  await settle();

  assert.strictEqual(page.session.cck_geo_1_1, 'hide');
});

test('geo: a later page view answering from that cache takes the same bypass path', async () => {
  const page = boot({
    config: geoConfig,
    session: { cck_geo_1_1: 'hide' },
    routes: {
      '/consent/geo': () => { throw new Error('the cached answer should have been used'); }
    }
  });
  await settle();

  assert.deepStrictEqual(page.fetch.to('/consent/geo'), [], 'no second lookup');
  assert.ok(page.activatedScript(), 'the cached bypass must activate content too');
  assert.strictEqual(page.banner.hidden, true);
  assert.strictEqual(page.consent.isGeoBypassed(), true);
});

test('geo: the cache key is namespaced per site and policy version', async () => {
  const page = boot({
    config: Object.assign({}, geoConfig, { siteId: 7, policyVersion: '2026-09-16.1234' }),
    routes: { '/consent/geo': () => jsonResponse(200, { show: false }) }
  });
  await settle();

  assert.strictEqual(page.session['cck_geo_7_2026-09-16.1234'], 'hide');
  assert.strictEqual(page.session.cck_geo_1_1, undefined, 'must not leak across sites');
});

test('geo: a real decision supersedes the bypass', async () => {
  const page = boot({
    config: geoConfig,
    routes: {
      '/consent/geo': () => jsonResponse(200, { show: false }),
      '/consent/save': () => jsonResponse(200, { success: true, visitorUuid: 'uuid-1' })
    }
  });
  await settle();

  assert.strictEqual(page.consent.isGeoBypassed(), true);

  page.consent.rejectAll();
  await settle();

  assert.strictEqual(page.consent.isGeoBypassed(), false);
  assert.strictEqual(page.consent.hasConsent('analytics'), false);
  assert.deepStrictEqual(JSON.parse(page.storage.cck_consent_1).categories, ['necessary']);
});

test('geo: GPC is applied before geo is ever consulted', async () => {
  const page = boot({
    config: geoConfig,
    navigator: { globalPrivacyControl: true },
    routes: { '/consent/save': () => jsonResponse(200, { success: true, visitorUuid: 'uuid-1' }) }
  });
  await settle();

  assert.deepStrictEqual(page.fetch.to('/consent/geo'), [], 'geo must not be asked');
  assert.strictEqual(page.banner.hidden, true);

  const saved = page.fetch.bodyTo('/consent/save')[0];
  assert.strictEqual(saved.action, 'reject_all');
  assert.strictEqual(saved.source, 'gpc');
  assert.deepStrictEqual(saved.categories, ['necessary']);
});

test('geo: disabled geo-targeting asks everyone, as before', async () => {
  const page = boot({});
  await settle();

  assert.deepStrictEqual(page.fetch.to('/consent/geo'), []);
  assert.strictEqual(page.banner.hidden, false);
});

// ---------------------------------------------------------------------------
// 2. Cookie detection must not run before there is anything to report against
// ---------------------------------------------------------------------------

test('detection: a fresh visitor triggers no request at all', async () => {
  const page = boot({});
  await settle();

  assert.deepStrictEqual(page.fetch.calls, [], 'no CSRF, session or report request before a decision');
});

test('detection: runs after Accept All', async () => {
  const page = boot({
    routes: { '/consent/save': () => jsonResponse(200, { success: true, visitorUuid: 'uuid-1' }) }
  });
  await settle();
  page.consent.acceptAll();
  await settle();

  assert.strictEqual(page.fetch.to('/cookie-detection/report').length, 1);
  assert.deepStrictEqual(
    page.fetch.bodyTo('/cookie-detection/report')[0].names,
    ['CraftSessionId']
  );
});

test('detection: runs after Reject All and after a custom decision', async () => {
  for (const act of ['rejectAll', 'savePreferences']) {
    const page = boot({
      routes: { '/consent/save': () => jsonResponse(200, { success: true }) }
    });
    await settle();
    page.consent[act]();
    await settle();

    assert.strictEqual(
      page.fetch.to('/cookie-detection/report').length,
      1,
      `${act} should report detected cookies`
    );
  }
});

test('detection: runs after a GPC-derived state, never before it', async () => {
  const page = boot({
    navigator: { globalPrivacyControl: true },
    routes: { '/consent/save': () => jsonResponse(200, { success: true }) }
  });
  await settle();

  const order = page.fetch.calls.map(function (call) {
    return call.url.indexOf('/consent/save') !== -1 ? 'save'
      : (call.url.indexOf('/cookie-detection/report') !== -1 ? 'report' : 'csrf');
  });

  assert.ok(order.indexOf('report') !== -1, 'detection should still happen');
  assert.ok(
    order.indexOf('save') < order.indexOf('report'),
    'the GPC decision is established before anything is reported'
  );
});

test('detection: runs for a returning visitor whose decision is already stored', async () => {
  const page = boot({ storage: { cck_consent_1: validDecision() } });
  await settle();

  assert.strictEqual(page.fetch.to('/cookie-detection/report').length, 1);
});

test('detection: runs under a geo bypass', async () => {
  const page = boot({
    config: geoConfig,
    routes: { '/consent/geo': () => jsonResponse(200, { show: false }) }
  });
  await settle();

  assert.strictEqual(page.fetch.to('/cookie-detection/report').length, 1);
});

test('detection: still sends a CSRF token', async () => {
  const page = boot({ storage: { cck_consent_1: validDecision() } });
  await settle();

  const report = page.fetch.to('/cookie-detection/report')[0];

  assert.strictEqual(report.init.method, 'POST');
  assert.strictEqual(report.init.headers['X-CSRF-Token'], 'token-1', 'the endpoint stays CSRF protected');
  assert.strictEqual(JSON.parse(report.init.body).CRAFT_CSRF_TOKEN, 'token-1');
});

test('detection: a rejected token is refreshed and retried exactly once', async () => {
  let attempt = 0;
  const page = boot({
    storage: { cck_consent_1: validDecision() },
    routes: {
      '/cookie-detection/report': () => {
        attempt++;

        return attempt === 1 ? jsonResponse(400, { error: 'invalid_csrf' }) : jsonResponse(200, {});
      }
    }
  });
  await settle();

  assert.strictEqual(attempt, 2, 'one retry after a stale token, not a loop');
});

// ---------------------------------------------------------------------------
// 3. Pending sync: retry what might succeed, give up on what cannot
// ---------------------------------------------------------------------------

const PENDING = 'cck_consent_1_pending';

async function syncWith(respond) {
  const page = boot({ routes: { '/consent/save': respond } });
  await settle();
  page.consent.acceptAll();
  await settle();

  return page;
}

test('sync: a network failure is queued for a later page load', async () => {
  const page = await syncWith(() => Promise.reject(new Error('offline')));

  assert.ok(page.storage[PENDING], 'a request that got no answer must be retried');
  assert.strictEqual(JSON.parse(page.storage[PENDING]).syncAttempts, 1);
});

test('sync: a 500 is queued', async () => {
  const page = await syncWith(() => jsonResponse(500, {}));

  assert.ok(page.storage[PENDING]);
});

test('sync: a 429 and a 408 are queued', async () => {
  for (const status of [408, 429]) {
    const page = await syncWith(() => jsonResponse(status, {}));

    assert.ok(page.storage[PENDING], `${status} means "later", not "no"`);
  }
});

test('sync: a permanently refused payload is not queued', async () => {
  for (const status of [400, 403, 404, 422]) {
    const page = await syncWith(() => jsonResponse(status, {}));

    assert.strictEqual(
      page.storage[PENDING],
      undefined,
      `${status} must not be retried on every page load for ever`
    );

    const failures = page.events('cookieConsent:syncFailed');
    assert.strictEqual(failures.length, 1);
    assert.strictEqual(failures[0].detail.permanent, true);
    assert.strictEqual(failures[0].detail.status, status);
  }
});

test('sync: a queued decision is retried and then cleared on success', async () => {
  const page = boot({
    storage: { cck_consent_1: validDecision(), [PENDING]: validDecision({ syncAttempts: 2 }) },
    routes: { '/consent/save': () => jsonResponse(200, { success: true, visitorUuid: 'uuid-9' }) }
  });
  await settle();

  assert.strictEqual(page.fetch.to('/consent/save').length, 1, 'the queued decision is re-sent');
  assert.strictEqual(page.storage[PENDING], undefined, 'and the queue is emptied');
  // The visitor id stays in the server's httpOnly cookie (L14): nothing on
  // the page needs it, so it is not copied where every script can read it.
  assert.strictEqual(page.storage.cck_visitor_1, undefined);
});

test('sync: retries are bounded so a page load cannot queue for ever', async () => {
  const page = boot({
    storage: { cck_consent_1: validDecision(), [PENDING]: validDecision({ syncAttempts: 5 }) },
    routes: { '/consent/save': () => jsonResponse(500, {}) }
  });
  await settle();

  assert.strictEqual(page.storage[PENDING], undefined, 'given up on after the attempt cap');

  const failures = page.events('cookieConsent:syncFailed');
  assert.strictEqual(failures[0].detail.permanent, false);
  assert.strictEqual(failures[0].detail.attempts, 6);
});

test('sync: the attempt counter never leaks into the stored decision', async () => {
  const page = await syncWith(() => jsonResponse(500, {}));

  assert.strictEqual(JSON.parse(page.storage.cck_consent_1).syncAttempts, undefined);
  assert.strictEqual(JSON.parse(page.storage[PENDING]).syncAttempts, 1);
});

test('sync: a malformed queue entry is discarded rather than posted', async () => {
  const page = boot({
    storage: { cck_consent_1: validDecision(), [PENDING]: { nonsense: true } },
    routes: { '/consent/save': () => jsonResponse(200, { success: true }) }
  });
  await settle();

  assert.deepStrictEqual(page.fetch.to('/consent/save'), []);
  assert.strictEqual(page.storage[PENDING], undefined);
});

// ---------------------------------------------------------------------------
// 4. resetConsent() must not be undone by a request already in flight
// ---------------------------------------------------------------------------

test('reset: with nothing in flight it clears everything', async () => {
  const page = boot({
    storage: { cck_consent_1: validDecision(), cck_visitor_1: 'uuid-1', [PENDING]: validDecision() },
    routes: { '/consent/save': () => jsonResponse(200, { success: true }) }
  });
  await settle();

  page.consent.resetConsent();
  await settle();

  assert.strictEqual(page.storage.cck_consent_1, undefined);
  assert.strictEqual(page.storage.cck_visitor_1, undefined);
  assert.strictEqual(page.storage[PENDING], undefined);
  assert.strictEqual(page.banner.hidden, false, 'the visitor is asked again');
});

test('reset: a stale sync failing afterwards cannot re-queue the withdrawn decision', async () => {
  const inFlight = deferred();
  const page = boot({ routes: { '/consent/save': () => inFlight.promise } });
  await settle();

  page.consent.acceptAll();
  await settle();

  page.consent.resetConsent();
  await settle();

  // Only now does the request the accept started fail.
  inFlight.reject(new Error('offline'));
  await settle();

  assert.strictEqual(
    page.storage[PENDING],
    undefined,
    'the withdrawn decision must not come back through the failure handler'
  );
  assert.strictEqual(page.storage.cck_consent_1, undefined);
});

test('reset: a stale sync succeeding afterwards cannot restore the visitor id', async () => {
  const inFlight = deferred();
  const page = boot({ routes: { '/consent/save': () => inFlight.promise } });
  await settle();

  page.consent.acceptAll();
  await settle();
  page.consent.resetConsent();
  await settle();

  inFlight.resolve({ ok: true, status: 200, json: () => Promise.resolve({ visitorUuid: 'uuid-stale' }) });
  await settle();

  assert.strictEqual(page.storage.cck_visitor_1, undefined);
});

test('reset: a new decision afterwards syncs normally', async () => {
  const inFlight = deferred();
  let respond = () => inFlight.promise;

  const page = boot({ routes: { '/consent/save': (...args) => respond(...args) } });
  await settle();

  page.consent.acceptAll();
  await settle();
  page.consent.resetConsent();
  await settle();

  respond = () => jsonResponse(200, { success: true, visitorUuid: 'uuid-new' });
  page.consent.rejectAll();
  await settle();

  inFlight.reject(new Error('offline'));
  await settle();

  assert.strictEqual(page.fetch.to('/consent/save').length, 2, 'the new decision is posted');
  assert.strictEqual(page.fetch.bodyTo('/consent/save')[1].action, 'reject_all');
  assert.deepStrictEqual(JSON.parse(page.storage.cck_consent_1).categories, ['necessary']);
  assert.strictEqual(page.storage[PENDING], undefined, 'the stale failure must not queue anything');
});

test('reset: a superseded decision cannot overwrite a newer one', async () => {
  const first = deferred();
  let respond = () => first.promise;

  const page = boot({ routes: { '/consent/save': (...args) => respond(...args) } });
  await settle();

  page.consent.acceptAll();
  await settle();

  respond = () => jsonResponse(200, { success: true, visitorUuid: 'uuid-second' });
  page.consent.rejectAll();
  await settle();

  first.reject(new Error('offline'));
  await settle();

  assert.strictEqual(page.storage[PENDING], undefined);
  assert.deepStrictEqual(JSON.parse(page.storage.cck_consent_1).categories, ['necessary']);
});

// ---------------------------------------------------------------------------
// 5. Behaviour the fixes must not have disturbed
// ---------------------------------------------------------------------------

test('regression: consent events reach both document and window listeners once', async () => {
  const page = boot({ routes: { '/consent/save': () => jsonResponse(200, { success: true }) } });
  await settle();

  const seen = [];
  page.document.addEventListener('cookieConsent:changed', (e) => seen.push(['document', e.detail]));

  page.consent.acceptAll();
  await settle();

  assert.strictEqual(seen.length, 1, 'exactly once');
  assert.strictEqual(seen[0][1].action, 'accept_all');
  assert.strictEqual(page.events('cck:consent').length, 1, 'the legacy name still fires');
});

test('regression: locked categories are always included', async () => {
  const page = boot({ routes: { '/consent/save': () => jsonResponse(200, { success: true }) } });
  await settle();

  page.consent.updateConsent({ analytics: true, necessary: false });
  await settle();

  const stored = JSON.parse(page.storage.cck_consent_1);
  assert.ok(stored.categories.indexOf('necessary') !== -1, 'locked categories cannot be declined');
  assert.strictEqual(stored.action, 'custom');
});

test('regression: unknown categories are stripped from the API and from storage', async () => {
  const page = boot({
    storage: { cck_consent_1: validDecision({ categories: ['necessary', 'analytics', 'ghost'] }) },
    routes: { '/consent/save': () => jsonResponse(200, { success: true }) }
  });
  await settle();

  assert.deepStrictEqual(page.consent.getConsent().categories, ['necessary', 'analytics']);

  page.consent.updateConsent(['analytics', 'ghost']);
  await settle();

  assert.deepStrictEqual(page.fetch.bodyTo('/consent/save')[0].categories, ['analytics', 'necessary']);
});

test('regression: a stale policy version invalidates a stored decision', async () => {
  const page = boot({ storage: { cck_consent_1: validDecision({ policyVersion: 'old' }) } });
  await settle();

  assert.strictEqual(page.consent.hasStoredConsent(), false);
  assert.strictEqual(page.banner.hidden, false, 'the visitor is asked again');
  assert.deepStrictEqual(page.fetch.calls, [], 'and nothing is reported before they answer');
});

test('regression: an expired decision invalidates a stored decision', async () => {
  const page = boot({
    storage: { cck_consent_1: validDecision({ timestamp: Date.now() - 200 * 864e5 }) }
  });
  await settle();

  assert.strictEqual(page.consent.hasStoredConsent(), false);
  assert.strictEqual(page.banner.hidden, false);
});

test('regression: Consent Mode denies what was not accepted', async () => {
  const page = boot({ routes: { '/consent/save': () => jsonResponse(200, { success: true }) } });
  await settle();

  page.consent.updateConsent({ analytics: true });
  await settle();

  assert.deepStrictEqual(page.consentUpdates()[0], {
    analytics_storage: 'granted',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied'
  });
});

test('regression: storage is namespaced per site', async () => {
  const page = boot({ config: { siteId: 4 }, routes: { '/consent/save': () => jsonResponse(200, {}) } });
  await settle();

  page.consent.acceptAll();
  await settle();

  assert.ok(page.storage.cck_consent_4, 'written under this site');
  assert.strictEqual(page.storage.cck_consent_1, undefined, 'and not another');
});

test('regression: the public API keeps its documented and legacy names', async () => {
  const page = boot({});
  await settle();

  assert.strictEqual(page.window.CookieConsent, page.window.CookieConsentKit);
  ['acceptAll', 'rejectAll', 'savePreferences', 'updateConsent', 'resetConsent',
    'hasConsent', 'getConsentState', 'openPreferences', 'closePreferences',
    'refreshGatedContent'].forEach(function (method) {
    assert.strictEqual(typeof page.consent[method], 'function', `${method} must remain public`);
  });
});

// ---------------------------------------------------------------------------
// 6. H1 — one runtime per page, however many times the file is included
// ---------------------------------------------------------------------------

const saved = () => jsonResponse(200, { success: true });

/** A button inside the banner that the delegated click handler acts on. */
function addAction(doc, action) {
  const button = doc.createElement('button');
  button.setAttribute('data-cck-action', action);
  doc.getElementById('cck-banner').appendChild(button);

  return button;
}

test('H1: loading the file twice leaves one runtime and one set of listeners', async () => {
  const page = boot({ loadTimes: 2, routes: { '/consent/save': saved } });
  await settle();

  assert.strictEqual(page.document.listenerCount('click'), 1, 'one delegated click handler');
  assert.strictEqual(page.document.listenerCount('keydown'), 1, 'one Escape handler');
  assert.strictEqual(page.events('cookieConsent:ready').length, 1, 'init ran once');
});

test('H1: a click after a double include saves exactly once', async () => {
  let accept;
  const page = boot({
    loadTimes: 2,
    routes: { '/consent/save': saved },
    extra: (doc) => { accept = addAction(doc, 'accept-all'); }
  });
  await settle();

  page.click(accept);
  await settle();

  assert.strictEqual(page.fetch.to('/consent/save').length, 1, 'one consent record per decision');
  assert.strictEqual(page.events('cookieConsent:changed').length, 1);
});

test('H1: a third include later in the page still changes nothing', async () => {
  const page = boot({ routes: { '/consent/save': saved } });
  await settle();

  const first = page.window.CookieConsent;
  page.loadAgain();
  page.loadAgain();
  await settle();

  assert.strictEqual(page.window.CookieConsent, first, 'the running instance is kept');
  assert.strictEqual(page.document.listenerCount('click'), 1);
});

test('H1: a placeholder object named CookieConsent is replaced, not mistaken for the runtime', async () => {
  const page = boot({});
  await settle();

  // A site stub assigned before (or instead of) the runtime.
  page.window.CookieConsent = { queued: [] };
  page.loadAgain();
  await settle();

  assert.strictEqual(typeof page.window.CookieConsent.acceptAll, 'function');
  assert.strictEqual(page.window.CookieConsent._isCckRuntime, true);
});

test('H1: a runtime that arrives before its configuration waits for it', async () => {
  const page = boot({ noConfig: true, readyState: 'loading', routes: { '/consent/save': saved } });
  await settle();

  assert.strictEqual(page.events('cookieConsent:ready').length, 0, 'nothing runs without configuration');

  // The configuration block is reached later in the document.
  page.window.cckConfig = Object.assign({}, BASE_CONFIG);
  page.domReady();
  await settle();

  assert.strictEqual(page.events('cookieConsent:ready').length, 1);

  page.consent.acceptAll();
  await settle();

  assert.ok(page.storage.cck_consent_1, 'site-namespaced storage, not the legacy key');
  assert.strictEqual(page.storage.cck_consent, undefined);
  assert.strictEqual(page.fetch.to('/consent/save').length, 1, 'save URL came from the configuration');
});

test('H1: the configuration is read from the inert JSON block', async () => {
  const page = boot({ configAsBlock: true, config: { siteId: 3 }, routes: { '/consent/save': saved } });
  await settle();

  assert.strictEqual(page.window.cckConfig, undefined, 'no global is needed');

  page.consent.rejectAll();
  await settle();

  assert.ok(page.storage.cck_consent_3);
  assert.strictEqual(page.fetch.to('/consent/save').length, 1);
});

// ---------------------------------------------------------------------------
// 7. M1 — a geo answer that arrives after a decision is ignored
// ---------------------------------------------------------------------------

function geoPending(answer) {
  const lookup = deferred();
  const page = boot({
    config: geoConfig,
    routes: {
      '/consent/geo': () => lookup.promise,
      '/consent/save': saved
    }
  });

  return {
    page,
    answer: async () => {
      lookup.resolve({ ok: true, status: 200, json: () => Promise.resolve(answer) });
      await settle();
    }
  };
}

const NOT_TARGETED = { show: false, country: 'US' };

test('M1: geo pending → reject → a late "not targeted" answer activates nothing', async () => {
  const { page, answer } = geoPending(NOT_TARGETED);
  await settle();

  page.consent.rejectAll();
  await settle();
  const updatesBefore = page.consentUpdates().length;

  await answer();

  assert.strictEqual(page.activatedScript(), null, 'no optional script after a rejection');
  assert.strictEqual(page.gatedFrame.getAttribute('src'), null, 'no optional iframe after a rejection');
  assert.strictEqual(page.consent.isGeoBypassed(), false);
  assert.strictEqual(page.consentUpdates().length, updatesBefore, 'no late grant sent to Google');
  assert.strictEqual(page.events('cookieConsent:suppressed').length, 0);
});

test('M1: geo pending → accept → a late "targeted" answer does not re-show the banner', async () => {
  const { page, answer } = geoPending({ show: true, country: 'DE' });
  await settle();

  page.consent.acceptAll();
  await settle();
  await answer();

  assert.strictEqual(page.banner.hidden, true, 'an answered question is not asked again');
  assert.strictEqual(page.events('cookieConsent:shown').length, 0);
});

test('M1: geo pending → custom → the custom choice stands', async () => {
  const { page, answer } = geoPending(NOT_TARGETED);
  await settle();

  page.consent.updateConsent({ analytics: true });
  await settle();
  await answer();

  assert.ok(page.activatedScript(), 'analytics was chosen');
  assert.strictEqual(page.gatedFrame.getAttribute('src'), null, 'marketing was not');
  assert.deepStrictEqual(page.consent.getConsentState(), { necessary: true, analytics: true, marketing: false });
});

test('M1: geo pending → reset → the stale answer is ignored and a fresh lookup decides', async () => {
  let calls = 0;
  const first = deferred();
  const page = boot({
    config: geoConfig,
    storage: { cck_consent_1: validDecision({ action: 'reject_all', categories: ['necessary'] }) },
    routes: {
      '/consent/geo': () => (++calls === 1 ? first.promise : jsonResponse(200, { show: true, country: 'DE' })),
      '/consent/save': saved
    }
  });
  await settle();

  // A stored decision means no lookup yet; reset starts the first one.
  page.consent.resetConsent();
  // …and a second reset, before it answers, supersedes it.
  page.consent.resetConsent();
  await settle();

  first.resolve({ ok: true, status: 200, json: () => Promise.resolve(NOT_TARGETED) });
  await settle();

  assert.strictEqual(page.activatedScript(), null, 'the superseded "not targeted" answer is not applied');
  assert.strictEqual(page.banner.hidden, false, 'the current lookup shows the banner');
});

test('M1: a geo failure after a decision does not re-show the banner', async () => {
  const lookup = deferred();
  const page = boot({ config: geoConfig, routes: { '/consent/geo': () => lookup.promise, '/consent/save': saved } });
  await settle();

  page.consent.rejectAll();
  await settle();

  lookup.reject(new Error('offline'));
  await settle();

  assert.strictEqual(page.banner.hidden, true);
});

// ---------------------------------------------------------------------------
// 8. M5 — reset withdraws Google consent too
// ---------------------------------------------------------------------------

const DENIED = {
  analytics_storage: 'denied',
  ad_storage: 'denied',
  ad_user_data: 'denied',
  ad_personalization: 'denied'
};

test('M5: accept → reset sends every optional signal back to denied', async () => {
  const page = boot({ routes: { '/consent/save': saved } });
  await settle();

  page.consent.acceptAll();
  await settle();
  page.consent.resetConsent();
  await settle();

  const updates = page.consentUpdates();
  assert.strictEqual(updates[0].analytics_storage, 'granted', 'accept granted first');
  assert.deepStrictEqual(updates[updates.length - 1], DENIED, 'reset denies afterwards');
});

test('M5: the denial is sent before the reset event, so listeners see the withdrawn state', async () => {
  const page = boot({ storage: { cck_consent_1: validDecision() } });
  await settle();

  let atReset = null;
  page.document.addEventListener('cookieConsent:reset', () => { atReset = page.consentUpdates().slice(-1)[0]; });
  page.consent.resetConsent();

  assert.deepStrictEqual(atReset, DENIED);
});

test('M5: custom → reset and reject → reset both end denied', async () => {
  for (const decide of [(c) => c.updateConsent({ marketing: true }), (c) => c.rejectAll()]) {
    const page = boot({ routes: { '/consent/save': saved } });
    await settle();

    decide(page.consent);
    await settle();
    page.consent.resetConsent();
    await settle();

    assert.deepStrictEqual(page.consentUpdates().slice(-1)[0], DENIED);
  }
});

test('M5: repeated resets, and a reset before any decision, stay denied and do not throw', async () => {
  const page = boot({});
  await settle();

  page.consent.resetConsent();
  page.consent.resetConsent();
  await settle();

  const updates = page.consentUpdates();
  assert.strictEqual(updates.length, 2);
  updates.forEach((update) => assert.deepStrictEqual(update, DENIED));
});

test('M5: a locked category keeps its signal granted through a reset', async () => {
  const page = boot({
    config: {
      consentMode: {
        enabled: true,
        type: 'advanced',
        signals: { necessary: ['security_storage'], analytics: ['analytics_storage'] }
      }
    },
    storage: { cck_consent_1: validDecision() }
  });
  await settle();

  page.consent.resetConsent();

  assert.deepStrictEqual(page.consentUpdates().slice(-1)[0], {
    security_storage: 'granted',
    analytics_storage: 'denied'
  });
});

// ---------------------------------------------------------------------------
// 9. M6 — the focus trap holds in both directions
// ---------------------------------------------------------------------------

/** A preference centre with a panel wrapper and three controls, one hidden and one disabled. */
function withDialogControls(doc) {
  const prefs = doc.getElementById('cck-preferences');
  const panel = doc.createElement('div');
  panel.setAttribute('class', 'cck-preferences__panel');

  const close = doc.createElement('button');
  close.setAttribute('id', 'close');
  const disabled = doc.createElement('button');
  disabled.setAttribute('id', 'disabled');
  disabled.setAttribute('disabled', 'disabled');
  disabled.disabled = true;
  const hiddenWrap = doc.createElement('div');
  hiddenWrap.hidden = true;
  const hiddenButton = doc.createElement('button');
  hiddenButton.setAttribute('id', 'hidden');
  hiddenWrap.appendChild(hiddenButton);
  const save = doc.createElement('button');
  save.setAttribute('id', 'save');

  panel.appendChild(close);
  panel.appendChild(disabled);
  panel.appendChild(hiddenWrap);
  panel.appendChild(save);
  prefs.appendChild(panel);
}

function openTrapped() {
  const page = boot({ extra: withDialogControls });
  page.consent.openPreferences();

  return page;
}

test('M6: focus starts on the dialog panel', async () => {
  const page = openTrapped();

  assert.strictEqual(page.document.activeElement.getAttribute('class'), 'cck-preferences__panel');
});

test('M6: Shift+Tab straight after opening wraps to the last control instead of escaping', async () => {
  const page = openTrapped();

  const allowed = page.key(page.preferences, 'Tab', true);

  assert.strictEqual(allowed, false, 'the default move out of the dialog is prevented');
  assert.strictEqual(page.document.activeElement.getAttribute('id'), 'save');
});

/** The first control a keyboard user reaches: the first enabled category switch. */
const firstControl = (page) => page.preferences.querySelectorAll('input[type="checkbox"][data-category]')
  .filter((box) => !box.disabled)[0];

test('M6: Tab from the panel is left to the browser, whose next stop is inside the dialog', async () => {
  const page = openTrapped();

  // The wrapper is the dialog's first element, so the browser's own next tab
  // stop is the first control inside it; the trap only handles the edges.
  assert.strictEqual(page.key(page.preferences, 'Tab'), true);
  assert.ok(page.preferences.contains(firstControl(page)));
});

test('M6 (final audit): a Tab on a <summary> inside the dialog is not hijacked', async () => {
  const page = boot({
    extra: (doc) => {
      // close, then a category's "Cookies used" disclosure, then save — the
      // order the real preference centre renders them in.
      const panel = doc.createElement('div');
      panel.setAttribute('class', 'cck-preferences__panel');
      const close = doc.createElement('button');
      close.setAttribute('id', 'close');
      const details = doc.createElement('details');
      const summary = doc.createElement('summary');
      summary.setAttribute('id', 'cookies-used');
      details.appendChild(summary);
      const save = doc.createElement('button');
      save.setAttribute('id', 'save');
      panel.appendChild(close);
      panel.appendChild(details);
      panel.appendChild(save);
      doc.getElementById('cck-preferences').appendChild(panel);
    }
  });
  page.consent.openPreferences();
  page.document.getElementById('cookies-used').focus();

  assert.strictEqual(page.key(page.preferences, 'Tab'), true, 'the browser moves on to the next control');
  assert.strictEqual(page.key(page.preferences, 'Tab', true), true, 'and back');
});

test('M6 (final audit): focus that left the dialog (an overlay click) is brought back by Tab', async () => {
  const page = openTrapped();

  page.document.activeElement = page.document.body; // what clicking the overlay does

  assert.strictEqual(page.key(page.document.body, 'Tab'), false, 'the move into the page behind is prevented');
  assert.ok(page.preferences.contains(page.document.activeElement), 'focus is back inside the dialog');

  page.document.activeElement = page.document.body;
  page.key(page.document.body, 'Tab', true);
  assert.strictEqual(page.document.activeElement.getAttribute('id'), 'save', 'Shift+Tab lands on the last control');
});

test('M6: Tab wraps from the last control and Shift+Tab from the first', async () => {
  const page = openTrapped();
  const byId = (id) => page.document.getElementById(id);

  byId('save').focus();
  assert.strictEqual(page.key(page.preferences, 'Tab'), false);
  assert.strictEqual(page.document.activeElement, firstControl(page));

  assert.strictEqual(page.key(page.preferences, 'Tab', true), false);
  assert.strictEqual(page.document.activeElement.getAttribute('id'), 'save');
});

test('M6: a Tab between two inner controls is left to the browser', async () => {
  const page = openTrapped();

  firstControl(page).focus();

  assert.strictEqual(page.key(page.preferences, 'Tab'), true, 'only the edges are intercepted');
});

test('M6: disabled and hidden controls are never focus targets', async () => {
  const page = openTrapped();

  page.key(page.preferences, 'Tab', true);
  const target = page.document.activeElement.getAttribute('id');

  assert.notStrictEqual(target, 'disabled');
  assert.notStrictEqual(target, 'hidden');
});

test('M6: Escape closes the preference centre, records nothing, and releases the trap', async () => {
  const page = openTrapped();
  const opener = page.document.createElement('button');
  // The opener has to be outside the dialog and focused before it opens.
  page.consent.closePreferences();
  page.document.body.appendChild(opener);
  opener.focus();
  page.consent.openPreferences();

  page.document.dispatchEvent({ type: 'keydown', key: 'Escape' });

  assert.strictEqual(page.preferences.hidden, true);
  assert.strictEqual(page.document.activeElement, opener, 'focus returns to what opened it');
  assert.strictEqual(page.preferences.listenerCount('keydown'), 0, 'no trap left behind');
  assert.deepStrictEqual(page.fetch.to('/consent/save'), []);
});

test('M7 (final audit): deciding in a bar layout moves focus to the site\'s preferences control', async () => {
  let accept;
  let footer;
  const page = boot({
    routes: { '/consent/save': saved },
    extra: (doc) => {
      accept = addAction(doc, 'accept-all');
      footer = doc.createElement('button');
      footer.setAttribute('data-cck-action', 'open-preferences');
      doc.body.appendChild(footer);
    }
  });
  await settle();

  accept.focus();
  page.click(accept);

  assert.strictEqual(page.banner.hidden, true);
  assert.strictEqual(page.document.activeElement, footer, 'not left on the hidden Accept button');
});

test('M7 (final audit): with no preferences control, focus is released from the hidden banner', async () => {
  let reject;
  const page = boot({ routes: { '/consent/save': saved }, extra: (doc) => { reject = addAction(doc, 'reject-all'); } });
  await settle();

  reject.focus();
  page.click(reject);

  assert.notStrictEqual(page.document.activeElement, reject);
  assert.ok(!page.banner.contains(page.document.activeElement));
});

// ---------------------------------------------------------------------------
// 10. L1 — `ready` is deterministic, and late listeners still hear it
// ---------------------------------------------------------------------------

test('L1: ready fires once for a GPC visitor, carrying the implied rejection', async () => {
  const page = boot({ navigator: { globalPrivacyControl: true }, routes: { '/consent/save': saved } });
  await settle();

  const ready = page.events('cookieConsent:ready');
  assert.strictEqual(ready.length, 1);
  assert.strictEqual(ready[0].detail.action, 'reject_all');
  assert.strictEqual(ready[0].detail.source, 'gpc');
});

test('L1: ready fires once for a DNT visitor when DNT is honoured', async () => {
  const page = boot({
    config: { respectDnt: true },
    navigator: { doNotTrack: '1' },
    routes: { '/consent/save': saved }
  });
  await settle();

  assert.strictEqual(page.events('cookieConsent:ready').length, 1);
  assert.strictEqual(page.events('cookieConsent:ready')[0].detail.source, 'dnt');
});

test('L1: ready fires exactly once in each of the three starting states', async () => {
  for (const options of [{}, { storage: { cck_consent_1: validDecision() } }, { navigator: { globalPrivacyControl: true } }]) {
    const page = boot(Object.assign({ routes: { '/consent/save': saved } }, options));
    await settle();

    page.consent.acceptAll();
    await settle();

    assert.strictEqual(page.events('cookieConsent:ready').length, 1, 'a later decision does not fire ready again');
  }
});

test('L1: onReady() calls back immediately when attached after ready', async () => {
  const page = boot({ storage: { cck_consent_1: validDecision() } });
  await settle();

  let heard = null;
  page.consent.onReady((detail) => { heard = detail; });

  assert.ok(page.consent.isReady());
  assert.strictEqual(heard.action, 'accept_all');
});

test('L1: onReady() attached before initialisation fires once on ready', async () => {
  const page = boot({ noConfig: true, readyState: 'loading' });

  let calls = 0;
  page.consent.onReady(() => { calls++; });
  assert.strictEqual(page.consent.isReady(), false);

  page.window.cckConfig = Object.assign({}, BASE_CONFIG);
  page.domReady();
  await settle();

  assert.strictEqual(calls, 1);
});

// ---------------------------------------------------------------------------
// 11. L2 — storage reads and writes agree about where a value lives
// ---------------------------------------------------------------------------

test('L2: readable but unwritable localStorage — the decision survives a reload via the cookie', async () => {
  const page = boot({ localStorage: { failWrites: true }, routes: { '/consent/save': saved } });
  await settle();

  page.consent.acceptAll();
  await settle();

  assert.ok(page.env.cookies.cck_consent_1, 'written to the fallback cookie');
  assert.strictEqual(page.consent.hasStoredConsent(), true, 'and read back from it');

  // The next page view: the same cookie jar, the same failing storage.
  const reload = boot({
    localStorage: { failWrites: true },
    cookies: { cck_consent_1: page.env.cookies.cck_consent_1 },
    routes: { '/consent/save': saved }
  });
  await settle();

  assert.strictEqual(reload.consent.getConsent().action, 'accept_all');
  assert.strictEqual(reload.banner.hidden, true, 'the banner does not come back on every page');
  assert.deepStrictEqual(reload.fetch.to('/consent/save'), [], 'and no duplicate record is posted');
});

test('L2: localStorage unavailable entirely — the cookie fallback round-trips', async () => {
  const page = boot({ localStorage: { broken: true }, routes: { '/consent/save': saved } });
  await settle();

  page.consent.rejectAll();
  await settle();

  assert.strictEqual(page.consent.getConsent().action, 'reject_all');
  assert.strictEqual(page.banner.hidden, true);
});

test('L2: a successful localStorage write retires the old cookie copy', async () => {
  const page = boot({ routes: { '/consent/save': saved } });
  page.env.setCookie('cck_consent_1', encodeURIComponent(JSON.stringify(validDecision({ action: 'reject_all' }))));
  await settle();

  page.consent.acceptAll();
  await settle();

  assert.strictEqual(page.env.cookies.cck_consent_1, undefined, 'no stale fallback left to disagree');
  assert.strictEqual(page.consent.getConsent().action, 'accept_all');
});

test('L2: a returning visitor whose decision is only in the cookie is recognised', async () => {
  const page = boot({
    cookies: { cck_consent_1: encodeURIComponent(JSON.stringify(validDecision())) }
  });
  await settle();

  assert.strictEqual(page.consent.hasStoredConsent(), true);
  assert.ok(page.activatedScript(), 'and their consent is acted on');
});

// ---------------------------------------------------------------------------
// 12. L3 / L4 — one CSRF request at a time; only a CSRF 400 is retried
// ---------------------------------------------------------------------------

test('L3: a decision needing two POSTs fetches one CSRF token', async () => {
  const page = boot({ routes: { '/consent/save': saved } });
  await settle();

  page.consent.acceptAll();
  await settle();

  assert.strictEqual(page.fetch.to('/users/session-info').length, 1, 'save and report share one token request');
  assert.strictEqual(page.fetch.to('/consent/save').length, 1);
  assert.strictEqual(page.fetch.to('/cookie-detection/report').length, 1);
});

test('L3: a failed token request is retried by the next caller, not remembered', async () => {
  let attempt = 0;
  const page = boot({
    routes: {
      '/users/session-info': () => (++attempt === 1 ? Promise.reject(new Error('offline')) : jsonResponse(200, { csrfTokenValue: 'token-2' })),
      '/consent/save': saved
    }
  });
  await settle();

  page.consent.acceptAll();
  await settle();
  page.consent.rejectAll();
  await settle();

  const lastSave = page.fetch.to('/consent/save').slice(-1)[0];
  assert.strictEqual(lastSave.init.headers['X-CSRF-Token'], 'token-2');
});

test('L4: a 400 that is not a CSRF refusal is not retried', async () => {
  let posts = 0;
  const page = boot({
    routes: { '/consent/save': () => { posts++; return jsonResponse(400, { error: 'invalid_action' }); } }
  });
  await settle();

  page.consent.acceptAll();
  await settle();

  assert.strictEqual(posts, 1, 'a refused payload is sent once');
  assert.strictEqual(page.fetch.to('/users/session-info').length, 1, 'and no fresh token is fetched for it');
  assert.strictEqual(page.events('cookieConsent:syncFailed')[0].detail.permanent, true);
});

test('L4: an invalid_csrf 400 is refreshed and retried exactly once', async () => {
  let posts = 0;
  const page = boot({
    routes: {
      '/consent/save': () => (++posts === 1 ? jsonResponse(400, { error: 'invalid_csrf' }) : saved())
    }
  });
  await settle();

  page.consent.acceptAll();
  await settle();

  assert.strictEqual(posts, 2);
  assert.strictEqual(page.storage[PENDING], undefined);
});

// ---------------------------------------------------------------------------
// 13. L5 / L6 / L7 — activation rules, order and CSP nonces
// ---------------------------------------------------------------------------

function gated(doc, category, attrs, text) {
  const node = doc.createElement('script');
  node.setAttribute('type', 'text/plain');
  node.setAttribute('data-cck-category', category);
  Object.keys(attrs || {}).forEach((name) => node.setAttribute(name, attrs[name]));
  node.textContent = text || '';
  doc.body.appendChild(node);

  return node;
}

test('L5: locked-category content runs before a decision; optional content does not', async () => {
  const page = boot({
    extra: (doc) => { gated(doc, 'necessary', { 'data-cck-src': 'https://first.test/essential.js' }); }
  });
  await settle();

  const srcs = page.activatedScripts().map((node) => node.getAttribute('src'));
  assert.ok(srcs.indexOf('https://first.test/essential.js') !== -1, 'strictly necessary content is not held back');
  assert.ok(srcs.indexOf('https://analytics.test/a.js') === -1, 'optional content waits for consent');
  assert.deepStrictEqual(page.fetch.calls, [], 'and still no request is made before the visitor answers');
});

test('L5: before a decision, Google hears only the locked categories\' grants', async () => {
  const page = boot({
    config: {
      consentMode: { enabled: true, type: 'advanced', signals: { necessary: ['security_storage'], analytics: ['analytics_storage'] } }
    }
  });
  await settle();

  assert.deepStrictEqual(page.consentUpdates(), [{ security_storage: 'granted' }]);
});

test('L6: an inline script waits for the external script before it', async () => {
  const page = boot({
    storage: { cck_consent_1: validDecision() },
    extra: (doc) => {
      gated(doc, 'analytics', { 'data-cck-src': 'https://lib.test/lib.js' });
      gated(doc, 'analytics', {}, 'window.libInit = true;');
    }
  });
  await settle();

  const lib = page.activatedScripts().find((node) => node.getAttribute('src') === 'https://lib.test/lib.js');
  assert.ok(lib, 'the library is inserted');
  assert.strictEqual(lib.async, false, 'and is not async, so it executes in order');

  const inlineBefore = page.activatedScripts().filter((node) => node.text === 'window.libInit = true;');
  assert.strictEqual(inlineBefore.length, 0, 'the init snippet is not inserted before its library loads');

  page.activatedScripts().forEach((node) => { if (node.getAttribute('src')) node.dispatchEvent({ type: 'load' }); });

  const inlineAfter = page.activatedScripts().filter((node) => node.text === 'window.libInit = true;');
  assert.strictEqual(inlineAfter.length, 1, 'and runs once it has');
});

test('L6: a failed external script does not block the scripts after it', async () => {
  const page = boot({
    storage: { cck_consent_1: validDecision() },
    extra: (doc) => {
      gated(doc, 'analytics', { 'data-cck-src': 'https://lib.test/missing.js' });
      gated(doc, 'analytics', {}, 'window.after = true;');
    }
  });
  await settle();

  page.activatedScripts().forEach((node) => { if (node.getAttribute('src')) node.dispatchEvent({ type: 'error' }); });

  assert.strictEqual(page.activatedScripts().filter((node) => node.text === 'window.after = true;').length, 1);
});

test('L6: an explicit async placeholder stays async and does not hold up the queue', async () => {
  const page = boot({
    storage: { cck_consent_1: validDecision() },
    extra: (doc) => {
      gated(doc, 'analytics', { 'data-cck-src': 'https://lib.test/independent.js', async: '' });
      gated(doc, 'analytics', {}, 'window.next = true;');
    }
  });
  await settle();

  const independent = page.activatedScripts().find((node) => node.getAttribute('src') === 'https://lib.test/independent.js');
  assert.strictEqual(independent.async, true);

  // The page's own ordered analytics script still comes first; once it has
  // loaded, the inline script runs even though the async one never reports.
  page.activatedScripts()
    .filter((node) => node.getAttribute('src') === 'https://analytics.test/a.js')
    .forEach((node) => node.dispatchEvent({ type: 'load' }));

  assert.strictEqual(page.activatedScripts().filter((node) => node.text === 'window.next = true;').length, 1);
});

test('L6: repeated activation passes never insert a placeholder twice', async () => {
  const page = boot({ routes: { '/consent/save': saved } });
  await settle();

  page.consent.acceptAll();
  page.consent.refreshGatedContent();
  page.consent.updateConsent({ analytics: true, marketing: true });
  await settle();

  const analytics = page.activatedScripts().filter((node) => node.getAttribute('src') === 'https://analytics.test/a.js');
  assert.strictEqual(analytics.length, 1);
});

test('L7: an activated script carries the placeholder\'s CSP nonce', async () => {
  const page = boot({
    storage: { cck_consent_1: validDecision() },
    extra: (doc) => {
      const node = gated(doc, 'analytics', { 'data-cck-src': 'https://lib.test/nonced.js' });
      // As in a browser after parsing: the attribute is blanked, the property keeps the value.
      node.setAttribute('nonce', '');
      node.nonce = 'r4nd0m';
    }
  });
  await settle();

  const script = page.activatedScripts().find((node) => node.getAttribute('src') === 'https://lib.test/nonced.js');
  assert.strictEqual(script.nonce, 'r4nd0m');
  assert.strictEqual(script.getAttribute('nonce'), null, 'the blanked attribute is not copied over it');
});

// ---------------------------------------------------------------------------
// 14. L14 and markup contract
// ---------------------------------------------------------------------------

test('L14: the visitor id returned by an older server is never written to storage', async () => {
  const page = boot({ routes: { '/consent/save': () => jsonResponse(200, { success: true, visitorUuid: 'uuid-legacy' }) } });
  await settle();

  page.consent.acceptAll();
  await settle();

  assert.strictEqual(page.storage.cck_visitor_1, undefined);
});

test('controls written as links do not also navigate', async () => {
  let link;
  const page = boot({
    routes: { '/consent/save': saved },
    extra: (doc) => { link = addAction(doc, 'reject-all'); }
  });
  await settle();

  assert.strictEqual(page.click(link), false, 'the default action is prevented');
  await settle();
  assert.strictEqual(page.consent.getConsent().action, 'reject_all');
});

test('unrelated clicks are left alone', async () => {
  const page = boot({});
  await settle();

  const other = page.document.createElement('a');
  page.document.body.appendChild(other);

  assert.strictEqual(page.click(other), true);
});

// ---------------------------------------------------------------------------
// 15. Final audit — sync outbox, stored-state validation, gating edges
// ---------------------------------------------------------------------------

test('final: the decision is queued before the request, so an aborted request loses nothing', async () => {
  const never = deferred(); // a request the page navigates away from: no handler ever runs
  const page = boot({ routes: { '/consent/save': () => never.promise } });
  await settle();

  page.consent.acceptAll();
  await settle();

  const queued = JSON.parse(page.storage[PENDING]);
  assert.strictEqual(queued.action, 'accept_all', 'already in the outbox while the request is in flight');
  assert.strictEqual(queued.syncAttempts, 0);
});

test('final: a newer decision replaces an older queued one, so the older is never re-sent', async () => {
  const never = deferred();
  const page = boot({
    storage: { cck_consent_1: validDecision(), [PENDING]: validDecision({ action: 'accept_all', syncAttempts: 1 }) },
    routes: { '/consent/save': () => never.promise }
  });
  await settle();

  page.consent.rejectAll();
  await settle();

  assert.strictEqual(JSON.parse(page.storage[PENDING]).action, 'reject_all');
});

test('final: the save carries the policy version the page showed, and outlives the page', async () => {
  const page = boot({ config: { policyVersion: '2026-09-26.110005' }, routes: { '/consent/save': saved } });
  await settle();

  page.consent.acceptAll();
  await settle();

  const call = page.fetch.to('/consent/save')[0];
  assert.strictEqual(JSON.parse(call.init.body).policyVersion, '2026-09-26.110005');
  assert.strictEqual(call.init.keepalive, true);
});

test('final: an unreachable session endpoint makes the save retryable, not a permanent drop', async () => {
  const page = boot({
    routes: {
      '/users/session-info': () => Promise.reject(new Error('offline')),
      '/consent/save': () => { throw new Error('must not post without a token'); }
    }
  });
  await settle();

  page.consent.acceptAll();
  await settle();

  assert.deepStrictEqual(page.fetch.to('/consent/save'), [], 'no doomed POST with an empty token');
  assert.strictEqual(JSON.parse(page.storage[PENDING]).syncAttempts, 1, 'queued for a later page view');
  assert.strictEqual(page.events('cookieConsent:syncFailed').length, 0, 'not reported as permanent');
});

test('final: a second invalid_csrf right after a fresh token is retryable', async () => {
  const page = boot({ routes: { '/consent/save': () => jsonResponse(400, { error: 'invalid_csrf' }) } });
  await settle();

  page.consent.acceptAll();
  await settle();

  assert.strictEqual(page.fetch.to('/consent/save').length, 2);
  assert.ok(page.storage[PENDING], 'kept for another attempt');
});

test('final: a decision dated in the future, or undated, does not count while expiry is on', async () => {
  for (const timestamp of [Date.now() + 30 * 864e5, 0, undefined, 'yesterday']) {
    const stored = validDecision({ timestamp: timestamp });
    if (timestamp === undefined) delete stored.timestamp;

    const page = boot({ storage: { cck_consent_1: stored } });
    await settle();

    assert.strictEqual(page.consent.hasStoredConsent(), false, String(timestamp));
    assert.strictEqual(page.activatedScript(), null, 'nothing optional runs: ' + String(timestamp));
  }
});

test('final: with expiry off, an undated decision still counts', async () => {
  const stored = validDecision();
  delete stored.timestamp;
  const page = boot({ config: { consentExpiryDays: 0 }, storage: { cck_consent_1: stored } });
  await settle();

  assert.strictEqual(page.consent.hasStoredConsent(), true);
});

test('final: without configuration, nothing stored is trusted as consent', async () => {
  const page = boot({ noConfig: true, storage: { cck_consent_1: validDecision({ categories: ['anything'] }) } });
  page.preferences.childNodes.length = 0; // no rendered categories to fall back on either
  await settle();

  assert.strictEqual(page.consent.getConsent(), null);
});

test('final: tampered storage cannot add a category the site does not have', async () => {
  const page = boot({ storage: { cck_consent_1: validDecision({ categories: ['necessary', '__proto__', 'constructor', 'x'] }) } });
  await settle();

  assert.deepStrictEqual(page.consent.getConsent().categories, ['necessary']);
});

test('final: withdrawing consent unloads iframes activated under it', async () => {
  const page = boot({ routes: { '/consent/save': saved } });
  await settle();

  page.consent.acceptAll();
  assert.strictEqual(page.gatedFrame.getAttribute('src'), 'https://ads.test/embed');

  page.consent.updateConsent({ analytics: true, marketing: false });
  assert.strictEqual(page.gatedFrame.getAttribute('src'), 'about:blank', 'marketing withdrawn');
  assert.strictEqual(page.gatedFrame.getAttribute('data-cck-activated'), null);

  page.consent.acceptAll();
  assert.strictEqual(page.gatedFrame.getAttribute('src'), 'https://ads.test/embed', 'and reactivated on a later acceptance');

  page.consent.resetConsent();
  assert.strictEqual(page.gatedFrame.getAttribute('src'), 'about:blank', 'reset unloads it too');
});

test('final: javascript: and data: sources are never activated', async () => {
  let frame;
  const page = boot({
    storage: { cck_consent_1: validDecision() },
    extra: (doc) => {
      frame = doc.createElement('iframe');
      frame.setAttribute('data-cck-category', 'marketing');
      frame.setAttribute('data-cck-src', 'javascript:alert(1)');
      doc.body.appendChild(frame);
      gated(doc, 'analytics', { 'data-cck-src': 'data:text/javascript,alert(1)' });
    }
  });
  await settle();

  assert.strictEqual(frame.getAttribute('src'), null);
  assert.strictEqual(page.activatedScripts().filter((n) => /^data:/.test(n.getAttribute('src') || '')).length, 0);
});

test('final: a queued placeholder removed from the page does not stall later scripts', async () => {
  // Document order: [analytics external (the page's own, still loading)]
  // [inline A] [external B] [inline C]. A waits for the first external, so B
  // and C are queued behind it; B is then removed from the page (a component
  // re-rendered) before the queue reaches it.
  let doomed;
  const page = boot({
    routes: { '/consent/save': saved },
    extra: (doc) => {
      gated(doc, 'analytics', {}, 'window.inlineA = true;');
      doomed = gated(doc, 'analytics', { 'data-cck-src': 'https://lib.test/removed.js' });
      gated(doc, 'analytics', {}, 'window.inlineC = true;');
    }
  });
  await settle();

  page.consent.acceptAll();

  const siblings = doomed.parentNode.childNodes;
  siblings.splice(siblings.indexOf(doomed), 1);
  doomed.parentNode = null;

  page.activatedScripts()
    .filter((node) => node.getAttribute('src') === 'https://analytics.test/a.js')
    .forEach((node) => node.dispatchEvent({ type: 'load' }));

  const inline = (text) => page.activatedScripts().filter((n) => n.text === text).length;
  assert.strictEqual(inline('window.inlineA = true;'), 1);
  assert.strictEqual(inline('window.inlineC = true;'), 1, 'the removed placeholder does not hold the queue for ever');
});

test('final: the cookie-report map keeps only the last day and never becomes a cookie', async () => {
  const old = Date.now() - 3 * 864e5;
  const page = boot({ storage: { cck_consent_1: validDecision(), cck_reported_1: { stale: old, CraftSessionId: old } } });
  await settle();

  const map = JSON.parse(page.storage.cck_reported_1);
  assert.deepStrictEqual(Object.keys(map), ['CraftSessionId'], 'stale names pruned, the re-reported one kept');

  const noStorage = boot({ localStorage: { broken: true }, storage: {}, cookies: { cck_consent_1: encodeURIComponent(JSON.stringify(validDecision())) } });
  await settle();
  assert.strictEqual(noStorage.env.cookies.cck_reported_1, undefined, 'no growing fallback cookie');
});

test('final: the un-namespaced legacy key is removed, not adopted by whichever site loads first', async () => {
  const page = boot({ storage: { cck_consent: validDecision() } });
  await settle();

  assert.strictEqual(page.consent.hasStoredConsent(), false);
  assert.strictEqual(page.storage.cck_consent, undefined);
  assert.strictEqual(page.banner.hidden, false);
});

test('final: configured colours are applied through the CSSOM, not an inline <style>', async () => {
  const page = boot({ config: { cssVars: { '--cck-banner-bg': '#101010', 'color': 'red', '--cck-x': {} } } });
  await settle();

  const style = page.document.documentElement.style;
  assert.strictEqual(style.getPropertyValue('--cck-banner-bg'), '#101010');
  assert.strictEqual(style.getPropertyValue('color'), '', 'only --cck-* custom properties');
  assert.strictEqual(style.getPropertyValue('--cck-x'), '', 'only string values');
});

test('final: opening the preference centre twice keeps the original focus to restore', async () => {
  const page = boot({ extra: withDialogControls });
  const opener = page.document.createElement('button');
  page.document.body.appendChild(opener);
  opener.focus();

  page.consent.openPreferences();
  page.consent.openPreferences();
  page.consent.closePreferences();

  assert.strictEqual(page.document.activeElement, opener);
});

test('final: buttons work where Element.closest is unavailable', async () => {
  let accept;
  const page = boot({ routes: { '/consent/save': saved }, extra: (doc) => { accept = addAction(doc, 'accept-all'); } });
  await settle();

  const inner = page.document.createElement('span');
  accept.appendChild(inner);
  inner.closest = undefined;

  page.click(inner);
  await settle();

  assert.strictEqual(page.consent.getConsent().action, 'accept_all');
});

// ---------------------------------------------------------------------------

(async function run() {
  let failed = 0;

  for (const { name, fn } of tests) {
    try {
      await fn();
      console.log(`  ok   ${name}`);
    } catch (error) {
      failed++;
      console.log(`  FAIL ${name}`);
      console.log(`       ${error.message.split('\n').join('\n       ')}`);
    }
  }

  console.log(`\n${tests.length - failed}/${tests.length} runtime tests passed`);
  process.exit(failed === 0 ? 0 : 1);
}());
