'use strict';

/**
 * The smallest browser the consent runtime will run in.
 *
 * cookie-banner.js has no dependencies and no build step, which is what makes
 * it worth testing directly rather than through a headless browser: the
 * behaviour that matters here — whether a gated script was activated, whether a
 * request was made before consent, whether a queued sync was retried — is all
 * decidable from a handful of DOM and network primitives.
 *
 * So this implements only what the runtime actually touches. It is deliberately
 * not a DOM: an element knows its attributes, its children and its text, and
 * the selector engine understands exactly the selectors the runtime uses. When
 * the runtime starts using something else, this should fail loudly rather than
 * quietly return nothing — see matches() below.
 */

// ---------------------------------------------------------------------------
// Selectors
// ---------------------------------------------------------------------------

/**
 * Parses one compound selector: a tag, any number of `[attr]` /
 * `[attr="value"]` clauses, `.class`, and a single trailing `:not([attr])`.
 */
function parseSelector(selector) {
  var negated = null;
  var notMatch = selector.match(/:not\(\[([\w-]+)\]\)\s*$/);

  if (notMatch) {
    negated = notMatch[1];
    selector = selector.slice(0, notMatch.index);
  }

  var tag = null;
  var classes = [];
  var attrs = [];
  var rest = selector.trim();

  var tagMatch = rest.match(/^[a-zA-Z][\w-]*/);
  if (tagMatch) {
    tag = tagMatch[0].toUpperCase();
    rest = rest.slice(tagMatch[0].length);
  }

  var token;
  var pattern = /\.([\w-]+)|\[([\w-]+)(?:=["']([^"']*)["'])?\]/g;
  var consumed = 0;

  while ((token = pattern.exec(rest)) !== null) {
    consumed += token[0].length;

    if (token[1] !== undefined) {
      classes.push(token[1]);
    } else {
      attrs.push({ name: token[2], value: token[3] });
    }
  }

  if (consumed !== rest.replace(/\s+/g, '').length) {
    throw new Error('dom-stub: unsupported selector fragment in "' + selector + '"');
  }

  return { tag: tag, classes: classes, attrs: attrs, negated: negated };
}

function matchesOne(element, parsed) {
  if (parsed.tag && element.tagName !== parsed.tag) return false;
  if (parsed.negated !== null && element.getAttribute(parsed.negated) !== null) return false;

  for (var i = 0; i < parsed.classes.length; i++) {
    if (!element.classList.contains(parsed.classes[i])) return false;
  }

  for (var j = 0; j < parsed.attrs.length; j++) {
    var attr = parsed.attrs[j];
    var actual = element.getAttribute(attr.name);

    if (actual === null) return false;
    if (attr.value !== undefined && actual !== attr.value) return false;
  }

  return true;
}

/** Supports comma-separated selector lists, as the focus-trap selector is. */
function matches(element, selector) {
  var parts = selector.split(',');

  for (var i = 0; i < parts.length; i++) {
    var part = parts[i].trim();

    if (part === '') continue;
    if (matchesOne(element, parseSelector(part))) return true;
  }

  return false;
}

// ---------------------------------------------------------------------------
// Elements
// ---------------------------------------------------------------------------

function Element(tagName, document) {
  this.tagName = String(tagName).toUpperCase();
  this.ownerDocument = document;
  this.childNodes = [];
  this.parentNode = null;
  this.textContent = '';
  this.hidden = false;
  this.checked = false;
  this.disabled = false;

  // Non-null means "rendered", which is all the focus trap asks.
  this.offsetParent = {};

  this._attrs = {};

  var self = this;
  this.classList = {
    add: function (name) { self._classes()[name] = true; self._syncClass(); },
    remove: function (name) { delete self._classes()[name]; self._syncClass(); },
    contains: function (name) { return Object.prototype.hasOwnProperty.call(self._classes(), name); }
  };
}

Element.prototype._classes = function () {
  if (!this._classMap) {
    this._classMap = {};
    (this._attrs['class'] || '').split(/\s+/).forEach(function (name) {
      if (name) this._classMap[name] = true;
    }, this);
  }

  return this._classMap;
};

Element.prototype._syncClass = function () {
  this._attrs['class'] = Object.keys(this._classes()).join(' ');
};

Element.prototype.setAttribute = function (name, value) {
  this._attrs[name] = String(value);
  if (name === 'class') this._classMap = null;
};

Element.prototype.getAttribute = function (name) {
  return Object.prototype.hasOwnProperty.call(this._attrs, name) ? this._attrs[name] : null;
};

Element.prototype.removeAttribute = function (name) {
  delete this._attrs[name];
};

Element.prototype.appendChild = function (child) {
  child.parentNode = this;
  this.childNodes.push(child);

  return child;
};

Element.prototype.replaceChild = function (fresh, stale) {
  var index = this.childNodes.indexOf(stale);

  if (index === -1) throw new Error('dom-stub: replaceChild called for a node that is not a child');

  this.childNodes[index] = fresh;
  fresh.parentNode = this;
  stale.parentNode = null;

  return stale;
};

Element.prototype.focus = function () {
  this.ownerDocument.activeElement = this;
};

Element.prototype.descendants = function () {
  var out = [];

  this.childNodes.forEach(function (child) {
    out.push(child);
    out = out.concat(child.descendants());
  });

  return out;
};

Element.prototype.querySelectorAll = function (selector) {
  return this.descendants().filter(function (node) {
    return matches(node, selector);
  });
};

Element.prototype.querySelector = function (selector) {
  return this.querySelectorAll(selector)[0] || null;
};

Element.prototype.closest = function (selector) {
  var node = this;

  while (node) {
    if (node.getAttribute && matches(node, selector)) return node;
    node = node.parentNode;
  }

  return null;
};

// `attributes` is iterated when a gated placeholder is cloned into a live tag.
Object.defineProperty(Element.prototype, 'attributes', {
  get: function () {
    var self = this;

    return Object.keys(this._attrs).map(function (name) {
      return { name: name, value: self._attrs[name] };
    });
  }
});

// `dataset` is read for `data-category`, and `src` decides whether a cloned
// script is external or inline.
Object.defineProperty(Element.prototype, 'dataset', {
  get: function () {
    var self = this;
    var data = {};

    Object.keys(this._attrs).forEach(function (name) {
      if (name.indexOf('data-') !== 0) return;

      var key = name.slice(5).replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); });
      data[key] = self._attrs[name];
    });

    return data;
  }
});

Object.defineProperty(Element.prototype, 'src', {
  get: function () { return this.getAttribute('src') || ''; },
  set: function (value) { this.setAttribute('src', value); }
});

// ---------------------------------------------------------------------------
// Document, storage, window
// ---------------------------------------------------------------------------

function createStorage(options) {
  var data = {};
  var broken = options && options.broken;

  return {
    getItem: function (key) {
      if (broken) throw new Error('storage unavailable');

      return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null;
    },
    setItem: function (key, value) {
      if (broken) throw new Error('storage unavailable');
      data[key] = String(value);
    },
    removeItem: function (key) {
      if (broken) throw new Error('storage unavailable');
      delete data[key];
    },
    _raw: data
  };
}

function createEnvironment(options) {
  options = options || {};

  var listeners = {};
  var cookies = {};

  var document = {
    readyState: 'complete',
    activeElement: null,
    dispatched: [],

    createElement: function (tag) { return new Element(tag, document); },

    getElementById: function (id) {
      return document.body.querySelectorAll('[id="' + id + '"]')[0] || null;
    },

    querySelectorAll: function (selector) { return document.body.querySelectorAll(selector); },
    querySelector: function (selector) { return document.body.querySelector(selector); },

    contains: function () { return true; },

    addEventListener: function (name, handler) {
      (listeners[name] = listeners[name] || []).push(handler);
    },

    removeEventListener: function (name, handler) {
      var bucket = listeners[name] || [];
      var index = bucket.indexOf(handler);

      if (index !== -1) bucket.splice(index, 1);
    },

    dispatchEvent: function (event) {
      document.dispatched.push(event);
      (listeners[event.type] || []).forEach(function (handler) { handler(event); });

      return true;
    },

    get cookie() {
      return Object.keys(cookies).map(function (name) {
        return name + '=' + cookies[name];
      }).join('; ');
    },

    set cookie(value) {
      var pair = String(value).split(';')[0];
      var eq = pair.indexOf('=');
      var name = (eq === -1 ? pair : pair.slice(0, eq)).trim();

      if (/expires=Thu, 01 Jan 1970/i.test(value)) {
        delete cookies[name];

        return;
      }

      cookies[name] = eq === -1 ? '' : pair.slice(eq + 1);
    }
  };

  document.body = new Element('body', document);

  var window = {
    localStorage: createStorage(options.localStorage),
    sessionStorage: createStorage(options.sessionStorage),
    navigator: options.navigator || {},
    location: { protocol: 'https:', href: 'https://example.test/' },
    dataLayer: undefined,
    cckConfig: options.config || {}
  };

  window.addEventListener = document.addEventListener;

  function CustomEvent(type, init) {
    this.type = type;
    this.detail = init && init.detail;
    this.bubbles = !!(init && init.bubbles);
  }

  return {
    window: window,
    document: document,
    CustomEvent: CustomEvent,
    cookies: cookies,
    setCookie: function (name, value) { cookies[name] = value; }
  };
}

module.exports = { createEnvironment: createEnvironment };
