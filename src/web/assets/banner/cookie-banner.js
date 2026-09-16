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

  /**
   * localStorage with a cookie fallback, and every access guarded.
   *
   * Storage can be absent or throw outright (private browsing, blocked site
   * data, a sandboxed iframe). None of those may break the page, so every
   * failure degrades to "no stored decision", which shows the banner — the
   * safe direction to fail in, since it asks rather than assumes.
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
        return true;
      } catch (e) {
        return Store._setCookie(key, str, 365);
      }
    },

    get: function (key) {
      var raw = null;
      try {
        raw = window.localStorage.getItem(key);
      } catch (e) {
        raw = Store._getCookie(key);
      }
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

  /* ------------------------------------------------------------------
     Focus management

     The preference centre is a real modal dialog, so keyboard users must be
     able to operate it and must not be able to tab out of it into the page
     behind. The previous implementation added a keydown listener per open and
     only removed it on the next Tab press after hiding, which accumulated
     listeners across repeated opens; this keeps exactly one and removes it
     deterministically on close.
  ------------------------------------------------------------------ */

  var FOCUSABLE =
    'button:not([disabled]), input:not([disabled]), select:not([disabled]), ' +
    'textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

  var FocusTrap = {
    _element: null,
    _handler: null,

    activate: function (el) {
      FocusTrap.release();

      FocusTrap._element = el;
      FocusTrap._handler = function (e) {
        if (e.key !== 'Tab') return;

        var focusable = toArray(el.querySelectorAll(FOCUSABLE)).filter(function (node) {
          return node.offsetParent !== null || node === document.activeElement;
        });
        if (!focusable.length) return;

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      };

      el.addEventListener('keydown', FocusTrap._handler);
    },

    release: function () {
      if (FocusTrap._element && FocusTrap._handler) {
        FocusTrap._element.removeEventListener('keydown', FocusTrap._handler);
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

  function activateGatedScripts(categories) {
    toArray(document.querySelectorAll('script[type="text/plain"][data-cck-category]')).forEach(function (node) {
      if (!isAllowed(node, categories)) return;

      var script = document.createElement('script');

      toArray(node.attributes).forEach(function (attr) {
        if (attr.name === 'type' || attr.name === 'data-cck-category') return;
        script.setAttribute(attr.name === 'data-cck-src' ? 'src' : attr.name, attr.value);
      });

      if (!script.src) script.text = node.textContent;

      if (node.parentNode) node.parentNode.replaceChild(script, node);
    });
  }

  function activateGatedFrames(categories) {
    toArray(
      document.querySelectorAll('iframe[data-cck-category][data-cck-src]:not([data-cck-activated])')
    ).forEach(function (node) {
      if (!isAllowed(node, categories)) return;
      node.setAttribute('src', node.getAttribute('data-cck-src'));
      node.setAttribute('data-cck-activated', 'true');
    });
  }

  function activateGatedContent(categories) {
    categories = categories || [];
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
    _config: {},
    _previousFocus: null,
    /** What had focus before a modal (center-popup) banner took it. */
    _bannerPreviousFocus: null,
    _storageKey: LEGACY_STORAGE_KEY,
    _visitorKey: LEGACY_VISITOR_KEY,
    _csrfToken: null,
    _ready: false,

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

    init: function () {
      if (this._ready) return;
      this._ready = true;

      this._config = window.cckConfig || {};
      this._resolveStorageKeys();

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
        this._emit('ready', stored);
        return;
      }

      // No valid stored decision. A browser privacy signal may answer for the
      // visitor before we ask them anything.
      if (this._applyPrivacySignal()) return;

      this._emit('ready', null);
      this._maybeShowBanner();
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

      if (Store.get(this._storageKey) === null) {
        var legacy = Store.get(LEGACY_STORAGE_KEY);
        if (legacy !== null) {
          Store.set(this._storageKey, legacy);
          Store.remove(LEGACY_STORAGE_KEY);
        }
      }
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

      if (cfg.consentExpiryDays && migrated.timestamp &&
          Date.now() - migrated.timestamp > cfg.consentExpiryDays * DAY_MS) {
        return null;
      }

      var known = cfg.allCategories || [];
      var locked = cfg.lockedCategories || [];

      var categories = migrated.categories.filter(function (key) {
        return known.length === 0 || known.indexOf(key) !== -1;
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

      activateGatedContent(this._geoBypass ? this._allCategories() : []);
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
     * Returns true when a signal was applied and the banner should stay hidden.
     */
    _applyPrivacySignal: function () {
      var cfg = this._config;
      var nav = window.navigator || {};

      var gpc = cfg.respectGpc && nav.globalPrivacyControl === true;
      var dnt = cfg.respectDnt && (nav.doNotTrack === '1' || window.doNotTrack === '1' || nav.msDoNotTrack === '1');

      if (!gpc && !dnt) return false;

      this._commit('reject_all', this._lockedCategories(), gpc ? 'gpc' : 'dnt');

      return true;
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

      fetch(cfg.geoUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      }).then(function (res) {
        return res.ok ? res.json() : { show: true };
      }).then(function (json) {
        var show = !json || json.show !== false;
        try {
          window.sessionStorage.setItem(cacheKey, show ? 'show' : 'hide');
        } catch (e) {}

        if (show) self._showBanner();
        else self._applyGeoBypass(json && json.country);
      }).catch(function () {
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
      this._hideBanner();
      this.closePreferences();

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
     */
    _pushConsentMode: function (categories) {
      var mode = this._config.consentMode;
      if (!mode || !mode.enabled || !mode.signals) return;

      var update = {};

      for (var category in mode.signals) {
        if (!Object.prototype.hasOwnProperty.call(mode.signals, category)) continue;

        var granted = categories.indexOf(category) !== -1;
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

      this._post(cfg.saveUrl, { action: data.action, categories: data.categories, source: data.source })
        .then(function (json) {
          if (generation !== self._syncGeneration) return;

          if (json && json.visitorUuid) Store.set(self._visitorKey, json.visitorUuid);
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

          var attempts = (typeof data.syncAttempts === 'number' ? data.syncAttempts : 0) + 1;

          if (attempts > MAX_SYNC_ATTEMPTS) {
            Store.remove(pendingKey);
            self._emit('syncFailed', {
              permanent: false,
              status: (error && error.status) || 0,
              attempts: attempts
            });

            return;
          }

          // Queued as a copy: the attempt count is bookkeeping for the queue
          // and has no business in the decision the visitor's storage holds.
          Store.set(pendingKey, assign(assign({}, data), { syncAttempts: attempts }));
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
    _post: function (url, payload) {
      var self = this;

      return this._csrf().then(function (token) {
        return self._rawPost(url, payload, token).then(function (res) {
          if (res.status !== 400) return res;

          // Discard and re-fetch, then try once more. Only once — a second
          // failure is a real problem, not a stale token, and retrying
          // further would just hammer the endpoint.
          self._csrfToken = null;
          return self._csrf(true).then(function (fresh) {
            return self._rawPost(url, payload, fresh);
          });
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

    _rawPost: function (url, payload, token) {
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
        body: JSON.stringify(body)
      });
    },

    /** Fetches (and memoises for the page view) a CSRF token. */
    _csrf: function (force) {
      var self = this;

      if (this._csrfToken && !force) return Promise.resolve(this._csrfToken);
      if (!this._config.csrfUrl) return Promise.resolve('');

      return fetch(this._config.csrfUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      }).then(function (res) {
        return res.ok ? res.json() : {};
      }).then(function (json) {
        self._csrfToken = (json && json.csrfTokenValue) || '';
        return self._csrfToken;
      }).catch(function () {
        return '';
      });
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
      var reported = Store.get(reportedKey) || {};
      var now = Date.now();

      var fresh = names.filter(function (name) {
        return !reported[name] || (now - reported[name]) > REPORT_TTL_MS;
      });

      if (!fresh.length) return;

      this._post(cfg.reportCookiesUrl, { names: fresh }).then(function () {
        fresh.forEach(function (name) { reported[name] = now; });
        Store.set(reportedKey, reported);
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
        var btn = e.target && e.target.closest ? e.target.closest('[data-cck-action]') : null;
        if (!btn) return;

        switch (btn.getAttribute('data-cck-action')) {
          case 'accept-all': self.acceptAll(); break;
          case 'reject-all': self.rejectAll(); break;
          case 'open-preferences': self.openPreferences(); break;
          case 'close-preferences': self.closePreferences(); break;
          case 'save-preferences': self.savePreferences(); break;
          case 'reset-consent': self.resetConsent(); break;
          default: return;
        }
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
