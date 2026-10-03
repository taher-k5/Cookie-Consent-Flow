/**
 * Cookie Consent Flow — control-panel welcome tour.
 *
 * Configuration arrives in `window.cckTour` (see helpers/Onboarding and
 * Plugin::_registerOnboarding()). With `autoStart`, a welcome dialog opens;
 * the tour then highlights the plugin's sidebar links one by one. Finishing,
 * skipping, pressing Escape or choosing "Don't show again" records the
 * user's "seen" preference so it never starts on its own again; "Later"
 * only hides it until this browser tab is closed. A `[data-cck-tour-replay]`
 * button (bottom of Settings) replays it.
 *
 * Text is set with textContent only: step copy includes translated strings
 * and nav labels, and none of it is markup.
 */
(function () {
  'use strict';

  var LATER_KEY = 'cck_tour_later';
  var NARROW = 640;
  var GAP = 16;

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function button(label, className, onClick) {
    var b = el('button', 'btn' + (className ? ' ' + className : ''), label);
    b.type = 'button';
    b.addEventListener('click', onClick);
    return b;
  }

  function laterThisTab() {
    try { return window.sessionStorage.getItem(LATER_KEY) === '1'; } catch (e) { return false; }
  }

  function rememberLater() {
    try { window.sessionStorage.setItem(LATER_KEY, '1'); } catch (e) {}
  }

  /** The sidebar link a step points at, or null when it isn't on screen. */
  function findTarget(step) {
    if (!step.path) return null;

    var selector = step.nav === 'section'
      ? '#nav .sidebar-action:not(.sidebar-action--sub)'
      : '#nav .sidebar-action--sub';
    var suffix = '/' + String(step.path).replace(/^\/+|\/+$/g, '');
    var links = document.querySelectorAll(selector);

    for (var i = 0; i < links.length; i++) {
      var path;
      try { path = new URL(links[i].href, window.location.href).pathname.replace(/\/+$/, ''); } catch (e) { continue; }
      if (path === suffix || path.slice(-suffix.length) === suffix) return links[i];
    }

    return null;
  }

  function isVisible(node) {
    if (!node) return false;
    var r = node.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && r.bottom > 0 && r.right > 0 &&
      r.top < window.innerHeight && r.left < window.innerWidth;
  }

  function Tour(cfg) {
    this.cfg = cfg;
    this.s = cfg.strings || {};
    this.steps = cfg.steps;
    this.index = 0;
    this.seen = !cfg.autoStart;
    this.target = null;
    this.returnFocus = null;
    this._build();
  }

  Tour.prototype = {
    _build: function () {
      var self = this;

      this.root = el('div', 'cck-tour');
      this.root.hidden = true;
      this.shade = el('div', 'cck-tour__shade');
      this.spot = el('div', 'cck-tour__spot');
      this.card = el('div', 'cck-tour__card');
      this.card.setAttribute('role', 'dialog');
      this.card.setAttribute('aria-modal', 'true');
      this.card.setAttribute('aria-labelledby', 'cck-tour-title');
      this.card.setAttribute('aria-describedby', 'cck-tour-body');

      this.eyebrow = el('div', 'cck-tour__eyebrow');
      this.title = el('h2', 'cck-tour__title');
      this.title.id = 'cck-tour-title';
      this.body = el('p', 'cck-tour__body');
      this.body.id = 'cck-tour-body';
      this.code = el('pre', 'cck-tour__code');
      this.footer = el('div', 'cck-tour__footer');
      this.dots = el('div', 'cck-tour__dots');
      this.dots.setAttribute('aria-hidden', 'true');
      this.actions = el('div', 'cck-tour__actions');

      this.footer.appendChild(this.dots);
      this.footer.appendChild(this.actions);
      [this.eyebrow, this.title, this.body, this.code, this.footer].forEach(function (n) { self.card.appendChild(n); });
      [this.shade, this.spot, this.card].forEach(function (n) { self.root.appendChild(n); });
      document.body.appendChild(this.root);

      this._onKey = function (e) { self._key(e); };
      this._onMove = function () { self._place(); };
    },

    _open: function () {
      if (this.root.hidden) {
        this.returnFocus = document.activeElement;
        this.root.hidden = false;
        document.addEventListener('keydown', this._onKey, true);
        window.addEventListener('resize', this._onMove);
        window.addEventListener('scroll', this._onMove, true);
      }
    },

    close: function (markSeen) {
      if (markSeen) this._save();
      this.root.hidden = true;
      this.target = null;
      document.removeEventListener('keydown', this._onKey, true);
      window.removeEventListener('resize', this._onMove);
      window.removeEventListener('scroll', this._onMove, true);
      if (this.returnFocus && this.returnFocus.focus) {
        try { this.returnFocus.focus(); } catch (e) {}
      }
    },

    _save: function () {
      if (this.seen) return;
      this.seen = true;
      // On failure the tour simply offers itself again next time.
      if (window.Craft && Craft.sendActionRequest) {
        Craft.sendActionRequest('POST', this.cfg.saveAction).catch(function () {});
      }
    },

    _setActions: function (buttons) {
      var actions = this.actions;
      while (actions.firstChild) actions.removeChild(actions.firstChild);
      buttons.forEach(function (b) { if (b) actions.appendChild(b); });
    },

    welcome: function () {
      var self = this, s = this.s;
      this._open();
      this.root.className = 'cck-tour cck-tour--centered';
      this.target = null;
      this.eyebrow.textContent = s.welcomeEyebrow || '';
      this.title.textContent = s.welcomeTitle || '';
      this.body.textContent = s.welcomeBody || '';
      this.code.hidden = true;
      this.dots.textContent = '';
      var start = button(s.start, 'submit', function () { self.show(0); });
      this._setActions([
        button(s.never, 'cck-tour__quiet', function () { self.close(true); }),
        button(s.later, '', function () { rememberLater(); self.close(false); }),
        start
      ]);
      this._place();
      start.focus();
    },

    show: function (i) {
      var self = this, s = this.s, step = this.steps[i], total = this.steps.length;
      if (!step) return;
      this._open();
      this.index = i;

      this.target = findTarget(step);
      if (this.target && this.target.scrollIntoView) {
        try { this.target.scrollIntoView({block: 'nearest'}); } catch (e) {}
      }
      if (!isVisible(this.target)) this.target = null;
      this.root.className = 'cck-tour' + (this.target ? '' : ' cck-tour--centered');

      this.eyebrow.textContent = String(s.stepOf || '{current} of {total}')
        .replace('{current}', String(i + 1)).replace('{total}', String(total));
      this.title.textContent = step.title;
      this.body.textContent = step.body;
      this.code.hidden = !step.code;
      this.code.textContent = step.code || '';

      this.dots.textContent = '';
      for (var d = 0; d < total; d++) this.dots.appendChild(el('i', d === i ? 'is-on' : ''));

      var last = i === total - 1;
      var next = button(last ? s.finish : s.next, 'submit', function () {
        if (last) self.close(true); else self.show(i + 1);
      });
      this._setActions([
        last ? null : button(s.skip, 'cck-tour__quiet', function () { self.close(true); }),
        i > 0 ? button(s.back, '', function () { self.show(i - 1); }) : null,
        next
      ]);

      this._place();
      next.focus();
    },

    /** Positions the highlight and the card; centred when there is no target. */
    _place: function () {
      if (this.root.hidden) return;

      var card = this.card, vw = window.innerWidth, vh = window.innerHeight;

      if (!this.target || !isVisible(this.target)) {
        this.root.classList.add('cck-tour--centered');
        card.style.left = card.style.top = '';
        return;
      }

      this.root.classList.remove('cck-tour--centered');
      var r = this.target.getBoundingClientRect(), pad = 4;
      this.spot.style.left = (r.left - pad) + 'px';
      this.spot.style.top = (r.top - pad) + 'px';
      this.spot.style.width = (r.width + pad * 2) + 'px';
      this.spot.style.height = (r.height + pad * 2) + 'px';

      // Phones: the card is a bottom sheet (CSS); only the highlight moves.
      if (vw <= NARROW) {
        card.style.left = card.style.top = '';
        return;
      }

      var cw = card.offsetWidth, ch = card.offsetHeight;
      var rtl = document.documentElement.dir === 'rtl';
      var left = rtl ? r.left - cw - GAP : r.right + GAP;

      if (left < GAP || left + cw > vw - GAP) left = rtl ? r.right + GAP : r.left - cw - GAP;
      if (left < GAP || left + cw > vw - GAP) left = Math.min(Math.max(GAP, r.left), vw - cw - GAP);

      var top = Math.min(Math.max(GAP, r.top + r.height / 2 - ch / 2), vh - ch - GAP);
      card.style.left = Math.max(GAP, left) + 'px';
      card.style.top = Math.max(GAP, top) + 'px';
    },

    _key: function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        this.close(true);
        return;
      }

      if (e.key === 'Tab') {
        var focusable = this.card.querySelectorAll('button');
        if (!focusable.length) return;
        var first = focusable[0], last = focusable[focusable.length - 1];
        if (!this.card.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
        else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    }
  };

  function init() {
    var cfg = window.cckTour;
    if (!cfg || !cfg.steps || !cfg.steps.length) return;

    var tour = new Tour(cfg);

    document.querySelectorAll('[data-cck-tour-replay]').forEach(function (b) {
      b.addEventListener('click', function () { tour.show(0); });
    });

    if (cfg.autoStart && !laterThisTab()) tour.welcome();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
