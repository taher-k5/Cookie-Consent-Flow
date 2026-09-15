/**
 * Cookie Consent Flow — Frontend Banner JS
 *
 * Handles banner visibility, preference modal, consent storage,
 * server sync, accessibility (focus trap, keyboard nav, ARIA).
 *
 * No external dependencies. ES2017+.
 */
(function (window, document) {
  'use strict';

  // Legacy, unnamespaced keys from before multi-site support. Kept as a
  // one-time migration source (see _resolveStorageKeys below) so upgrading
  // an existing single-site install doesn't lose stored consent.
  var LEGACY_STORAGE_KEY = 'cck_consent';
  var LEGACY_VISITOR_KEY = 'cck_visitor';

  /* ------------------------------------------------------------------
     Utility: localStorage with cookie fallback
  ------------------------------------------------------------------ */
  var Store = {
    set: function (key, value) {
      var str = JSON.stringify(value);
      try {
        localStorage.setItem(key, str);
      } catch (e) {
        Store._setCookie(key, str, 365);
      }
    },
    get: function (key) {
      try {
        var val = localStorage.getItem(key);
        return val ? JSON.parse(val) : null;
      } catch (e) {
        var c = Store._getCookie(key);
        return c ? JSON.parse(c) : null;
      }
    },
    remove: function (key) {
      try { localStorage.removeItem(key); } catch (e) {}
      Store._deleteCookie(key);
    },
    _setCookie: function (name, value, days) {
      var expires = new Date(Date.now() + days * 864e5).toUTCString();
      document.cookie =
        name + '=' + encodeURIComponent(value) +
        ';expires=' + expires +
        ';path=/;SameSite=Lax';
    },
    _getCookie: function (name) {
      var match = document.cookie.match(
        new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)')
      );
      return match ? decodeURIComponent(match[1]) : null;
    },
    _deleteCookie: function (name) {
      document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Lax';
    }
  };

  /* ------------------------------------------------------------------
     Focus trap helper
  ------------------------------------------------------------------ */
  function trapFocus(el) {
    var focusableSelectors =
      'button:not([disabled]), input:not([disabled]), select:not([disabled]), ' +
      'textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

    function handler(e) {
      if (e.key !== 'Tab') return;

      // Remove trap once the element is hidden
      if (el.hasAttribute('hidden')) {
        el.removeEventListener('keydown', handler);
        return;
      }

      var focusable = Array.prototype.slice.call(el.querySelectorAll(focusableSelectors));
      if (!focusable.length) return;

      var first = focusable[0];
      var last  = focusable[focusable.length - 1];

      if (e.shiftKey) {
        if (document.activeElement === first) {
          e.preventDefault();
          last.focus();
        }
      } else {
        if (document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    }

    el.addEventListener('keydown', handler);
  }

  /* ------------------------------------------------------------------
     Gated content — scripts and iframes withheld until their category
     has been accepted.

     Markup convention:

       <script type="text/plain" data-cck-category="analytics"
               data-cck-src="https://www.googletagmanager.com/gtag/js?id=XXX"></script>

       <script type="text/plain" data-cck-category="analytics">
         // inline tracking code
       </script>

       <iframe data-cck-category="marketing"
               data-cck-src="https://www.youtube.com/embed/XXX"></iframe>

     `data-cck-category` accepts a single key or a comma/space separated
     list; the element activates if the visitor accepted ANY of them.
     Browsers never execute type="text/plain" scripts, so the placeholder
     is inert until this code clones it into a real <script> tag.

     Note: this only prevents gated content from loading in the first
     place. It cannot "unload" a script that already ran earlier in the
     same page view (e.g. if a visitor switches from Accept to Reject
     mid-session) — that is a limitation of the browser, not this code.
  ------------------------------------------------------------------ */
  function activateGatedScripts(categories) {
    var nodes = document.querySelectorAll('script[type="text/plain"][data-cck-category]');

    nodes.forEach(function (node) {
      var required = node.getAttribute('data-cck-category').split(/[\s,]+/).filter(Boolean);
      var allowed  = required.some(function (cat) { return categories.indexOf(cat) !== -1; });
      if (!allowed) return;

      var script = document.createElement('script');

      Array.prototype.forEach.call(node.attributes, function (attr) {
        if (attr.name === 'type' || attr.name === 'data-cck-category') return;
        if (attr.name === 'data-cck-src') {
          script.setAttribute('src', attr.value);
          return;
        }
        script.setAttribute(attr.name, attr.value);
      });

      if (!script.src) {
        script.text = node.textContent;
      }

      node.parentNode.replaceChild(script, node);
    });
  }

  function activateGatedFrames(categories) {
    var nodes = document.querySelectorAll('iframe[data-cck-category][data-cck-src]:not([data-cck-activated])');

    nodes.forEach(function (node) {
      var required = node.getAttribute('data-cck-category').split(/[\s,]+/).filter(Boolean);
      var allowed  = required.some(function (cat) { return categories.indexOf(cat) !== -1; });
      if (!allowed) return;

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
     CookieConsentKit module
  ------------------------------------------------------------------ */
  var CookieConsentKit = {
    _config: null,
    _previousFocus: null,
    _storageKey: LEGACY_STORAGE_KEY,
    _visitorKey: LEGACY_VISITOR_KEY,

    // ----------------------------------------------------------------
    // Initialise
    // ----------------------------------------------------------------
    init: function () {
      this._config = window.cckConfig || {};
      this._resolveStorageKeys();

      // Always bind action-button delegation, even if consent already
      // exists — a persistent "manage preferences" / "reset consent" link
      // (renderPreferencesButton() / resetConsent()) must keep working
      // after the initial decision, not just while the banner is showing.
      this._bindGlobalEvents();

      // Runs regardless of consent state (deliberately before the
      // hasConsent() branch below) — the whole point is to surface cookies
      // nobody has documented yet, including necessary/technical ones that
      // exist before any consent decision at all.
      this._reportDetectedCookies();

      if (this.hasConsent()) {
        // Restore categories so third-party scripts can read them
        var stored = this.getConsent();
        if (stored) {
          activateGatedContent(stored.categories || []);
          this._dispatchEvent('cck:loaded', stored);
        }
        return;
      }

      this._showBanner();
    },

    // ----------------------------------------------------------------
    // Namespace consent/visitor storage by Craft site so a decision made
    // on one site is never silently read as consent for another on a
    // shared-origin multi-site install (e.g. example.com/en vs
    // example.com/de as separate Craft sites). On the first run for a
    // given site, if a legacy unnamespaced value exists, adopt it into the
    // namespaced key and delete the legacy key — this preserves existing
    // consent for the common single-site case (no visible change; it's
    // just renamed under the hood) while every *other* site on a shared
    // origin correctly starts fresh, since only one site can "win" the
    // legacy key on first visit after upgrade.
    // ----------------------------------------------------------------
    _resolveStorageKeys: function () {
      var siteId = this._config && this._config.siteId;
      if (siteId === undefined || siteId === null) {
        // No site context (shouldn't normally happen) — keep legacy keys
        // rather than namespacing under a meaningless placeholder.
        return;
      }

      this._storageKey = LEGACY_STORAGE_KEY + '_' + siteId;
      this._visitorKey = LEGACY_VISITOR_KEY + '_' + siteId;

      if (Store.get(this._storageKey) === null) {
        var legacyConsent = Store.get(LEGACY_STORAGE_KEY);
        if (legacyConsent !== null) {
          Store.set(this._storageKey, legacyConsent);
          Store.remove(LEGACY_STORAGE_KEY);
        }
      }
    },

    // ----------------------------------------------------------------
    // Consent state
    //
    // A stored decision is only valid if it is both fresh (within
    // consentExpiryDays — ICO/CNIL guidance recommends re-asking within
    // ~6-12 months; 0 disables expiry) and against the current
    // policyVersion (bumped whenever the cookie policy/category list
    // changes materially). Either check failing means the visitor must
    // re-consent, so hasConsent()/getConsent() treat it as if nothing were
    // stored at all — this is what makes the banner reappear and stops
    // refreshGatedContent()/init() from activating gated content on stale
    // consent.
    // ----------------------------------------------------------------
    hasConsent: function () {
      var stored = Store.get(this._storageKey);
      if (!stored) return false;

      var cfg = this._config || {};

      if (cfg.policyVersion && stored.policyVersion !== cfg.policyVersion) {
        return false;
      }

      var expiryDays = cfg.consentExpiryDays;
      if (expiryDays && stored.timestamp) {
        if (Date.now() - stored.timestamp > expiryDays * 86400000) {
          return false;
        }
      }

      return true;
    },

    getConsent: function () {
      return this.hasConsent() ? Store.get(this._storageKey) : null;
    },

    // ----------------------------------------------------------------
    // Re-scan the page for gated scripts/iframes against the visitor's
    // current consent. Call this after injecting new markup at runtime
    // (AJAX-loaded content, SPA-style navigation, etc.) — activation
    // otherwise only runs once, on page load / on consent change.
    // ----------------------------------------------------------------
    refreshGatedContent: function () {
      var stored = this.getConsent();
      activateGatedContent(stored && stored.categories ? stored.categories : []);
    },

    // ----------------------------------------------------------------
    // Banner visibility
    // ----------------------------------------------------------------
    _showBanner: function () {
      var banner = document.getElementById('cck-banner');
      if (!banner) return;
      banner.removeAttribute('hidden');
      banner.setAttribute('aria-hidden', 'false');
      banner.classList.add('cck-visible');
    },

    _hideBanner: function () {
      var banner = document.getElementById('cck-banner');
      if (!banner) return;
      banner.setAttribute('hidden', '');
      banner.setAttribute('aria-hidden', 'true');
      banner.classList.remove('cck-visible');
    },

    // ----------------------------------------------------------------
    // Preferences modal
    // ----------------------------------------------------------------
    openPreferences: function () {
      var modal = document.getElementById('cck-preferences');
      if (!modal) return;

      this._previousFocus = document.activeElement;

      modal.removeAttribute('hidden');
      modal.setAttribute('aria-hidden', 'false');
      trapFocus(modal);

      // Restore checked state from storage
      var stored = Store.get(this._storageKey);
      if (stored && stored.categories) {
        var checkboxes = modal.querySelectorAll('input[type="checkbox"][data-category]');
        checkboxes.forEach(function (cb) {
          if (!cb.disabled) {
            cb.checked = stored.categories.indexOf(cb.dataset.category) !== -1;
          }
        });
      }

      // Focus the close button
      var closeBtn = modal.querySelector('.cck-preferences__close');
      if (closeBtn) {
        setTimeout(function () { closeBtn.focus(); }, 50);
      }
    },

    closePreferences: function () {
      var modal = document.getElementById('cck-preferences');
      if (!modal) return;
      modal.setAttribute('hidden', '');
      modal.setAttribute('aria-hidden', 'true');

      if (this._previousFocus && typeof this._previousFocus.focus === 'function') {
        this._previousFocus.focus();
      }
    },

    // ----------------------------------------------------------------
    // Consent actions
    // ----------------------------------------------------------------
    acceptAll: function () {
      var keys = this._getAllCategoryKeys();
      this._commit('accept_all', keys);
    },

    rejectAll: function () {
      var keys = this._getLockedCategoryKeys();
      this._commit('reject_all', keys);
    },

    savePreferences: function () {
      var modal = document.getElementById('cck-preferences');
      if (!modal) return;
      var checked = [];
      modal.querySelectorAll('input[type="checkbox"][data-category]').forEach(function (cb) {
        if (cb.checked && cb.dataset.category) {
          checked.push(cb.dataset.category);
        }
      });
      this._commit('custom', checked);
    },

    resetConsent: function () {
    Store.remove(this._storageKey);
    Store.remove(this._visitorKey);

    this._dispatchEvent('cck:reset', {});
    this._showBanner();
    },

    // ----------------------------------------------------------------
    // Internal commit
    // ----------------------------------------------------------------
    _commit: function (action, categories) {
      var data = {
        action:        action,
        categories:    categories,
        timestamp:     Date.now(),
        policyVersion: (this._config && this._config.policyVersion) || ''
      };

      Store.set(this._storageKey, data);
      activateGatedContent(categories);
      this._dispatchEvent('cck:consent', data);
      this._hideBanner();
      this.closePreferences();
      this._sync(action, categories);
    },

    // ----------------------------------------------------------------
    // Server sync
    // ----------------------------------------------------------------
    _sync: function (action, categories) {
      var cfg = this._config;
      var visitorKey = this._visitorKey;
      if (!cfg || !cfg.saveUrl) return;

      var body = {};
      body[cfg.csrfTokenName || 'CRAFT_CSRF_TOKEN'] = cfg.csrfToken || '';
      body.action     = action;
      body.categories = categories;

      var headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      };
      if (cfg.csrfToken) {
        headers['X-CSRF-Token'] = cfg.csrfToken;
      }

      fetch(cfg.saveUrl, {
        method:      'POST',
        credentials: 'same-origin',
        headers:     headers,
        body:        JSON.stringify(body)
      }).then(function (res) {
        return res.json();
      }).then(function (json) {
        if (json && json.visitorUuid) {
          Store.set(visitorKey, json.visitorUuid);
        }
      }).catch(function () {
        // Consent is saved locally; server sync failure is non-fatal.
      });
    },

    // ----------------------------------------------------------------
    // Cookie detection — reports cookie NAMES only (never values) so the
    // Cookies CP page can flag anything a developer hasn't documented yet,
    // without requiring them to already know it exists. Throttled via
    // localStorage so a given browser doesn't re-POST every name on every
    // single page load — but the throttle expires after REPORT_TTL_MS
    // rather than lasting forever. A "report once ever" throttle would
    // permanently blind a browser to a name the moment the server-side
    // record of it is gone for any reason (DB restore, a plugin reinstall
    // during dev, an admin clearing the table to re-test) — the client has
    // no way to know the server "forgot", so it would just never re-report
    // that name again. A day-long TTL means detection self-heals within a
    // day of any such reset instead of silently staying blind forever.
    // ----------------------------------------------------------------
    _reportDetectedCookies: function () {
      var cfg = this._config;
      if (!cfg || !cfg.reportCookiesUrl || !document.cookie) return;

      var REPORT_TTL_MS = 24 * 60 * 60 * 1000;

      var names = document.cookie.split(';').map(function (part) {
        var eq = part.indexOf('=');
        return (eq === -1 ? part : part.slice(0, eq)).trim();
      }).filter(Boolean);

      if (!names.length) return;

      var reportedKey = 'cck_reported_' + (cfg.siteId !== undefined ? cfg.siteId : '0');
      var reported     = Store.get(reportedKey) || {};
      var now          = Date.now();
      var newNames     = names.filter(function (n) {
        return !reported[n] || (now - reported[n]) > REPORT_TTL_MS;
      });

      if (!newNames.length) return;

      var body = {};
      body[cfg.csrfTokenName || 'CRAFT_CSRF_TOKEN'] = cfg.csrfToken || '';
      body.names = newNames;

      var headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      };
      if (cfg.csrfToken) {
        headers['X-CSRF-Token'] = cfg.csrfToken;
      }

      fetch(cfg.reportCookiesUrl, {
        method:      'POST',
        credentials: 'same-origin',
        headers:     headers,
        body:        JSON.stringify(body)
      }).then(function () {
        newNames.forEach(function (n) { reported[n] = now; });
        Store.set(reportedKey, reported);
      }).catch(function () {
        // Best-effort telemetry only; a failed report just means it's
        // retried on a future page load instead of this one.
      });
    },

    // ----------------------------------------------------------------
    // Category key helpers
    // ----------------------------------------------------------------
    _getAllCategoryKeys: function () {
      var cfg = this._config;
      if (cfg && cfg.allCategories && cfg.allCategories.length) {
        return cfg.allCategories.slice();
      }
      // Fallback: collect from DOM
      var keys = [];
      var modal = document.getElementById('cck-preferences');
      if (modal) {
        modal.querySelectorAll('input[type="checkbox"][data-category]').forEach(function (cb) {
          if (cb.dataset.category) keys.push(cb.dataset.category);
        });
      }
      return keys.length ? keys : ['necessary'];
    },

    _getLockedCategoryKeys: function () {
      var cfg = this._config;
      if (cfg && cfg.lockedCategories && cfg.lockedCategories.length) {
        return cfg.lockedCategories.slice();
      }
      // Fallback: collect disabled checkboxes
      var keys = [];
      var modal = document.getElementById('cck-preferences');
      if (modal) {
        modal.querySelectorAll('input[type="checkbox"][data-category][disabled]').forEach(function (cb) {
          if (cb.dataset.category) keys.push(cb.dataset.category);
        });
      }
      return keys.length ? keys : ['necessary'];
    },

    // ----------------------------------------------------------------
    // Custom event dispatch
    // ----------------------------------------------------------------
    _dispatchEvent: function (name, detail) {
      try {
        window.dispatchEvent(new CustomEvent(name, { detail: detail, bubbles: false }));
      } catch (e) {}
    },

    // ----------------------------------------------------------------
    // Global event delegation
    // ----------------------------------------------------------------
    _bindGlobalEvents: function () {
      var self = this;

      // Action buttons (data-cck-action attribute)
      document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-cck-action]');
        if (!btn) return;

        var action = btn.getAttribute('data-cck-action');
        switch (action) {
          case 'accept-all':        self.acceptAll();       break;
          case 'reject-all':        self.rejectAll();       break;
          case 'open-preferences':  self.openPreferences(); break;
          case 'close-preferences': self.closePreferences(); break;
          case 'save-preferences':  self.savePreferences(); break;
          case 'reset-consent':     self.resetConsent();    break;
        }
      });

      // Keyboard: Escape closes preferences
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          var modal = document.getElementById('cck-preferences');
          if (modal && !modal.hasAttribute('hidden')) {
            self.closePreferences();
          }
        }
      });
    }
  };

  // Expose globally so Twig-rendered reopen/reset buttons can call it
  window.CookieConsentKit = CookieConsentKit;

  // Auto-init on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { CookieConsentKit.init(); });
  } else {
    CookieConsentKit.init();
  }

}(window, document));
