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
  const env = createEnvironment({ navigator: options.navigator || {}, config: config });
  const doc = env.document;

  // A cookie has to exist for detection to have anything to report.
  env.setCookie('CraftSessionId', 'abc123');

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

  new Function('window', 'document', 'fetch', 'CustomEvent', RUNTIME)(
    env.window,
    doc,
    fetch,
    env.CustomEvent
  );

  return {
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
  assert.strictEqual(JSON.parse(page.storage.cck_visitor_1), 'uuid-9');
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

  assert.strictEqual(JSON.parse(page.storage.cck_visitor_1), 'uuid-new');
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
