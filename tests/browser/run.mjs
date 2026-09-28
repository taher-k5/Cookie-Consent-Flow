/**
 * Real-browser checks for the consent runtime, driven over the Chrome
 * DevTools Protocol with nothing but Node's built-in fetch and WebSocket.
 *
 *     node tests/browser/run.mjs http://127.0.0.1:8611 /path/to/craft-project [admin password]
 *
 * With an admin's credentials the control-panel checks run too.
 *
 * The project must be a disposable Craft install serving the fixture
 * templates in tests/integration/templates/ (see tests/integration/http.php).
 * Settings are changed between checks through tests/browser/set.php.
 *
 * The runtime harness (tests/js/) proves the logic against a stub DOM. This
 * proves the things only a browser can: real focus movement, real network
 * requests (or their absence), real layout at phone widths, and a real
 * Content-Security-Policy.
 *
 * Set CHROME to override the browser binary.
 */

import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const [base, projectRoot, cpUser, cpPassword] = process.argv.slice(2);

if (!base || !projectRoot) {
  console.error('Usage: node tests/browser/run.mjs http://127.0.0.1:8611 /path/to/craft-project');
  process.exit(2);
}

const here = path.dirname(fileURLToPath(import.meta.url));
const chromePath = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const port = 9400 + Math.floor(Math.random() * 400);

function settings(values) {
  execFileSync('php', [path.join(here, 'set.php'), projectRoot, JSON.stringify(values)], { stdio: ['ignore', 'ignore', 'inherit'] });
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// ---------------------------------------------------------------------------
// A minimal CDP client
// ---------------------------------------------------------------------------

const profile = mkdtempSync(path.join(tmpdir(), 'ccf-chrome-'));
const chrome = spawn(chromePath, [
  '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
  '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--disable-extensions',
  '--host-resolver-rules=MAP analytics.example 127.0.0.1:9', 'about:blank'
], { stdio: 'ignore' });

let version;
for (let i = 0; i < 50 && !version; i++) {
  try { version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); } catch { await sleep(100); }
}
if (!version) { console.error('Chrome did not start'); process.exit(2); }

const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((resolve) => ws.addEventListener('open', resolve, { once: true }));

let nextId = 1;
const pending = new Map();
const listeners = [];

ws.addEventListener('message', (message) => {
  const data = JSON.parse(message.data);
  if (data.id && pending.has(data.id)) {
    const { resolve, reject } = pending.get(data.id);
    pending.delete(data.id);
    data.error ? reject(new Error(data.error.message)) : resolve(data.result);
  } else if (data.method) {
    listeners.forEach((listener) => listener(data));
  }
});

function send(method, params = {}, sessionId) {
  const id = nextId++;
  ws.send(JSON.stringify({ id, method, params, sessionId }));
  return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}

/** A fresh, isolated page: its own cookie jar and storage. */
async function openPage(width = 1280, height = 900) {
  const { browserContextId } = await send('Target.createBrowserContext');
  const { targetId } = await send('Target.createTarget', { url: 'about:blank', browserContextId });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });

  const requests = [];
  const consoleErrors = [];
  listeners.push((event) => {
    if (event.sessionId !== sessionId) return;
    if (event.method === 'Network.requestWillBeSent') requests.push({ url: event.params.request.url, method: event.params.request.method });
    if (event.method === 'Runtime.exceptionThrown') consoleErrors.push(event.params.exceptionDetails.text);
    if (event.method === 'Log.entryAdded' && event.params.entry.level === 'error') consoleErrors.push(event.params.entry.text);
  });

  await send('Page.enable', {}, sessionId);
  await send('Runtime.enable', {}, sessionId);
  await send('Network.enable', {}, sessionId);
  await send('Log.enable', {}, sessionId);
  await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 600 }, sessionId);

  const page = {
    requests,
    consoleErrors,
    async goto(url) {
      const loaded = new Promise((resolve) => listeners.push((event) => {
        if (event.sessionId === sessionId && event.method === 'Page.loadEventFired') resolve();
      }));
      await send('Page.navigate', { url }, sessionId);
      await loaded;
      await sleep(300);
    },
    async eval(expression) {
      const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, sessionId);
      if (result.exceptionDetails) throw new Error('page threw: ' + (result.exceptionDetails.exception?.description || result.exceptionDetails.text));
      return result.result.value;
    },
    async key(key, shift = false) {
      const code = { Tab: 9, Escape: 27, Enter: 13 }[key];
      const modifiers = shift ? 8 : 0;
      await send('Input.dispatchKeyEvent', { type: 'keyDown', key, code: key, windowsVirtualKeyCode: code, modifiers }, sessionId);
      await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code: key, windowsVirtualKeyCode: code, modifiers }, sessionId);
    },
    /** A real mouse click at viewport coordinates (moves the focus starting point, unlike element.click()). */
    async mouseClick(x, y) {
      for (const type of ['mousePressed', 'mouseReleased']) {
        await send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1 }, sessionId);
      }
    },
    async cookies() {
      return (await send('Storage.getCookies', { browserContextId })).cookies;
    },
    async close() {
      await send('Target.closeTarget', { targetId });
      await send('Target.disposeBrowserContext', { browserContextId });
    },
    to(fragment) {
      return requests.filter((request) => request.url.includes(fragment));
    }
  };

  return page;
}

// ---------------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------------

const checks = [];
const check = (name, fn) => checks.push({ name, fn });

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

const PLUGIN_ENDPOINTS = ['cookie-consent-flow/', 'users/session-info'];
const pluginRequests = (page) => page.requests.filter((r) => PLUGIN_ENDPOINTS.some((p) => r.url.includes(p)));

// The project should serve a favicon: Craft's own 404 page (which a missing
// /favicon.ico hits) starts a session and sets its CSRF cookie whether or not
// this plugin is installed, which would be mistaken for the plugin's doing.
check('a fresh visitor triggers no plugin request and is issued no cookie', async (page) => {
  await page.goto(base + '/');

  expect(pluginRequests(page).length === 0, 'requests: ' + JSON.stringify(pluginRequests(page)));
  expect((await page.cookies()).length === 0, 'cookies: ' + (await page.cookies()).map((c) => c.name).join(', ') + ' after requests ' + JSON.stringify(page.requests.map((r) => r.url)));
  expect(await page.eval(`!document.getElementById('cck-banner').hidden`), 'banner not shown');
});

check('H1: the page with footer buttons runs one runtime — one save per click', async (page) => {
  await page.goto(base + '/');

  expect(await page.eval(`document.querySelectorAll('script[src*="cookie-banner.js"]').length`) === 1, 'script tags');
  await page.eval(`document.querySelector('#cck-banner [data-cck-action="accept-all"]').click()`);
  await sleep(800);

  const saves = page.to('consent/save').filter((r) => r.method === 'POST');
  expect(saves.length === 1, 'consent saves: ' + saves.length);
});

check('Reject All makes no optional network request', async (page) => {
  await page.goto(base + '/');
  await page.eval(`document.querySelector('#cck-banner [data-cck-action="reject-all"]').click()`);
  await sleep(800);
  await page.goto(base + '/');
  await sleep(500);

  expect(page.to('analytics.example').length === 0, 'the analytics script was requested after a rejection');
  expect(await page.eval(`document.getElementById('cck-banner').hidden`), 'banner came back');
});

check('Accept All activates the tagged script', async (page) => {
  await page.goto(base + '/');
  await page.eval(`document.querySelector('#cck-banner [data-cck-action="accept-all"]').click()`);
  await sleep(800);

  expect(page.to('analytics.example').length === 1, 'the gated script was not requested exactly once: ' + page.to('analytics.example').length);
});

check('M5: reset returns Google Consent Mode to denied', async (page) => {
  settings({ consentModeEnabled: true, consentModeType: 'advanced' });
  try {
    await page.goto(base + '/');
    await page.eval(`document.querySelector('#cck-banner [data-cck-action="accept-all"]').click()`);
    await sleep(500);
    await page.eval(`document.querySelector('footer [data-cck-action="reset-consent"]').click()`);
    await sleep(300);

    const last = await page.eval(`(function(){var u=(window.dataLayer||[]).filter(function(e){return e&&e[0]==='consent'&&e[1]==='update'});return u[u.length-1]&&Object.assign({},u[u.length-1][2]);})()`);
    expect(last && last.analytics_storage === 'denied' && last.ad_storage === 'denied', 'last update: ' + JSON.stringify(last));
    expect(await page.eval(`!document.getElementById('cck-banner').hidden`), 'asked again');
  } finally {
    settings({ consentModeEnabled: false });
  }
});

check('M6: the preference centre traps Tab and Shift+Tab, and Escape restores focus', async (page) => {
  await page.goto(base + '/');
  await page.eval(`document.querySelector('#cck-banner [data-cck-action="open-preferences"]').focus()`);
  await page.eval(`document.activeElement.click()`);
  await sleep(200);

  const inside = `!!document.activeElement.closest('#cck-preferences')`;
  expect(await page.eval(inside), 'focus did not move into the dialog');

  await page.key('Tab', true);
  expect(await page.eval(inside), 'Shift+Tab straight after opening escaped the dialog');

  for (let i = 0; i < 25; i++) {
    await page.key('Tab', i % 3 === 0);
    expect(await page.eval(inside), `focus escaped on key ${i + 1}`);
  }

  await page.key('Escape');
  await sleep(100);
  expect(await page.eval(`document.getElementById('cck-preferences').hidden`), 'Escape did not close it');
  expect(await page.eval(`document.activeElement && document.activeElement.getAttribute('data-cck-action') === 'open-preferences'`), 'focus not returned to the opener');
  expect(page.to('consent/save').length === 0, 'Escape recorded a decision');
});

check('A1: saving from the preference centre leaves focus on a visible element (center popup)', async (page) => {
  settings({ bannerLayout: 'center-popup' });
  try {
    await page.goto(base + '/');
    await page.eval(`document.querySelector('#cck-banner [data-cck-action="open-preferences"]').click()`);
    await sleep(100);
    await page.eval(`document.querySelector('#cck-preferences [data-cck-action="save-preferences"]').click()`);
    await sleep(300);

    expect(await page.eval(`document.getElementById('cck-banner').hidden`), 'banner still visible');
    expect(await page.eval(`document.activeElement !== null && document.activeElement.offsetParent !== null || document.activeElement === document.body`), 'focus on a hidden element');
    expect(await page.eval(`!document.activeElement.closest('[hidden]')`), 'focus inside a hidden container');
  } finally {
    settings({ bannerLayout: 'bottom-bar' });
  }
});

for (const width of [320, 375, 390, 414]) {
  check(`L25: at ${width}px a long bottom bar keeps every button reachable`, async () => {
    const page = await openPage(width, 640);
    try {
      await page.goto(base + '/');
      // Measured once the entrance animation has finished, not mid-slide.
      await page.eval(`Promise.all(document.getAnimations().map(function(a){return a.finished}))`);
      const metrics = await page.eval(`(function(){
        var b=document.getElementById('cck-banner'); var r=b.getBoundingClientRect(); var cs=getComputedStyle(b);
        var last=b.querySelectorAll('.cck-btn'); last=last[last.length-1]; last.scrollIntoView({block:'nearest'});
        var lr=last.getBoundingClientRect();
        return {top:r.top,bottom:r.bottom,h:innerHeight,w:innerWidth,overflowY:cs.overflowY,scrollW:document.documentElement.scrollWidth,
                lastTop:lr.top,lastBottom:lr.bottom};
      })()`);
      expect(metrics.top >= 0 && metrics.bottom <= metrics.h + 1, 'banner exceeds the viewport: ' + JSON.stringify(metrics));
      expect(metrics.lastBottom <= metrics.h + 1 && metrics.lastTop >= 0, 'last button unreachable: ' + JSON.stringify(metrics));
      expect(metrics.scrollW <= metrics.w, 'horizontal page scroll: ' + JSON.stringify(metrics));
    } finally {
      await page.close();
    }
  });
}

for (const layout of ['center-popup', 'corner-popup']) {
  check(`release: on a short desktop window a ${layout} with a tall configured height stays on screen`, async () => {
    settings({ bannerLayout: layout, maxHeight: '900px' });
    const page = await openPage(1280, 480);
    try {
      await page.goto(base + '/');
      await page.eval(`Promise.all(document.getAnimations().map(function(a){return a.finished}))`);
      const m = await page.eval(`(function(){
        var b=document.getElementById('cck-banner'); var r=b.getBoundingClientRect();
        var btns=b.querySelectorAll('.cck-btn'); var last=btns[btns.length-1];
        last.focus(); var lr=last.getBoundingClientRect();
        return {top:r.top,bottom:r.bottom,h:innerHeight,lastTop:lr.top,lastBottom:lr.bottom,focused:document.activeElement===last,scrollable:b.scrollHeight>b.clientHeight?getComputedStyle(b).overflowY:'fits'};
      })()`);
      expect(m.top >= 0 && m.bottom <= m.h + 1, 'popup exceeds the window: ' + JSON.stringify(m));
      expect(m.focused && m.lastTop >= 0 && m.lastBottom <= m.h + 1, 'the last button is not reachable by keyboard: ' + JSON.stringify(m));
    } finally {
      await page.close();
      settings({ bannerLayout: 'bottom-bar', maxHeight: '90vh' });
    }
  });
}

check('release: on a short window every preference-centre button is reachable', async () => {
  const page = await openPage(1280, 420);
  try {
    await page.goto(base + '/');
    await page.eval(`document.querySelector('#cck-banner [data-cck-action="open-preferences"]').click()`);
    await page.eval(`Promise.all(document.getAnimations().map(function(a){return a.finished}))`);
    const panel = await page.eval(`(function(){var r=document.querySelector('.cck-preferences__panel').getBoundingClientRect();return {top:r.top,bottom:r.bottom,h:innerHeight}})()`);
    expect(panel.top >= 0 && panel.bottom <= panel.h + 1, 'panel exceeds the window: ' + JSON.stringify(panel));
    const boxes = await page.eval(`Array.from(document.querySelectorAll('#cck-preferences .cck-preferences__footer .cck-btn')).map(function(b){b.focus();var r=b.getBoundingClientRect();return {t:r.top,b:r.bottom,h:innerHeight,focused:document.activeElement===b}})`);
    expect(boxes.length >= 2, 'footer buttons: ' + boxes.length);
    boxes.forEach((box) => expect(box.focused && box.t >= 0 && box.b <= box.h + 1, 'unreachable: ' + JSON.stringify(box)));
  } finally {
    await page.close();
  }
});

check('L24: on a narrow screen every cookie-table value is labelled', async () => {
  const page = await openPage(360, 740);
  try {
    await page.goto(base + '/cookies');
    const labels = await page.eval(`Array.from(document.querySelectorAll('.cck-cookie-table td')).map(function(td){return getComputedStyle(td,'::before').content})`);
    expect(labels.length > 0, 'no cells');
    expect(labels.every((c) => /Provider|Purpose|Duration/.test(c)), 'unlabelled cells: ' + JSON.stringify(labels.slice(0, 6)));
    const roles = await page.eval(`[document.querySelector('.cck-cookie-table').getAttribute('role'), document.querySelector('.cck-cookie-table td').getAttribute('role')]`);
    expect(roles[0] === 'table' && roles[1] === 'cell', 'ARIA roles: ' + roles);
  } finally {
    await page.close();
  }
});

check('L7: under a nonce CSP an activated inline script keeps its nonce and runs; an un-nonced one stays blocked', async (page) => {
  await page.goto(base + '/csp');
  expect(await page.eval(`typeof window.CookieConsent === 'object'`), 'runtime blocked by the policy (configuration must be inert JSON)');

  await page.eval(`CookieConsent.acceptAll()`);
  await sleep(500);

  expect(await page.eval(`window.ccfNoncedRan === true`), 'the nonced gated script did not run');
  expect(await page.eval(`window.ccfUnnoncedRan !== true`), 'an un-nonced inline script ran under a nonce policy');
});

check('final: on a 320px phone every preference-centre button is on screen and reachable', async () => {
  const page = await openPage(320, 640);
  try {
    await page.goto(base + '/');
    await page.eval(`document.querySelector('#cck-banner [data-cck-action="open-preferences"]').click()`);
    await page.eval(`Promise.all(document.getAnimations().map(function(a){return a.finished}))`);
    const boxes = await page.eval(`Array.from(document.querySelectorAll('#cck-preferences .cck-preferences__footer .cck-btn')).map(function(b){b.scrollIntoView({block:'nearest'});var r=b.getBoundingClientRect();return {l:r.left,r:r.right,t:r.top,b:r.bottom,w:innerWidth,h:innerHeight,label:b.textContent.trim()}})`);
    expect(boxes.length >= 2, 'footer buttons: ' + boxes.length);
    boxes.forEach((box) => expect(box.l >= 0 && box.r <= box.w + 1 && box.t >= 0 && box.b <= box.h + 1, 'off screen: ' + JSON.stringify(box)));
  } finally {
    await page.close();
  }
});

check('final: the focus ring is visible on the white Accept/Save buttons', async (page) => {
  await page.goto(base + '/');
  await page.eval(`document.querySelector('#cck-banner [data-cck-action="accept-all"]').focus()`);
  await page.key('Tab', true); await page.key('Tab'); // keyboard modality, back on Accept
  const ring = await page.eval(`(function(){var b=document.activeElement;var s=getComputedStyle(b);return {style:s.outlineStyle,color:s.outlineColor,bg:getComputedStyle(document.getElementById('cck-banner')).backgroundColor}})()`);
  expect(ring.style !== 'none', 'no outline: ' + JSON.stringify(ring));
  expect(ring.color !== ring.bg && ring.color !== 'rgb(255, 255, 255)', 'ring invisible against the banner: ' + JSON.stringify(ring));
});

check('final: Tab through the preference centre passes each "Cookies used" summary and reaches Save', async (page) => {
  await page.goto(base + '/');
  await page.eval(`document.querySelector('#cck-banner [data-cck-action="open-preferences"]').click()`);
  const summaries = await page.eval(`document.querySelectorAll('#cck-preferences summary').length`);
  expect(summaries > 0, 'the fixture needs documented cookies (a summary to tab past)');

  let reachedSave = false;
  let sawSummary = false;
  for (let i = 0; i < 40 && !reachedSave; i++) {
    await page.key('Tab');
    const at = await page.eval(`(document.activeElement.getAttribute('data-cck-action')||'') + '|' + document.activeElement.tagName`);
    if (at.endsWith('|SUMMARY')) sawSummary = true;
    if (at.startsWith('save-preferences')) reachedSave = true;
    expect(await page.eval(`!!document.activeElement.closest('#cck-preferences')`), 'focus left the dialog at ' + at);
  }
  expect(sawSummary && reachedSave, `summary=${sawSummary} save=${reachedSave}`);
});

check('final: after clicking the popup overlay, Tab stays inside the dialog', async (page) => {
  settings({ bannerLayout: 'center-popup' });
  try {
    await page.goto(base + '/');
    // A real click on the dimmed backdrop, well away from the dialog.
    await page.mouseClick(5, 5);
    expect(await page.eval(`!document.activeElement.closest('#cck-banner')`), 'precondition: the click moved focus out of the dialog');
    // The overlay sits just before the dialog in the document, so Shift+Tab is
    // the direction that walks back into the page behind it.
    await page.key('Tab', true);
    expect(await page.eval(`!!document.activeElement.closest('#cck-banner')`), 'Shift+Tab went to the page behind the modal');
    await page.mouseClick(5, 5);
    await page.key('Tab');
    expect(await page.eval(`!!document.activeElement.closest('#cck-banner')`), 'Tab went to the page behind the modal');
  } finally {
    settings({ bannerLayout: 'bottom-bar' });
  }
});

check('final: deciding in the bar moves focus to the footer preferences button', async (page) => {
  await page.goto(base + '/');
  await page.eval(`document.querySelector('#cck-banner [data-cck-action="reject-all"]').focus()`);
  await page.eval(`document.activeElement.click()`);
  await sleep(200);
  expect(await page.eval(`document.activeElement.matches('footer [data-cck-action="open-preferences"]')`), 'focus: ' + await page.eval(`document.activeElement.outerHTML.slice(0,80)`));
});

check('final: under style-src \'self\', configured colours still apply (no inline <style>)', async (page) => {
  settings({ bannerBgColor: '#123456' });
  try {
    await page.goto(base + '/csp');
    const bg = await page.eval(`getComputedStyle(document.getElementById('cck-banner')).backgroundColor`);
    expect(bg === 'rgb(18, 52, 86)', 'banner background ' + bg);
    const blocked = page.consoleErrors.filter((e) => /style-src|Content Security Policy/i.test(e));
    expect(blocked.length === 0, 'CSP violations: ' + JSON.stringify(blocked));
  } finally {
    settings({ bannerBgColor: '#ffffff' });
  }
});

check('the full flow produces no console errors', async (page) => {
  await page.goto(base + '/');
  await page.eval(`document.querySelector('#cck-banner [data-cck-action="open-preferences"]').click()`);
  await page.eval(`document.querySelector('#cck-preferences [data-cck-action="save-preferences"]').click()`);
  await sleep(600);
  await page.goto(base + '/');
  await page.eval(`document.querySelector('footer [data-cck-action="reset-consent"]').click()`);
  await sleep(300);

  const relevant = page.consoleErrors.filter((e) => !/analytics\.example|ERR_CONNECTION|Failed to load resource/.test(e));
  expect(relevant.length === 0, 'errors: ' + JSON.stringify(relevant));
});

// ---------------------------------------------------------------------------
// Control panel
// ---------------------------------------------------------------------------

/** Logs the page's browser context into the control panel. */
async function cpLogin(page) {
  await page.goto(base + '/index.php?p=admin/login');
  const ok = await page.eval(`(async function(){
    var info = await (await fetch('/index.php?p=actions/users/session-info', {headers:{Accept:'application/json'}})).json();
    var body = new FormData();
    body.append('loginName', ${JSON.stringify(cpUser || '')});
    body.append('password', ${JSON.stringify(cpPassword || '')});
    body.append(info.csrfTokenName || 'CRAFT_CSRF_TOKEN', info.csrfTokenValue);
    var res = await fetch('/index.php?p=actions/users/login', {method:'POST', body:body, headers:{Accept:'application/json'}});
    return res.ok;
  })()`);
  expect(ok, 'control-panel login failed');
}

const NEW_ROW = `document.querySelector('#cck-categories-list .cck-category-row:last-child, .cck-categories-group .cck-categories-list .cck-category-row:last-child')`;
const switchState = (label) => `(function(){
  var row = ${NEW_ROW};
  var field = Array.from(row.querySelectorAll('.field')).find(function(f){return f.textContent.indexOf(${JSON.stringify(label)}) !== -1 && f.querySelector('.lightswitch')});
  var ls = field.querySelector('.lightswitch');
  return {on: ls.classList.contains('on'), value: ls.querySelector('input').value, name: ls.querySelector('input').name};
})()`;
const clickSwitch = (label) => `(function(){
  var row = ${NEW_ROW};
  var field = Array.from(row.querySelectorAll('.field')).find(function(f){return f.textContent.indexOf(${JSON.stringify(label)}) !== -1 && f.querySelector('.lightswitch')});
  field.querySelector('.lightswitch').click();
})()`;

if (cpUser && cpPassword) {
  check('release: a newly added category\'s switches work before saving, and their state is saved', async (page) => {
    await cpLogin(page);
    const key = 'ccf_browser_' + Date.now().toString(36);

    await page.goto(base + '/index.php?p=admin/cookie-consent-flow/settings');
    const before = await page.eval(`document.querySelectorAll('.cck-categories-group .cck-categories-list .cck-category-row').length`);
    await page.eval(`document.querySelector('.cck-categories-group .cck-add-category').click()`);
    expect(await page.eval(`document.querySelectorAll('.cck-categories-group .cck-categories-list .cck-category-row').length`) === before + 1, 'no row added');

    await page.eval(`(function(){
      var row = ${NEW_ROW};
      row.querySelector('input[name$="[key]"]').value = ${JSON.stringify(key)};
      row.querySelector('input[name$="[label]"]').value = 'Browser test';
    })()`);

    for (const label of ['Enabled by default', 'Always on (locked)']) {
      const off = await page.eval(switchState(label));
      expect(!off.on && off.value !== '1', `${label} starts on: ` + JSON.stringify(off));
      await page.eval(clickSwitch(label));
      const on = await page.eval(switchState(label));
      expect(on.on && on.value === '1', `${label} did not respond to a click without a reload: ` + JSON.stringify(on));
    }

    // Save the real form, then reload.
    await page.eval(`document.querySelector('.cck-categories-group').closest('form').requestSubmit()`);
    await sleep(1500);
    await page.goto(base + '/index.php?p=admin/cookie-consent-flow/settings');

    const saved = await page.eval(`(function(){
      var row = Array.from(document.querySelectorAll('.cck-categories-group .cck-categories-list .cck-category-row')).find(function(r){return r.querySelector('input[name$="[key]"]').value === ${JSON.stringify(key)}});
      if (!row) return null;
      return Array.from(row.querySelectorAll('.lightswitch')).map(function(ls){return ls.classList.contains('on')});
    })()`);
    try {
      expect(saved !== null, 'the new category was not saved');
      expect(saved.length === 2 && saved.every(Boolean), 'switch state not saved: ' + JSON.stringify(saved));
    } finally {
      // Remove the test category again.
      await page.eval(`(function(){
        var row = Array.from(document.querySelectorAll('.cck-categories-group .cck-categories-list .cck-category-row')).find(function(r){return r.querySelector('input[name$="[key]"]').value === ${JSON.stringify(key)}});
        if (row) { row.querySelector('.cck-remove-category').click(); document.querySelector('.cck-categories-group').closest('form').requestSubmit(); }
      })()`);
      await sleep(1500);
    }
  });
}

// ---------------------------------------------------------------------------

settings({
  bannerLayout: 'bottom-bar', consentModeEnabled: false, geoEnabled: false, logEnabled: true,
  bannerDescription: 'We use cookies to enhance your browsing experience. '.repeat(18),
  __cookies: [
    { categoryKey: 'analytics', name: '_ga', provider: 'Google', purpose: 'Distinguishes visitors', duration: '2 years' },
    { categoryKey: 'marketing', name: '_fbp', provider: 'Meta', purpose: 'Ad delivery', duration: '90 days' }
  ]
});

let failed = 0;

for (const { name, fn } of checks) {
  const page = await openPage();
  try {
    await fn(page);
    console.log(`  ok   ${name}`);
  } catch (error) {
    failed++;
    console.log(`  FAIL ${name}\n       ${error.message}`);
  } finally {
    await page.close().catch(() => {});
  }
}

settings({ bannerDescription: 'We use cookies to enhance your browsing experience.' });

console.log(`\n${checks.length - failed}/${checks.length} browser checks passed (Chrome ${version.Browser})`);

ws.close();
chrome.kill();
await sleep(300);
try { rmSync(profile, { recursive: true, force: true }); } catch {}

process.exit(failed === 0 ? 0 : 1);
