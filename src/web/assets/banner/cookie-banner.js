/**
 * Cookie Consent Flow — front-end runtime.
 *
 * Owns banner and preference-centre visibility, the visitor's stored decision,
 * consent gating of tagged scripts/iframes, Google Consent Mode updates,
 * browser privacy signals, and server sync.
 *
 * No dependencies. ES5-compatible syntax with a small set of ES2015+ built-ins
 * (Promise, fetch, CustomEvent), all of which are guarded before use.
 *
 * ## Cache safety
 *
 * This file and the HTML it operates on are identical for every visitor, and
 * are safe to serve from a full-page cache. Everything visitor-specific —
 * the stored decision, the CSRF token, the resolved country — is read or
 * fetched here, at runtime, on the visitor's own device. Nothing about one
 * visitor can reach another through cached markup.
 */
(function (window, document) {
  'use strict';

  /* ------------------------------------------------------------------
     Single instance

     The file can reach a page more than once: auto-injection adds it, a
     template may register the asset bundle as well, and a site may copy the
     tag into a layout by hand. Every copy used to install its own document
     listeners and run its own init(), so one click on "Accept" committed
     twice and posted two consent records. The first copy to run owns the
     page; any later copy leaves it alone.

     Keyed on a marker rather than on `window.CookieConsent` alone, so a site
     that defines its own placeholder under that name before the runtime
     arrives is replaced as before rather than mistaken for a running copy.
  ------------------------------------------------------------------ */

  if (window.CookieConsent && window.CookieConsent._isCckRuntime === true) return;

  /* ------------------------------------------------------------------
     Storage
  ------------------------------------------------------------------ */

  /**
   * Version of the stored-consent envelope. Bumping this makes older
   * envelopes unreadable *by shape*, which is separate from policyVersion
   * (which invalidates by *policy*). A shape change must never be mistaken
   * for a policy change: the first is our problem and should migrate
   * silently where possible, the second is the visitor's and must re-ask.
   */
  var STORAGE_VERSION = 2;

  // Pre-multi-site key names. Read once, to adopt an existing decision rather
  // than silently forgetting it on upgrade.
  var LEGACY_STORAGE_KEY = 'cck_consent';
  var LEGACY_VISITOR_KEY = 'cck_visitor';

  var DAY_MS = 864e5;

  // How long the geo lookup may take before the banner is shown anyway.
  var GEO_TIMEOUT_MS = 4000;

  /**
   * localStorage with a cookie fallback, and every access guarded.
   *
   * Storage can be absent or throw outright (private browsing, blocked site
   * data, a sandboxed iframe). None of those may break the page, so every
   * failure degrades to "no stored decision", which shows the banner — the
   * safe direction to fail in, since it asks rather than assumes.
   *
   * Reads and writes have to agree about where a value lives. They did not:
   * a write fell back to the cookie whenever `setItem()` threw (a full quota,
   * say), but a read only consulted the cookie when `getItem()` threw. With
   * reading working and writing failing, every decision went into a cookie
   * nothing read back, so the banner returned on every page and every click
   * logged another record — while the head snippet, which does read the
   * cookie, replayed that same decision to Google. So:
   *
   * - a read takes localStorage's value when there is one, and the cookie
   *   otherwise, whichever of the two failed;
   * - a successful localStorage write removes any cookie copy, so a stale
   *   fallback can never outlive the value that replaced it.
   */
  var Store = {
    set: function (key, value) {
      var str;
      try {
        str = JSON.stringify(value);
      } catch (e) {
        return false;
      }
      try {
        window.localStorage.setItem(key, str);
      } catch (e) {
        return Store._setCookie(key, str, 365);
      }
      if (Store._getCookie(key) !== null) Store._deleteCookie(key);
      return true;
    },

    get: function (key) {
      var raw = null;
      try {
        raw = window.localStorage.getItem(key);
      } catch (e) {
        raw = null;
      }
      if (raw === null || raw === undefined || raw === '') raw = Store._getCookie(key);
      if (raw === null || raw === undefined || raw === '') return null;
      try {
        return JSON.parse(raw);
      } catch (e) {
        // Corrupt or hand-edited value. Clear it rather than retrying the
        // same failing parse on every page load for the rest of time.
        Store.remove(key);
        return null;
      }
    },

    remove: function (key) {
      try { window.localStorage.removeItem(key); } catch (e) {}
      Store._deleteCookie(key);
    },

    /** localStorage only, with no cookie fallback — for bookkeeping, not decisions. */
    getLocal: function (key) {
      try {
        var raw = window.localStorage.getItem(key);
        return raw ? JSON.parse(raw) : null;
      } catch (e) {
        return null;
      }
    },

    setLocal: function (key, value) {
      try {
        window.localStorage.setItem(key, JSON.stringify(value));
        return true;
      } catch (e) {
        return false;
      }
    },

    _setCookie: function (name, value, days) {
      try {
        var secure = window.location.protocol === 'https:' ? ';Secure' : '';
        document.cookie = name + '=' + encodeURIComponent(value) +
          ';expires=' + new Date(Date.now() + days * DAY_MS).toUTCString() +
          ';path=/;SameSite=Lax' + secure;
        return true;
      } catch (e) {
        return false;
      }
    },

    _getCookie: function (name) {
      try {
        var match = document.cookie.match(
          new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)')
        );
        return match ? decodeURIComponent(match[1]) : null;
      } catch (e) {
        return null;
      }
    },

    _deleteCookie: function (name) {
      try {
        document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Lax';
      } catch (e) {}
    }
  };

  /* ------------------------------------------------------------------
     Small helpers
  ------------------------------------------------------------------ */

  function toArray(list) {
    return Array.prototype.slice.call(list || []);
  }

  function unique(arr) {
    var seen = {};
    var out = [];
    for (var i = 0; i < arr.length; i++) {
      if (!Object.prototype.hasOwnProperty.call(seen, arr[i])) {
        seen[arr[i]] = true;
        out.push(arr[i]);
      }
    }
    return out;
  }

  /**
   * Whether a decision made at `timestamp` is still within `expiryDays`.
   *
   * With expiry on, a missing, zero or non-numeric timestamp is not "no
   * expiry": it is a decision whose age cannot be known, and is re-asked. A
   * timestamp more than a day in the future (a clock set ahead, or a
   * hand-edited value) would otherwise never age at all. Expiry 0 disables
   * the check.
   */
  function isFresh(timestamp, expiryDays) {
    if (!expiryDays) return true;
    if (typeof timestamp !== 'number' || !(timestamp > 0)) return false;

    var now = Date.now();

    return timestamp <= now + DAY_MS && now - timestamp <= expiryDays * DAY_MS;
  }

  /** Shallow copy, so a queued payload can carry bookkeeping of its own. */
  function assign(target, source) {
    for (var key in source) {
      if (Object.prototype.hasOwnProperty.call(source, key)) target[key] = source[key];
    }
    return target;
  }

  /**
   * How many times a failed-but-retryable sync is re-sent on later page loads
   * before it is given up on. A decision the server has refused five times
   * over five page views is not going to be accepted on the sixth, and the
   * visitor's own storage — which is authoritative for what actually runs —
   * already holds it.
   */
  var MAX_SYNC_ATTEMPTS = 5;

  /**
   * Whether a failed request is worth sending again later.
   *
   * The queue exists for the case where the server never got the message. It
   * is not a way to argue with an answer: re-sending a body the server has
   * already rejected produces the same rejection, once per page load, for as
   * long as the visitor keeps browsing.
   */
  function isRetryable(error) {
    var status = error && typeof error.status === 'number' ? error.status : 0;

    // No status: the request got no answer at all — offline, DNS, a dropped
    // connection, an aborted fetch. Precisely what queueing is for.
    if (!status) return true;

    // Asked to come back later rather than refused.
    if (status === 408 || status === 429) return true;

    // The server's problem, and usually a passing one.
    if (status >= 500) return true;

    // Any other 4xx is a refusal of this exact payload (400 invalid_action,
    // 403, 404, 422). Nothing about re-sending it unchanged can succeed.
    return false;
  }

  /**
   * The nearest `[data-cck-action]` element at or above `node`. Walks the
   * tree itself where `Element.closest` is missing (older Safari, IE), where
   * the old guard returned null and every banner button silently did nothing.
   */
  function closestAction(node) {
    if (!node) return null;
    if (typeof node.closest === 'function') return node.closest('[data-cck-action]');

    for (; node && node.getAttribute; node = node.parentNode) {
      if (node.getAttribute('data-cck-action') !== null) return node;
    }

    return null;
  }

  /* ------------------------------------------------------------------
     Focus management

     The preference centre is a real modal dialog, so keyboard users must be
     able to operate it and must not be able to tab out of it into the page
     behind. The previous implementation added a keydown listener per open and
     only removed it on the next Tab press after hiding, which accumulated
     listeners across repeated opens; this keeps exactly one and removes it
     deterministically on close.
  ------------------------------------------------------------------ */

  // Everything a browser puts in the tab order by default. `summary` matters
  // here: each category's "Cookies used" disclosure is one, and when it was
  // missing from this list the trap treated it as "outside" and sent the next
  // Tab back to the first control — so no later category, and neither Save
  // nor Accept, could be reached by Tab at all.
  var FOCUSABLE =
    'button:not([disabled]), input:not([disabled]), select:not([disabled]), ' +
    'textarea:not([disabled]), a[href], area[href], summary, iframe, ' +
    'audio[controls], video[controls], [contenteditable]:not([contenteditable="false"]), ' +
    '[tabindex]:not([tabindex="-1"])';

  var FocusTrap = {
    _element: null,
    _handler: null,

    /**
     * Holds keyboard focus inside `el` while it is a modal dialog.
     *
     * The listener is on the document, not the dialog: a click on the dimmed
     * overlay (or anywhere outside) moves focus to <body>, and a listener on
     * the dialog then never saw the next Tab, which walked into the page
     * behind an `aria-modal` dialog. From outside, Tab and Shift+Tab bring
     * focus back to the dialog's first or last control.
     *
     * Inside the dialog only the edges are handled — Shift+Tab on the first
     * control (or on the dialog's own wrapper, where focus starts), Tab on
     * the last. Every other Tab is the browser's, so anything focusable the
     * list above does not know about still behaves normally.
     */
    activate: function (el) {
      FocusTrap.release();

      FocusTrap._element = el;
      FocusTrap._handler = function (e) {
        if (e.key !== 'Tab') return;

        var focusable = FocusTrap._focusable(el);

        // Nothing inside can take focus: keep it where it is rather than let
        // Tab carry it to the page behind a modal.
        if (!focusable.length) {
          e.preventDefault();
          return;
        }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        var active = document.activeElement;
        var inside = !!active && active !== document.body && el.contains(active);

        if (!inside) {
          e.preventDefault();
          (e.shiftKey ? last : first).focus();
          return;
        }

        // The wrapper focus starts on (tabindex="-1", so a screen reader
        // announces the dialog first) is inside but is not a control.
        var onWrapper = active === el || (active.getAttribute && active.getAttribute('tabindex') === '-1' &&
          focusable.indexOf(active) === -1);

        if (e.shiftKey && (active === first || onWrapper)) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && active === last) {
          e.preventDefault();
          first.focus();
        }
      };

      document.addEventListener('keydown', FocusTrap._handler, true);
    },

    /**
     * The controls a keyboard user can actually reach inside a dialog:
     * enabled, and not inside anything hidden. `offsetParent` is null for an
     * element that is not rendered, which covers `display: none` ancestors;
     * the `hidden` walk covers content hidden by attribute in environments
     * where layout is not computed.
     */
    _focusable: function (el) {
      return toArray(el.querySelectorAll(FOCUSABLE)).filter(function (node) {
        if (node.disabled) return false;

        for (var parent = node; parent && parent !== el; parent = parent.parentNode) {
          if (parent.hidden) return false;
        }

        return node.offsetParent !== null || node === document.activeElement;
      });
    },

    release: function () {
      if (FocusTrap._handler) {
        document.removeEventListener('keydown', FocusTrap._handler, true);
      }
      FocusTrap._element = null;
      FocusTrap._handler = null;
    },

    /**
     * Whether the trap is currently held by a given element. Exactly one
     * element holds it at a time, so a caller closing one dialog has to be
     * able to tell whether the trap is still its own before releasing it —
     * otherwise saving preferences over a modal banner would release the
     * preference centre's trap on the banner's behalf.
     */
    holds: function (el) {
      return !!el && FocusTrap._element === el;
    }
  };

  /* ------------------------------------------------------------------
     Gated content

     Non-essential scripts and iframes stay inert until their category is
     accepted:

       <script type="text/plain" data-cck-category="analytics"
               data-cck-src="https://www.googletagmanager.com/gtag/js?id=X"></script>

       <iframe data-cck-category="marketing"
               data-cck-src="https://www.youtube.com/embed/X"></iframe>

     `data-cck-category` takes one key or a comma/space-separated list, and
     activates if the visitor accepted ANY of them. Browsers never execute a
     type="text/plain" script, so the placeholder is genuinely inert until it
     is cloned into a real element here.

     Limitation, by nature rather than by defect: this prevents content from
     loading. It cannot unload a script that has already run in the current
     page view — withdrawing consent mid-visit takes effect for everything
     subsequently loaded, and fully on the next navigation.
  ------------------------------------------------------------------ */

  function isAllowed(node, categories) {
    var required = (node.getAttribute('data-cck-category') || '').split(/[\s,]+/);
    for (var i = 0; i < required.length; i++) {
      if (required[i] && categories.indexOf(required[i]) !== -1) return true;
    }
    return false;
  }

  /**
   * Activated scripts run in document order, as the same tags would have
   * without gating.
   *
   * A script created with createElement() is async by default, so an external
   * library and the inline snippet that configures it used to race, and the
   * snippet could run before the library existed. Now:
   *
   * - external scripts are created with `async = false`, which makes the
   *   browser execute them in insertion order while still downloading them
   *   in parallel — unless the placeholder itself asked for `async`, in which
   *   case the author has said order does not matter;
   * - an inline script waits until every ordered external script inserted
   *   before it has loaded (or failed), because an inline script runs the
   *   moment it is inserted and would otherwise overtake them.
   *
   * Placeholders are claimed when queued, so a second activation pass while
   * the queue is still draining cannot activate the same tag twice.
   */
  var ScriptQueue = {
    _items: [],
    _pending: 0,

    add: function (node) {
      node.setAttribute('data-cck-queued', 'true');
      ScriptQueue._items.push(node);
      ScriptQueue._drain();
    },

    _drain: function () {
      while (ScriptQueue._items.length) {
        var node = ScriptQueue._items[0];
        var external = !!node.getAttribute('data-cck-src');

        if (!external && ScriptQueue._pending > 0) return;

        ScriptQueue._items.shift();
        ScriptQueue._insert(node, external);
      }
    },

    _insert: function (node, external) {
      // A placeholder removed from the page (a re-rendered component, say)
      // while queued has nowhere to go. It used to count as pending anyway,
      // never loaded, and so held back every gated inline script after it
      // for the rest of the page view.
      if (!node.parentNode) return;

      if (external && !isSafeSrc(node.getAttribute('data-cck-src'))) {
        node.setAttribute('data-cck-refused', 'unsafe-src');
        return;
      }

      var script = document.createElement('script');

      toArray(node.attributes).forEach(function (attr) {
        // `nonce` is copied from the property below: browsers blank the
        // attribute once the document has parsed, so the attribute copy
        // would carry an empty nonce and a nonce-based CSP would block the
        // activated script.
        if (attr.name === 'type' || attr.name === 'data-cck-category' ||
            attr.name === 'data-cck-queued' || attr.name === 'nonce') return;
        script.setAttribute(attr.name === 'data-cck-src' ? 'src' : attr.name, attr.value);
      });

      var nonce = node.nonce || node.getAttribute('nonce');
      if (nonce) script.nonce = nonce;

      if (external) {
        var ordered = node.getAttribute('async') === null;

        script.async = !ordered;

        if (ordered) {
          ScriptQueue._pending++;

          var done = function () {
            script.removeEventListener('load', done);
            script.removeEventListener('error', done);
            ScriptQueue._pending = Math.max(0, ScriptQueue._pending - 1);
            ScriptQueue._drain();
          };

          script.addEventListener('load', done);
          script.addEventListener('error', done);
        }
      } else {
        script.text = node.textContent;
      }

      node.parentNode.replaceChild(script, node);
    }
  };

  function activateGatedScripts(categories) {
    toArray(
      document.querySelectorAll('script[type="text/plain"][data-cck-category]:not([data-cck-queued])')
    ).forEach(function (node) {
      if (!isAllowed(node, categories)) return;

      ScriptQueue.add(node);
    });
  }

  /**
   * Whether a `data-cck-src` is a URL a gated tag may load: http(s), or
   * relative. `javascript:` and `data:` would run code the moment an iframe
   * or script is given them, which no gated embed needs.
   */
  function isSafeSrc(url) {
    if (typeof url !== 'string' || url === '') return false;

    var head = url.split(/[\/?#]/)[0];

    return head.indexOf(':') === -1 || /^https?:$/i.test(head);
  }

  function activateGatedFrames(categories) {
    toArray(
      document.querySelectorAll('iframe[data-cck-category][data-cck-src]:not([data-cck-activated])')
    ).forEach(function (node) {
      if (!isAllowed(node, categories)) return;
      if (!isSafeSrc(node.getAttribute('data-cck-src'))) return;
      node.setAttribute('src', node.getAttribute('data-cck-src'));
      node.setAttribute('data-cck-activated', 'true');
    });
  }

  /**
   * Unloads activated iframes whose category is no longer accepted.
   *
   * A script that has run cannot be unloaded, but an iframe can: withdrawing
   * consent used to leave an embed that was activated under it loaded and
   * running for the rest of the page view. It is pointed at about:blank and
   * becomes a placeholder again, so a later acceptance activates it anew.
   */
  function deactivateGatedFrames(categories) {
    toArray(document.querySelectorAll('iframe[data-cck-category][data-cck-activated]')).forEach(function (node) {
      if (isAllowed(node, categories)) return;
      node.setAttribute('src', 'about:blank');
      node.removeAttribute('data-cck-activated');
    });
  }

  function activateGatedContent(categories) {
    categories = categories || [];
    deactivateGatedFrames(categories);
    activateGatedScripts(categories);
    activateGatedFrames(categories);
  }

  /* ------------------------------------------------------------------
     Runtime
  ------------------------------------------------------------------ */

  /**
   * The ids the banner template renders. Collected here so the runtime has one
   * place that knows what the markup is called, instead of the same string
   * literal in a dozen lookups.
   */
  var ELEMENTS = {
    banner: 'cck-banner',
    bannerOverlay: 'cck-banner-overlay',
    preferences: 'cck-preferences'
  };

  var CookieConsent = {
    /** Identifies a running copy of this file; see the guard at the top. */
    _isCckRuntime: true,
    _config: {},
    _previousFocus: null,
    /** What had focus before a modal (center-popup) banner took it. */
    _bannerPreviousFocus: null,
    _storageKey: LEGACY_STORAGE_KEY,
    _visitorKey: LEGACY_VISITOR_KEY,
    _csrfToken: null,
    /** The in-flight token request, shared by every caller that needs one. */
    _csrfPromise: null,
    _ready: false,

    /**
     * Whether `cookieConsent:ready` has fired, and what it carried. Kept so a
     * listener that attaches afterwards can still be told — see onReady().
     */
    _readyFired: false,
    _readyDetail: null,
    _readyCallbacks: [],

    /**
     * Whether this page view is running under the site's geo policy rather
     * than under a decision the visitor made — see _applyGeoBypass().
     *
     * Deliberately per page view and never persisted. It is not consent and
     * must not be stored, logged, expire, or carry a policy version; if the
     * admin narrows the target countries tomorrow, this visitor is simply
     * asked, because nothing was written on their device today.
     */
    _geoBypass: false,

    /**
     * Counter identifying the current consent decision for the purposes of
     * server sync. Every new decision and every reset increments it, and an
     * in-flight sync that finishes carrying an older number is discarded —
     * see _sync() and resetConsent().
     */
    _syncGeneration: 0,

    /* ---------------------------------------------------------------
       Initialisation
    --------------------------------------------------------------- */

    /**
     * ## Lifecycle
     *
     * `cookieConsent:ready` fires exactly once per page view, as soon as the
     * page's consent state is settled enough to act on:
     *
     * - a valid stored decision → after it has been applied, with that
     *   decision as `detail` (preceded by `loaded`);
     * - a GPC or DNT signal → after the rejection it implies has been
     *   committed, with that decision as `detail` (it used to never fire at
     *   all on this path);
     * - no decision → with `detail: null`, before the banner is shown.
     *
     * Because a listener attached after that moment has missed the event,
     * `CookieConsent.onReady(callback)` calls back immediately with the same
     * detail when the page is already ready, and on the event otherwise.
     */
    init: function () {
      if (this._ready) return;

      var config = this._readConfig();

      // The runtime was loaded before its configuration: the asset bundle's
      // tag can precede the configuration block in the document. Starting now
      // would run with no URLs, no categories and un-namespaced storage keys,
      // so wait for the document to finish parsing, by which point the
      // configuration has been reached.
      if (!config && document.readyState === 'loading') {
        var self = this;
        document.addEventListener('DOMContentLoaded', function () { self.init(); });

        return;
      }

      this._ready = true;

      this._config = config || {};
      this._resolveStorageKeys();
      this._applyCssVars();

      // Bound unconditionally, including when consent already exists: a
      // persistent "manage preferences" or "reset" control rendered anywhere
      // on the site has to keep working after the first decision, not only
      // while the banner happens to be on screen.
      this._bindGlobalEvents();

      var stored = this.getConsent();

      if (stored) {
        activateGatedContent(stored.categories);
        this._pushConsentMode(stored.categories);
        this._retryPendingSync();

        // Only once a decision exists. See _reportDetectedCookies() for why
        // this deliberately no longer runs on a visitor's first page view.
        this._reportDetectedCookies();

        this._emit('loaded', stored);
        this._markReady(stored);
        return;
      }

      // No valid stored decision. A browser privacy signal may answer for the
      // visitor before we ask them anything.
      var signalled = this._applyPrivacySignal();
      if (signalled) {
        this._markReady(signalled);
        return;
      }

      // Locked categories are strictly necessary: they are on in every
      // decision and the visitor cannot decline them, so content tagged with
      // one runs now rather than waiting on a question whose answer cannot
      // change it. Only their own signals are granted; every optional signal
      // stays on the denied default until the visitor answers.
      var locked = this._lockedCategories();
      if (locked.length) {
        activateGatedContent(locked);
        this._pushConsentMode(locked, true);
      }

      this._markReady(null);
      this._maybeShowBanner();
    },

    /**
     * The runtime configuration: the inert JSON block the plugin renders
     * (`<script type="application/json" id="cck-config">`), or the
     * `window.cckConfig` global that earlier versions and custom templates
     * set. Null when neither is present yet.
     *
     * A JSON block is not executed, so it needs no CSP nonce and cannot be
     * blocked by a strict script-src policy.
     */
    _readConfig: function () {
      var node = document.getElementById('cck-config');

      if (node) {
        try {
          var parsed = JSON.parse(node.textContent || '');
          if (parsed && typeof parsed === 'object') return parsed;
        } catch (e) {}
      }

      return window.cckConfig && typeof window.cckConfig === 'object' ? window.cckConfig : null;
    },

    /**
     * Sets the banner's configured colours and dimensions as CSS custom
     * properties on the document element. Done through the CSSOM rather than
     * an inline <style>, which a Content Security Policy without
     * 'unsafe-inline' in style-src would block — leaving every configured
     * colour at its default. Values are allow-listed on the server.
     */
    _applyCssVars: function () {
      var vars = this._config.cssVars;
      var root = document.documentElement;

      if (!vars || typeof vars !== 'object' || !root || !root.style || typeof root.style.setProperty !== 'function') return;

      for (var name in vars) {
        if (Object.prototype.hasOwnProperty.call(vars, name) && /^--cck-[a-z-]+$/.test(name) && typeof vars[name] === 'string') {
          root.style.setProperty(name, vars[name]);
        }
      }
    },

    /** Fires `ready` once and releases anything waiting in onReady(). */
    _markReady: function (detail) {
      if (this._readyFired) return;

      this._readyFired = true;
      this._readyDetail = detail;

      this._emit('ready', detail);

      var callbacks = this._readyCallbacks;
      this._readyCallbacks = [];

      for (var i = 0; i < callbacks.length; i++) {
        try { callbacks[i](detail); } catch (e) {}
      }
    },

    /**
     * Runs `callback(detail)` once the page's consent state is settled —
     * immediately if it already is. Safe to call from a script that runs
     * before or after this file.
     */
    onReady: function (callback) {
      if (typeof callback !== 'function') return;

      if (this._readyFired) {
        try { callback(this._readyDetail); } catch (e) {}
        return;
      }

      this._readyCallbacks.push(callback);
    },

    /** Whether `cookieConsent:ready` has fired on this page. */
    isReady: function () {
      return this._readyFired;
    },

    /**
     * Namespaces storage per Craft site, so a decision made on one site of a
     * shared-origin multi-site install is never read as consent for another.
     *
     * On the first run for a site, an existing un-namespaced value is adopted
     * and the old key removed — preserving consent for the ordinary
     * single-site upgrade, while every *other* site on a shared origin
     * correctly starts by asking, since only one site can claim the legacy key.
     */
    _resolveStorageKeys: function () {
      var siteId = this._config.siteId;
      if (siteId === undefined || siteId === null) return;

      this._storageKey = LEGACY_STORAGE_KEY + '_' + siteId;
      this._visitorKey = LEGACY_VISITOR_KEY + '_' + siteId;

      // The un-namespaced key from pre-multisite development builds is not
      // adopted. On a shared-origin multisite install it was taken by
      // whichever site the visitor happened to load first — so a decision
      // made on one site became consent on another. It is removed instead;
      // nothing was ever published that wrote it.
      Store.remove(LEGACY_STORAGE_KEY);
    },

    /* ---------------------------------------------------------------
       Consent state

       A stored decision counts only if it is structurally valid, still
       fresh (within consentExpiryDays; 0 disables expiry), and made against
       the current policyVersion. Any of those failing means the visitor has
       to be asked again, so it is treated exactly as if nothing were stored.
    --------------------------------------------------------------- */

    /**
     * Reads, validates and normalises the stored decision. Returns null when
     * there is nothing usable.
     *
     * Normalisation matters as much as validation: a category the admin has
     * since deleted must not keep granting anything, and a locked category
     * must be present whether or not the stored value says so. Trusting the
     * stored array verbatim would let a stale or hand-edited value decide
     * what runs.
     */
    getConsent: function () {
      var stored = Store.get(this._storageKey);

      if (!stored || typeof stored !== 'object') return null;

      var migrated = this._migrate(stored);
      if (!migrated) return null;

      var cfg = this._config;

      if (cfg.policyVersion && migrated.policyVersion !== cfg.policyVersion) return null;

      if (!isFresh(migrated.timestamp, cfg.consentExpiryDays)) return null;

      // Unknown keys are dropped against the site's categories — from the
      // configuration, or the rendered preference centre when the
      // configuration is missing. With neither there is nothing to check a
      // stored key against, so nothing stored is trusted.
      var known = this._allCategories();
      var locked = this._lockedCategories();

      if (!known.length) return null;

      var categories = migrated.categories.filter(function (key) {
        return known.indexOf(key) !== -1;
      });

      return {
        action: migrated.action,
        categories: unique(categories.concat(locked)),
        timestamp: migrated.timestamp,
        policyVersion: migrated.policyVersion,
        source: migrated.source || 'banner'
      };
    },

    /**
     * Brings an older envelope up to the current shape, or rejects it as
     * unusable. Version 1 had no `v` field and no `source`; its other fields
     * are shape-compatible, so it upgrades in place rather than forcing a
     * re-ask for a purely internal change.
     */
    _migrate: function (stored) {
      if (!Array.isArray(stored.categories) || typeof stored.action !== 'string') return null;

      var version = typeof stored.v === 'number' ? stored.v : 1;
      if (version > STORAGE_VERSION) return null;

      return {
        v: STORAGE_VERSION,
        action: stored.action,
        categories: stored.categories.filter(function (key) { return typeof key === 'string'; }),
        timestamp: typeof stored.timestamp === 'number' ? stored.timestamp : 0,
        policyVersion: typeof stored.policyVersion === 'string' ? stored.policyVersion : '',
        source: typeof stored.source === 'string' ? stored.source : 'banner'
      };
    },

    /** Whether a valid decision is stored. */
    hasStoredConsent: function () {
      return this.getConsent() !== null;
    },

    /**
     * Whether a given category is currently consented to.
     * With no argument, whether any valid decision exists at all — which is
     * what the old zero-argument `hasConsent()` meant, so existing calls
     * keep their original behaviour.
     */
    hasConsent: function (category) {
      var stored = this.getConsent();

      // Under a geo bypass there is no decision, but optional content is
      // permitted — so a site gating its own code on this has to get the same
      // answer the `data-cck-category` convention already acts on, or the two
      // would disagree about the same visitor.
      if (!stored) {
        if (!this._geoBypass) return false;
        if (category === undefined || category === null) return true;

        return this._allCategories().indexOf(category) !== -1;
      }

      if (category === undefined || category === null) return true;

      return stored.categories.indexOf(category) !== -1;
    },

    /**
     * The decision as a category → boolean map covering every configured
     * category, which is usually easier to work with than the accepted-only
     * array. Categories are never hard-coded here; the map is whatever this
     * site has configured.
     */
    getConsentState: function () {
      var stored = this.getConsent();
      var state = {};
      var all = this._config.allCategories || [];
      // No decision, but permitted by the site's geo policy — see
      // _applyGeoBypass().
      var allowed = !stored && this._geoBypass;

      for (var i = 0; i < all.length; i++) {
        state[all[i]] = stored ? stored.categories.indexOf(all[i]) !== -1 : allowed;
      }

      return state;
    },

    /** Re-scans for gated content against the current decision. */
    refreshGatedContent: function () {
      var stored = this.getConsent();

      if (stored) {
        activateGatedContent(stored.categories);
        return;
      }

      // Before a decision, exactly what init() allowed: locked categories,
      // which no decision can switch off. An empty list here used to unload
      // the locked iframes init() had loaded, and left locked content added
      // after an AJAX or Sprig update inert.
      activateGatedContent(this._geoBypass ? this._allCategories() : this._lockedCategories());
    },

    /* ---------------------------------------------------------------
       Browser privacy signals
    --------------------------------------------------------------- */

    /**
     * Applies Global Privacy Control, or Do Not Track when the site has opted
     * into honouring it, by recording a rejection without showing the banner.
     *
     * GPC is an explicit, machine-readable statement of preference, so acting
     * on it *is* respecting the visitor's choice — asking again would be
     * ignoring an answer already given. DNT is off by default because it is
     * enabled wholesale by some browsers and carries no general legal force,
     * which makes reading it as a considered choice unsafe.
     *
     * Returns the committed decision when a signal was applied and the banner
     * should stay hidden, or false.
     */
    _applyPrivacySignal: function () {
      var cfg = this._config;
      var nav = window.navigator || {};

      var gpc = cfg.respectGpc && nav.globalPrivacyControl === true;
      var dnt = cfg.respectDnt && (nav.doNotTrack === '1' || window.doNotTrack === '1' || nav.msDoNotTrack === '1');

      if (!gpc && !dnt) return false;

      return this._commit('reject_all', this._lockedCategories(), gpc ? 'gpc' : 'dnt');
    },

    /* ---------------------------------------------------------------
       Banner visibility
    --------------------------------------------------------------- */

    /**
     * Shows the banner, first checking geo-targeting when it is enabled.
     *
     * The check is a request rather than something rendered into the page,
     * precisely so the answer is this visitor's and not whichever visitor's
     * request happened to populate the cache. The banner is shown if the
     * check fails for any reason — a lookup problem must never be the reason
     * a visitor is not asked for consent.
     */
    _maybeShowBanner: function () {
      var self = this;
      var cfg = this._config;

      if (!cfg.geoEnabled || !cfg.geoUrl || !window.fetch) {
        this._showBanner();
        return;
      }

      // Remembered for the tab only: the visitor's country cannot change
      // mid-session in any way that matters, and this keeps it to one request
      // per visit rather than one per page view. The key carries the policy
      // version, so bumping it (Settings → Invalidate Existing Consent, or an
      // edit to the field) also retires any cached geo answer instead of
      // leaving open tabs on a superseded decision.
      var cacheKey = this._geoCacheKey();
      var cached = null;
      try {
        cached = window.sessionStorage.getItem(cacheKey);
      } catch (e) {}

      if (cached === 'show') { this._showBanner(); return; }
      if (cached === 'hide') { this._applyGeoBypass(null); return; }

      // The lookup is asynchronous, and the visitor (or the site's own code)
      // can decide while it is in flight — through a footer "manage
      // preferences" control, rejectAll(), updateConsent(). The answer
      // arriving afterwards used to be applied regardless: a "not targeted"
      // answer activated every optional category over a rejection the
      // visitor had just made, and a "targeted" one re-showed a banner that
      // had already been answered. The generation counter already identifies
      // the current decision for sync purposes; the same number says whether
      // this lookup still belongs to the page state that asked for it.
      var generation = this._syncGeneration;
      var isStale = function () {
        return generation !== self._syncGeneration || self.getConsent() !== null;
      };

      // A lookup that never answers — a stalled proxy, a CDN holding the
      // connection — used to keep the banner hidden until the browser gave
      // up, which can take minutes, and the visitor could not consent in the
      // meantime. After GEO_TIMEOUT_MS the banner is shown, exactly as for a
      // failed lookup: the timeout only ever asks, it never grants. It is not
      // cached, so the next page view tries again, and an answer arriving
      // after it is ignored — a late "not targeted" must not activate
      // optional content under a banner the visitor is already looking at.
      var settled = false;
      var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
      var timer = typeof window.setTimeout === 'function'
        ? window.setTimeout(function () {
            if (settled) return;
            settled = true;
            if (controller) {
              try { controller.abort(); } catch (e) {}
            }
            if (isStale()) return;

            self._showBanner();
          }, GEO_TIMEOUT_MS)
        : null;
      var settle = function () {
        if (settled) return false;
        settled = true;
        if (timer !== null && typeof window.clearTimeout === 'function') window.clearTimeout(timer);

        return true;
      };

      var init = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
      if (controller) init.signal = controller.signal;

      fetch(cfg.geoUrl, init).then(function (res) {
        return res.ok ? res.json() : { show: true };
      }).then(function (json) {
        if (!settle() || isStale()) return;

        var show = !json || json.show !== false;
        try {
          window.sessionStorage.setItem(cacheKey, show ? 'show' : 'hide');
        } catch (e) {}

        if (show) self._showBanner();
        else self._applyGeoBypass(json && json.country);
      }).catch(function () {
        if (!settle() || isStale()) return;

        self._showBanner();
      });
    },

    /**
     * Applies the site's geo policy to a visitor the banner does not apply to.
     *
     * Geo-targeting in this plugin answers one question: in which countries
     * does this site need to ask for consent. A visitor outside that list is
     * one the site has decided it does not need to ask — so the banner is not
     * shown, and optional content runs.
     *
     * Previously only the first half of that happened. The banner was
     * suppressed and then nothing else was: no decision existed, so every
     * `data-cck-category` script and iframe stayed inert and Consent Mode kept
     * the denied default, permanently, for every visitor outside the target
     * countries. A site targeting the EU lost all analytics everywhere else,
     * silently and with no way for the visitor to resolve it.
     *
     * What this is **not** is a consent record:
     *
     * - nothing is written to the visitor's storage, so there is no decision
     *   to expire, to carry a policy version, or to invalidate later;
     * - `_commit()` is not called and `_sync()` never runs, so no consent log
     *   row is created and the statistics are unchanged. The audit trail keeps
     *   meaning "a visitor chose this", which is the only thing that makes it
     *   evidence;
     * - the flag lives for one page view only.
     *
     * If the admin narrows the target countries later, this visitor is simply
     * asked on their next page load, because nothing was recorded on their
     * device to suppress the question.
     */
    _applyGeoBypass: function (country) {
      var categories = this._allCategories();

      this._geoBypass = true;

      activateGatedContent(categories);
      this._pushConsentMode(categories);

      // Consistent with every other settled state: detection reports only once
      // the page has stopped being a pending question.
      this._reportDetectedCookies();

      this._emit('suppressed', {
        reason: 'geo',
        country: country || null,
        categories: categories
      });
    },

    /**
     * Whether this page view is running under the site's geo policy rather
     * than a decision the visitor made. Exposed so a site can tell the two
     * apart — `hasConsent()` answers "may this run", which is the same for
     * both, and is usually the question worth asking.
     */
    isGeoBypassed: function () {
      return this._geoBypass && !this.hasStoredConsent();
    },

    /**
     * The tab-scoped geo cache key. Namespaced by site, and by policy version
     * so a policy change doesn't leave open tabs acting on a stale answer.
     */
    _geoCacheKey: function () {
      var cfg = this._config;

      return 'cck_geo_' + (cfg.siteId !== undefined ? cfg.siteId : '0') +
        '_' + (cfg.policyVersion || '');
    },

    /**
     * Whether the banner is presented as a modal dialog — true only for the
     * center-popup layout, which dims the page behind it. The bar layouts
     * leave the page genuinely usable and stay a labelled region, so they get
     * no focus management: trapping focus in a non-blocking bar would be the
     * bug, not the fix. The template is the single source of truth here (it
     * marks the dialog up as one), rather than this file re-deriving the
     * layout from configuration.
     */
    _bannerIsModal: function (banner) {
      return !!banner && banner.getAttribute('role') === 'dialog';
    },

    /**
     * The banner element when it is currently on screen *and* modal, otherwise
     * null. Every place that has to coordinate with a modal banner — opening
     * the preference centre over it, closing it, hiding the banner on a
     * decision — asks this one question, so it is asked in one place.
     */
    _openModalBanner: function () {
      var banner = this._element('banner');

      return this._bannerIsModal(banner) && !banner.hidden ? banner : null;
    },

    _showBanner: function () {
      var banner = this._element('banner');
      if (!banner) return;

      // Whether this is a fresh open or a re-show of an already-visible banner
      // (resetConsent() on a page that is still showing it) decides whether
      // there is a pre-banner focus worth remembering: re-capturing it here
      // would store something inside the banner and "restore" focus into a
      // hidden element later.
      var alreadyOpen = !!this._openModalBanner();

      banner.hidden = false;
      banner.setAttribute('aria-hidden', 'false');
      banner.classList.add('cck-visible');

      this._toggleOverlay(false);

      // A dimmed page with content a keyboard user can still tab behind is a
      // dialog in appearance only. When the layout dims the page, focus moves
      // in and stays in until the visitor decides — Escape deliberately still
      // does nothing here, because dismissing without a choice would have to
      // be recorded as one.
      if (this._bannerIsModal(banner)) {
        if (!alreadyOpen) {
          this._bannerPreviousFocus = document.activeElement;
        }

        FocusTrap.activate(banner);
        this._focusFirst(banner, '.cck-banner__inner');
      }

      this._emit('shown', null);
    },

    _hideBanner: function () {
      var banner = this._element('banner');
      var wasModal = !!this._openModalBanner();

      if (banner) {
        banner.hidden = true;
        banner.setAttribute('aria-hidden', 'true');
        banner.classList.remove('cck-visible');
      }

      this._toggleOverlay(true);

      if (!wasModal) return;

      if (FocusTrap.holds(banner)) {
        FocusTrap.release();
      }

      // If the preference centre is open over the banner, it owns focus and
      // restores it on its own close; stepping in here would pull focus out of
      // a dialog the visitor is still using.
      var prefs = this._element('preferences');

      if (!prefs || prefs.hidden) {
        this._restoreFocus('_bannerPreviousFocus');
      } else {
        this._bannerPreviousFocus = null;
      }
    },

    /**
     * After the banner and preference centre are hidden, focus must not stay
     * on a control inside them. The modal layout restores focus to what had
     * it before the banner opened; the bar and corner layouts never took
     * focus, so a keyboard user who tabbed into them and decided was left
     * focused on a hidden button. Focus goes to the site's own "manage
     * preferences" control when the page has one — the natural place to
     * change the decision just made — and is released otherwise.
     */
    _moveFocusOutOfHiddenUi: function () {
      var active = document.activeElement;
      var banner = this._element('banner');
      var prefs = this._element('preferences');

      if (!active || active === document.body) return;

      var inHidden = (banner && banner.hidden && banner.contains(active)) ||
        (prefs && prefs.hidden && prefs.contains(active));

      if (!inHidden) return;

      var controls = toArray(document.querySelectorAll('[data-cck-action="open-preferences"]')).filter(function (node) {
        return !(banner && banner.contains(node)) && !(prefs && prefs.contains(node)) && node.offsetParent !== null;
      });

      if (controls.length) {
        controls[0].focus();
      } else if (typeof active.blur === 'function') {
        active.blur();
      }
    },

    /** Shows or hides the dimming overlay that only the popup layout renders. */
    _toggleOverlay: function (hidden) {
      var overlay = this._element('bannerOverlay');

      if (overlay) overlay.hidden = hidden;
    },

    /**
     * Moves focus to the first thing inside a dialog that is worth landing on
     * — its panel or inner wrapper where there is one, so a screen-reader user
     * hears the dialog's purpose rather than whichever control happens to come
     * first (usually the way back out of it).
     */
    _focusFirst: function (container, selector) {
      var target = container.querySelector(selector) || container;

      target.setAttribute('tabindex', '-1');
      target.focus();
    },

    /** Looks up one of the banner's own elements; null when it isn't rendered. */
    _element: function (name) {
      return document.getElementById(ELEMENTS[name]);
    },

    /** Returns focus to whatever had it before a dialog opened. */
    _restoreFocus: function (property) {
      var previous = this[property];

      if (previous && typeof previous.focus === 'function' && document.contains(previous)) {
        previous.focus();
      }

      this[property] = null;
    },

    /* ---------------------------------------------------------------
       Preference centre
    --------------------------------------------------------------- */

    openPreferences: function () {
      var modal = this._element('preferences');
      if (!modal) return;

      // Already open: remembering the current focus now would store an
      // element inside the dialog, and closing would "restore" focus into
      // the hidden dialog.
      if (!modal.hidden) return;

      this._previousFocus = document.activeElement;

      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      FocusTrap.activate(modal);

      // Only one dialog at a time may claim the screen reader. With the
      // center-popup layout the banner underneath is itself a modal dialog, so
      // it is hidden from assistive technology while the preference centre is
      // in front of it — focus is inside the preference centre by the end of
      // this method, so nothing focusable is being hidden.
      var modalBanner = this._openModalBanner();
      if (modalBanner) {
        modalBanner.setAttribute('aria-hidden', 'true');
      }

      // Reflect the visitor's current position: their stored choices if they
      // have any, otherwise the admin's configured defaults. Optional
      // categories are only ever pre-checked because an admin asked for it.
      var stored = this.getConsent();
      var checked = stored ? stored.categories : (this._config.defaultCategories || this._lockedCategories());

      toArray(modal.querySelectorAll('input[type="checkbox"][data-category]')).forEach(function (cb) {
        if (!cb.disabled) cb.checked = checked.indexOf(cb.dataset.category) !== -1;
      });

      this._focusFirst(modal, '.cck-preferences__panel');

      this._emit('preferences', { open: true });
    },

    closePreferences: function () {
      var modal = this._element('preferences');
      if (!modal || modal.hidden) return;

      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      FocusTrap.release();

      // Closing the preference centre can return the visitor to a banner that
      // is itself modal (center-popup). FocusTrap holds one element at a time,
      // so the banner's trap — released when this dialog claimed it — has to
      // be put back, or the visitor is left inside a dimmed page with nothing
      // holding focus. This runs before focus is restored: the control focus
      // returns to is usually inside that banner, and it has to be visible to
      // assistive technology again before it receives focus.
      var modalBanner = this._openModalBanner();
      if (modalBanner) {
        modalBanner.setAttribute('aria-hidden', 'false');
        FocusTrap.activate(modalBanner);
      }

      this._restoreFocus('_previousFocus');

      this._emit('preferences', { open: false });
    },

    /* ---------------------------------------------------------------
       Consent actions
    --------------------------------------------------------------- */

    acceptAll: function () {
      this._commit('accept_all', this._allCategories(), 'banner');
    },

    rejectAll: function () {
      this._commit('reject_all', this._lockedCategories(), 'banner');
    },

    savePreferences: function () {
      var modal = this._element('preferences');
      if (!modal) return;

      var checked = [];
      toArray(modal.querySelectorAll('input[type="checkbox"][data-category]')).forEach(function (cb) {
        if (cb.checked && cb.dataset.category) checked.push(cb.dataset.category);
      });

      this._commit('custom', checked, 'banner');
    },

    /**
     * Sets consent programmatically, from a category → boolean map or an
     * array of accepted keys:
     *
     *   CookieConsent.updateConsent({ analytics: true, marketing: false });
     *   CookieConsent.updateConsent(['analytics']);
     *
     * Locked categories are always included and unknown keys are dropped, so
     * a caller cannot record consent for something this site does not offer.
     */
    updateConsent: function (input) {
      var accepted = [];

      if (Array.isArray(input)) {
        accepted = input.slice();
      } else if (input && typeof input === 'object') {
        for (var key in input) {
          if (Object.prototype.hasOwnProperty.call(input, key) && input[key]) accepted.push(key);
        }
      }

      var known = this._allCategories();
      var locked = this._lockedCategories();

      accepted = accepted.filter(function (key) { return known.indexOf(key) !== -1; });

      // Classify by the optional categories only — locked ones are in every
      // decision and so say nothing about what the visitor chose.
      var optional = known.filter(function (key) { return locked.indexOf(key) === -1; });
      var chosen = optional.filter(function (key) { return accepted.indexOf(key) !== -1; });

      var action = chosen.length === 0 ? 'reject_all'
        : (chosen.length === optional.length ? 'accept_all' : 'custom');

      this._commit(action, accepted, 'api');
    },

    /**
     * Clears the stored decision and asks again.
     *
     * Deliberately does not delete cookies that third-party scripts already
     * set: this code cannot know which cookie belongs to which script, and
     * guessing would break sessions. Withdrawal stops further loading and
     * takes full effect on the next navigation — which is stated plainly in
     * the documentation rather than papered over.
     */
    resetConsent: function () {
      // Retire every sync belonging to the withdrawn decision, in flight or
      // not. Clearing the queue alone is not enough and was the bug: a request
      // sent before this call still had its own handlers to run, and on
      // failure they wrote the withdrawn decision straight back into the
      // queue — so the next page load posted consent the visitor had already
      // taken back. Bumping the generation makes those handlers no-ops (see
      // _sync()), which is the only ordering-independent way to do it.
      this._syncGeneration++;

      // Not a decision, so it does not survive one either.
      this._geoBypass = false;

      Store.remove(this._storageKey);
      Store.remove(this._visitorKey);

      // The queued copy of an earlier decision goes too. Leaving it would let
      // a decision the visitor has just withdrawn be posted to the server on a
      // later page load — recording consent after its withdrawal. Only this
      // reset clears the queue; an ordinary failed save still retries.
      Store.remove(this._storageKey + '_pending');

      try { window.sessionStorage.removeItem(this._geoCacheKey()); } catch (e) {}

      // Withdrawal reaches Google too. Tags already on the page otherwise
      // kept running under the grant that was just taken back until the next
      // navigation. Every optional signal returns to denied; the locked
      // categories' signals stay granted, exactly as a rejection leaves them.
      this._pushConsentMode(this._lockedCategories());
      deactivateGatedFrames(this._lockedCategories());

      this._emit('reset', {});
      this._maybeShowBanner();
    },

    /* ---------------------------------------------------------------
       Commit
    --------------------------------------------------------------- */

    _commit: function (action, categories, source) {
      categories = unique((categories || []).concat(this._lockedCategories()));

      // This decision supersedes anything already in flight, for the same
      // reason resetConsent() does: a sync for the previous decision that
      // fails after this one is made must not queue the superseded payload
      // over the current one.
      this._syncGeneration++;

      // A real decision replaces the geo policy's standing permission.
      this._geoBypass = false;

      var data = {
        v: STORAGE_VERSION,
        action: action,
        categories: categories,
        timestamp: Date.now(),
        policyVersion: this._config.policyVersion || '',
        source: source || 'banner'
      };

      var persisted = Store.set(this._storageKey, data);

      activateGatedContent(categories);
      this._pushConsentMode(categories);

      // The preference centre closes first. Hiding the banner while it was
      // still open discarded the banner's remembered focus, and closing it
      // afterwards returned focus to its opener — a button inside the banner
      // that had just been hidden — so keyboard focus fell to <body>.
      this.closePreferences();
      this._hideBanner();
      this._moveFocusOutOfHiddenUi();

      // Say so rather than failing silently: with no writable storage the
      // decision cannot outlive the page, and a listener may want to react.
      this._emit('changed', { action: action, categories: categories, source: source, persisted: persisted });
      this._emit(action === 'accept_all' ? 'accepted' : (action === 'reject_all' ? 'rejected' : 'custom'), data);

      this._sync(data);

      // Now that the page has a settled state — and the consent save has
      // already established the session this needs anyway — the browser's
      // cookie names can be reported. Covers accept, reject, custom, the
      // JavaScript API, and the GPC/DNT paths, all of which arrive here.
      this._reportDetectedCookies();

      return data;
    },

    /* ---------------------------------------------------------------
       Google Consent Mode v2
    --------------------------------------------------------------- */

    /**
     * Translates the decision into a single `consent update`.
     *
     * The category → signal mapping is entirely admin-configured and arrives
     * in the runtime config; no category name and no signal is assumed here.
     * Every signal the configuration mentions is set explicitly to granted or
     * denied, so withdrawing consent genuinely returns a signal to denied
     * rather than leaving the previous grant standing.
     *
     * With `grantsOnly`, only the given categories' signals are sent, as
     * granted — used before the visitor has answered, when the locked
     * categories are already in effect but nothing optional has been decided
     * and the denied default must be left alone.
     */
    _pushConsentMode: function (categories, grantsOnly) {
      var mode = this._config.consentMode;
      if (!mode || !mode.enabled || !mode.signals) return;

      var update = {};

      for (var category in mode.signals) {
        if (!Object.prototype.hasOwnProperty.call(mode.signals, category)) continue;

        var granted = categories.indexOf(category) !== -1;
        if (grantsOnly && !granted) continue;

        var signals = mode.signals[category] || [];

        for (var i = 0; i < signals.length; i++) {
          // A signal mapped to several categories is granted if any of them
          // is — never downgraded by a later denied category.
          if (update[signals[i]] !== 'granted') {
            update[signals[i]] = granted ? 'granted' : 'denied';
          }
        }
      }

      if (!Object.keys(update).length) return;

      window.dataLayer = window.dataLayer || [];
      if (typeof window.gtag !== 'function') {
        window.gtag = function () { window.dataLayer.push(arguments); };
      }
      window.gtag('consent', 'update', update);

      // A plain data-layer event too, so Tag Manager can trigger on the
      // change without a custom template reading gtag's internals.
      window.dataLayer.push({ event: 'cookie_consent_update', cck_consent: update });
    },

    /* ---------------------------------------------------------------
       Server sync
    --------------------------------------------------------------- */

    /**
     * Records the decision server-side.
     *
     * The visitor's own storage is authoritative for what runs on the page;
     * this is the audit record. A failure is therefore never allowed to
     * affect the visitor's experience — it is queued and retried on the next
     * page load instead of being lost.
     */
    _sync: function (data) {
      var self = this;
      var cfg = this._config;

      if (!cfg.saveUrl || !window.fetch) return;

      var pendingKey = this._storageKey + '_pending';

      // Captured now, compared later. A reset or a newer decision bumps the
      // counter, at which point this request's handlers must not touch stored
      // state whatever they were about to do — the decision they belong to no
      // longer exists.
      var generation = this._syncGeneration;

      // An outbox, written *before* the request. It used to be written only
      // when a request failed — so a navigation that aborted the request
      // (a site reloading on `cookieConsent:changed`, a click on a link right
      // after Accept) ran no handler at all, and the record was lost without
      // trace. And because each new decision replaces the entry, an older
      // decision still queued from an earlier page can never be re-sent over
      // a newer one. The cost is at-least-once delivery: a request whose
      // response never arrived is sent again on the next page view.
      var attempts = typeof data.syncAttempts === 'number' ? data.syncAttempts : 0;
      var entry = assign(assign({}, data), { syncAttempts: attempts });

      Store.set(pendingKey, entry);

      this._post(cfg.saveUrl, {
        action: data.action,
        categories: data.categories,
        source: data.source,
        policyVersion: data.policyVersion
      }, { keepalive: true })
        .then(function () {
          if (generation !== self._syncGeneration) return;

          // The visitor identifier lives only in the server's httpOnly
          // cookie; nothing here stores it.
          Store.remove(pendingKey);
        })
        .catch(function (error) {
          if (generation !== self._syncGeneration) return;

          // A refusal of this payload is final. Queueing it would re-send the
          // identical body on every page load for the rest of the visit, and
          // every visit after it, and be refused every time.
          if (!isRetryable(error)) {
            Store.remove(pendingKey);
            self._emit('syncFailed', { permanent: true, status: (error && error.status) || 0 });

            return;
          }

          if (attempts + 1 > MAX_SYNC_ATTEMPTS) {
            Store.remove(pendingKey);
            self._emit('syncFailed', {
              permanent: false,
              status: (error && error.status) || 0,
              attempts: attempts + 1
            });

            return;
          }

          // The attempt count is bookkeeping for the queue and never part of
          // the decision the visitor's own storage holds.
          Store.set(pendingKey, assign(entry, { syncAttempts: attempts + 1 }));
        });
    },

    /**
     * Re-sends a decision whose server sync failed on an earlier page view.
     *
     * Only ever a decision: a queued entry without an action is malformed and
     * is dropped rather than posted, since nothing can make it valid.
     */
    _retryPendingSync: function () {
      var pendingKey = this._storageKey + '_pending';
      var pending = Store.get(pendingKey);

      if (!pending) return;

      if (!pending.action) {
        Store.remove(pendingKey);

        return;
      }

      this._sync(pending);
    },

    /**
     * POSTs JSON with a CSRF token, fetching a fresh one and retrying once if
     * the token is rejected.
     *
     * This is what makes consent saving work on statically cached pages. A
     * token rendered into HTML goes stale as soon as that HTML is cached and
     * replayed to other visitors, so no token is embedded at all: one is
     * fetched when first needed, and a rejection is treated as "that token
     * expired" rather than as a failure.
     */
    _post: function (url, payload, options) {
      var self = this;

      // No token means the session endpoint could not be reached, which is a
      // passing condition. Posting anyway guaranteed an `invalid_csrf`
      // refusal, which counted as final — so a brief outage of that one
      // endpoint permanently dropped the consent record.
      var requireToken = function (token) {
        if (!token && self._config.csrfUrl) {
          throw new Error('No CSRF token available');
        }

        return token;
      };

      // Only a rejected token is worth another attempt. The endpoints say so
      // explicitly (`invalid_csrf`); any other 400 is a refusal of the
      // payload itself, and re-sending it would only spend another request
      // against a rate-limited endpoint to be refused again.
      var classify = function (res, second) {
        if (res.status !== 400) return res;

        return self._errorCode(res).then(function (code) {
          if (code !== 'invalid_csrf') return { ok: false, status: 400 };

          // A second rejection right after a fresh token is not the payload's
          // fault either (a session that will not stick, say): report it as
          // retryable later, rather than as a permanent refusal.
          if (second) return { ok: false, status: 0 };

          self._csrfToken = null;
          return self._csrf(true).then(requireToken).then(function (fresh) {
            return self._rawPost(url, payload, fresh, options).then(function (again) {
              return classify(again, true);
            });
          });
        });
      };

      return this._csrf().then(requireToken).then(function (token) {
        return self._rawPost(url, payload, token, options).then(function (res) {
          return classify(res, false);
        });
      }).then(function (res) {
        if (!res.ok) {
          // The status travels with the error, because the caller's decision
          // about whether to try again depends entirely on it.
          var error = new Error('Request failed: ' + res.status);
          error.status = res.status;

          throw error;
        }

        return res.json();
      });
    },

    _rawPost: function (url, payload, token, options) {
      var body = {};
      for (var key in payload) {
        if (Object.prototype.hasOwnProperty.call(payload, key)) body[key] = payload[key];
      }
      body[this._config.csrfTokenName || 'CRAFT_CSRF_TOKEN'] = token || '';

      var headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
      if (token) headers['X-CSRF-Token'] = token;

      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify(body),
        // Lets a consent save outlive the page that sent it.
        keepalive: !!(options && options.keepalive)
      });
    },

    /** The `error` code of a JSON error response, or '' when it has none. */
    _errorCode: function (res) {
      if (!res || typeof res.json !== 'function') return Promise.resolve('');

      return res.json().then(function (json) {
        return (json && typeof json.error === 'string') ? json.error : '';
      }, function () {
        return '';
      });
    },

    /**
     * Fetches (and memoises for the page view) a CSRF token.
     *
     * The request itself is shared, not just its result. A decision starts
     * the consent save and the cookie-name report in the same tick, and each
     * used to fetch a token of its own; on a visitor with no session yet that
     * meant two session requests, two sessions, and one POST carrying a token
     * the other request's cookie had already replaced. A forced refresh
     * starts a new request, which later callers then share in turn.
     */
    _csrf: function (force) {
      var self = this;

      if (this._csrfToken && !force) return Promise.resolve(this._csrfToken);
      if (!this._config.csrfUrl) return Promise.resolve('');
      if (this._csrfPromise && !force) return this._csrfPromise;

      var request = fetch(this._config.csrfUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      }).then(function (res) {
        return res.ok ? res.json() : {};
      }).then(function (json) {
        var token = (json && json.csrfTokenValue) || '';
        if (self._csrfPromise === request) self._csrfToken = token;
        return token;
      }).catch(function () {
        return '';
      }).then(function (token) {
        // Released once settled, so a failed lookup is retried by the next
        // caller rather than remembered for the rest of the page view.
        if (self._csrfPromise === request) self._csrfPromise = null;
        return token;
      });

      this._csrfPromise = request;

      return request;
    },

    /* ---------------------------------------------------------------
       Cookie detection

       Reports cookie NAMES only — never values, never tied to a visitor —
       so the control panel can flag anything nobody has documented yet. This
       is site inventory for the administrator, the same information they
       would get by opening developer tools themselves; it is not visitor
       tracking, and it must stay that way. If this is ever extended to send
       anything beyond bare names, that reasoning no longer holds and it needs
       to be gated behind consent.

       Throttled per browser with an expiry rather than "report each name once
       forever": a permanent throttle would leave a browser silently blind to
       a name the moment the server-side record of it disappeared for any
       reason (a restore, a reinstall, an admin clearing the table), with no
       way for the client to learn that it had. A day-long window means
       detection heals itself instead.

       ## Why this waits for a settled state

       This used to run on every page view, including a visitor's first, before
       they had been asked anything. It POSTs, so it fetches a CSRF token
       first, and that request makes Craft issue a session cookie and a CSRF
       cookie — meaning the cookie-consent plugin was itself the reason a
       visitor who had not yet decided (and one who went on to reject) had
       cookies set. Strictly necessary or not, that is the one thing this
       plugin should not be doing on its own initiative.

       So it is called only once the page view has a settled state: a stored
       decision on load, immediately after a decision is made (_commit(), which
       every path including GPC and DNT goes through), or under a geo bypass.
       In all of those the session either already exists or is being
       established by the consent save itself, so detection adds nothing the
       visitor did not already have. The endpoint keeps its CSRF requirement —
       the fix is to stop calling it early, not to make it cheaper to call.
    --------------------------------------------------------------- */

    _reportDetectedCookies: function () {
      var self = this;
      var cfg = this._config;

      if (!cfg.reportCookiesUrl || !document.cookie || !window.fetch) return;

      var REPORT_TTL_MS = DAY_MS;

      var names = document.cookie.split(';').map(function (part) {
        var eq = part.indexOf('=');
        return (eq === -1 ? part : part.slice(0, eq)).trim();
      }).filter(Boolean);

      if (!names.length) return;

      var reportedKey = 'cck_reported_' + (cfg.siteId !== undefined ? cfg.siteId : '0');
      var now = Date.now();

      // Pruned on every read, so the map holds at most the names seen within
      // the last day rather than every name ever seen. It is kept in
      // localStorage only: with no localStorage it used to fall back to a
      // year-long cookie that grew with each name, went out with every
      // request, and was silently dropped by the browser past 4 KB. Without
      // localStorage names are simply reported once per page view.
      var reported = {};
      var previous = Store.getLocal(reportedKey) || {};
      for (var seen in previous) {
        if (Object.prototype.hasOwnProperty.call(previous, seen) &&
            typeof previous[seen] === 'number' && now - previous[seen] <= REPORT_TTL_MS) {
          reported[seen] = previous[seen];
        }
      }

      var fresh = names.filter(function (name) {
        return !reported[name] || (now - reported[name]) > REPORT_TTL_MS;
      });

      if (!fresh.length) return;

      this._post(cfg.reportCookiesUrl, { names: fresh }).then(function () {
        fresh.forEach(function (name) { reported[name] = now; });
        Store.setLocal(reportedKey, reported);
      }).catch(function () {
        // Best-effort only — a failed report is simply retried on a later
        // page load, and never surfaces to the visitor.
      });
    },

    /* ---------------------------------------------------------------
       Category helpers
    --------------------------------------------------------------- */

    _allCategories: function () {
      var cfg = this._config;
      if (cfg.allCategories && cfg.allCategories.length) return cfg.allCategories.slice();

      return this._categoriesFromDom('input[type="checkbox"][data-category]');
    },

    _lockedCategories: function () {
      var cfg = this._config;
      if (cfg.lockedCategories && cfg.lockedCategories.length) return cfg.lockedCategories.slice();

      return this._categoriesFromDom('input[type="checkbox"][data-category][disabled]');
    },

    /**
     * Fallback for when the runtime config is missing (an unusual but
     * recoverable state — e.g. the banner markup was rendered by a custom
     * template without the config script). Reads the categories out of the
     * rendered preference centre rather than assuming any particular set.
     */
    _categoriesFromDom: function (selector) {
      var modal = this._element('preferences');
      if (!modal) return [];

      return toArray(modal.querySelectorAll(selector)).map(function (cb) {
        return cb.dataset.category;
      }).filter(Boolean);
    },

    /* ---------------------------------------------------------------
       Events

       Dispatched under both the documented `cookieConsent:*` names and the
       original `cck:*` ones, so integrations written against either keep
       working. Mapped explicitly rather than by string-munging, because the
       two sets were never a clean one-to-one.
    --------------------------------------------------------------- */

    _emit: function (name, detail) {
      this._dispatch('cookieConsent:' + name, detail);

      var legacy = {
        loaded: 'cck:loaded',
        changed: 'cck:consent',
        reset: 'cck:reset'
      }[name];

      if (legacy) this._dispatch(legacy, detail);
    },

    /**
     * Dispatched on `document` and allowed to bubble, which is what makes one
     * dispatch reach both documented targets: an event dispatched on the
     * document propagates to `window` as the last step of its path, so
     * `document.addEventListener` (what the README shows) and
     * `window.addEventListener` (what existing integrations use) both fire —
     * exactly once each, from a single event object with an unchanged
     * `detail`. Dispatching twice, or on `window` alone, would break one of
     * the two.
     */
    _dispatch: function (name, detail) {
      try {
        document.dispatchEvent(new CustomEvent(name, { detail: detail, bubbles: true }));
      } catch (e) {
        // CustomEvent is unavailable (very old browsers). Events are an
        // integration convenience; consent itself must not depend on them.
      }
    },

    /* ---------------------------------------------------------------
       Event delegation
    --------------------------------------------------------------- */

    _bindGlobalEvents: function () {
      var self = this;

      document.addEventListener('click', function (e) {
        var btn = closestAction(e.target);
        if (!btn) return;

        var handlers = {
          'accept-all': 'acceptAll',
          'reject-all': 'rejectAll',
          'open-preferences': 'openPreferences',
          'close-preferences': 'closePreferences',
          'save-preferences': 'savePreferences',
          'reset-consent': 'resetConsent'
        };
        var method = handlers[btn.getAttribute('data-cck-action')];

        if (!method) return;

        // A control written as `<a href="#">` would otherwise also navigate
        // (or jump to the top of the page) after doing its job.
        if (typeof e.preventDefault === 'function') e.preventDefault();

        self[method]();
      });

      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' && e.key !== 'Esc') return;

        var modal = self._element('preferences');

        // Escape closes the preference centre and returns to the banner. It
        // deliberately does not dismiss the banner itself: dismissal without
        // a choice would have to be recorded as something, and recording a
        // keypress as consent — or as refusal — misrepresents the visitor.
        if (modal && !modal.hidden) self.closePreferences();
      });
    }
  };

  /* ------------------------------------------------------------------
     Public API

     `window.CookieConsent` is the documented name. `window.CookieConsentKit`
     is the original one and remains a live alias — the same object, not a
     copy — so existing integrations keep working unchanged.
  ------------------------------------------------------------------ */

  window.CookieConsent = CookieConsent;
  window.CookieConsentKit = CookieConsent;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { CookieConsent.init(); });
  } else {
    CookieConsent.init();
  }
}(window, document));
