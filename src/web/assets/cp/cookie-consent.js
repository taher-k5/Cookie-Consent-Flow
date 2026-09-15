/**
 * Cookie Consent Flow — Control Panel JavaScript.
 *
 * Handles:
 *  - Settings tab navigation
 *  - Show/hide corner-position field based on layout selection
 *  - Dynamic colour swatches next to colour inputs
 *  - Category row add / remove
 *  - Category index renumbering after remove
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    /* ------------------------------------------------------------------
       Tab navigation (Craft CP tab links anchor to #tab-* divs)
    ------------------------------------------------------------------ */
    var tabLinks  = document.querySelectorAll('#tabs a[href^="#tab-"]');
    var tabPanels = document.querySelectorAll('#cck-settings > div[id^="tab-"]');

    function showTab(targetId) {
      tabPanels.forEach(function (panel) {
        panel.classList.toggle('hidden', panel.id !== targetId.replace('#', ''));
      });
    }

    tabLinks.forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        showTab(link.getAttribute('href'));
        history.replaceState(null, '', link.getAttribute('href'));
        tabLinks.forEach(function (l) { l.classList.remove('sel'); });
        link.classList.add('sel');
      });
    });

    // Activate tab from hash on load
    if (window.location.hash && document.getElementById(window.location.hash.replace('#', ''))) {
      var activeLink = document.querySelector('#tabs a[href="' + window.location.hash + '"]');
      if (activeLink) activeLink.click();
    } else if (tabLinks.length) {
      tabLinks[0].classList.add('sel');
    }

    /* ------------------------------------------------------------------
       Corner-position field visibility
    ------------------------------------------------------------------ */
    var layoutSelect     = document.getElementById('bannerLayout');
    var cornerField      = document.getElementById('cck-corner-position-field');

    function toggleCorner() {
      if (!layoutSelect || !cornerField) return;
      cornerField.classList.toggle('hidden', layoutSelect.value !== 'corner-popup');
    }

    if (layoutSelect) {
      layoutSelect.addEventListener('change', toggleCorner);
      toggleCorner();
    }

    /* ------------------------------------------------------------------
       Button-group fields — replace native <select> with a row of
       clickable icon buttons. Each group has a hidden input holding the
       actual submitted value, kept in sync via a "change" event so any
       existing listeners on that input (e.g. corner-position toggling
       above) keep working unchanged.
    ------------------------------------------------------------------ */
    document.querySelectorAll('.cck-btn-group').forEach(function (group) {
      var hiddenInput = document.getElementById(group.getAttribute('data-input'));
      if (!hiddenInput) return;

      group.querySelectorAll('.cck-btn-option').forEach(function (btn) {
        btn.addEventListener('click', function () {
          if (btn.classList.contains('sel')) return;

          group.querySelectorAll('.cck-btn-option').forEach(function (b) {
            b.classList.remove('sel');
          });
          btn.classList.add('sel');

          hiddenInput.value = btn.getAttribute('data-value');
          hiddenInput.dispatchEvent(new Event('change'));
        });
      });
    });

    /* ------------------------------------------------------------------
       Colour swatches — adds a small coloured square inside each
       colour text-input so the admin can see the current value at a glance.
    ------------------------------------------------------------------ */
    function initColorSwatches() {
      document.querySelectorAll('.cck-color-input').forEach(function (input) {
        // Wrap in position:relative container if not already
        var wrap = input.parentElement;
        if (!wrap.classList.contains('cck-field-wrap')) {
          var newWrap = document.createElement('div');
          newWrap.className = 'cck-field-wrap';
          input.parentNode.insertBefore(newWrap, input);
          newWrap.appendChild(input);
          wrap = newWrap;
        }

        var swatch = wrap.querySelector('.cck-color-swatch');
        if (!swatch) {
          swatch = document.createElement('span');
          swatch.className = 'cck-color-swatch';
          wrap.appendChild(swatch);
        }

        function update() {
          swatch.style.background = input.value || 'transparent';
        }

        update();
        input.addEventListener('input', update);
        input.addEventListener('change', update);
      });
    }

    initColorSwatches();

    /* ------------------------------------------------------------------
       Category rows — add / remove
       Scoped per ".cck-categories-group" so the same logic drives both
       the single global category list and any number of per-site
       category lists on the Multi Site Override page.
    ------------------------------------------------------------------ */
    document.querySelectorAll('.cck-categories-group').forEach(function (group) {
      var categoriesList = group.querySelector('.cck-categories-list');
      var addBtn         = group.querySelector('.cck-add-category');
      var template       = group.querySelector('.cck-category-template');

      function reindex() {
        if (!categoriesList) return;
        categoriesList.querySelectorAll('.cck-category-row').forEach(function (row, i) {
          row.setAttribute('data-index', i);
          row.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace(/\[categories\]\[\d+\]/, '[categories][' + i + ']');
          });
        });
      }

      function bindRemove(row) {
        var removeBtn = row.querySelector('.cck-remove-category');
        if (!removeBtn) return;
        removeBtn.addEventListener('click', function () {
          row.remove();
          reindex();
        });
      }

      // Bind remove on existing rows
      if (categoriesList) {
        categoriesList.querySelectorAll('.cck-category-row').forEach(bindRemove);
      }

      // Add new row
      if (addBtn && template && categoriesList) {
        addBtn.addEventListener('click', function () {
          var count  = categoriesList.querySelectorAll('.cck-category-row').length;
          var html   = template.innerHTML.replace(/__INDEX__/g, count);
          var tmp    = document.createElement('div');
          tmp.innerHTML = html;
          var newRow = tmp.firstElementChild;
          categoriesList.appendChild(newRow);
          bindRemove(newRow);
          initColorSwatches();
          // Focus first input in new row
          var firstInput = newRow.querySelector('input[type="text"]');
          if (firstInput) firstInput.focus();
        });
      }
    });

    /* ------------------------------------------------------------------
       Cookie rows — add / remove / prefill.
       Scoped per ".cck-cookies-group", exactly like the category rows
       above, so the same logic drives both the single global list
       (Cookies page) and any number of per-site cookie lists (the
       "Cookies" card on the Multisite page).
    ------------------------------------------------------------------ */
    document.querySelectorAll('.cck-cookies-group').forEach(function (group) {
      var list       = group.querySelector('.cck-cookies-list');
      var addBtn     = group.querySelector('.cck-add-cookie');
      var template   = group.querySelector('.cck-cookie-template');
      var emptyState = list && list.querySelector('[data-cck-cookies-empty]');

      function reindex() {
        if (!list) return;
        list.querySelectorAll('.cck-cookie-row').forEach(function (row, i) {
          row.setAttribute('data-index', i);
          // The index is always the last numeric segment, right before the
          // final [field] segment — e.g. "cookies[0][name]" on the global
          // page, or "sites[2][cookies][0][name]" on the Multisite page
          // (where [2] is the site id, not the row index, and must be left
          // alone).
          row.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace(/\[\d+\](\[[a-zA-Z]+\])$/, function (match, fieldPart) {
              return '[' + i + ']' + fieldPart;
            });
          });
        });
      }

      function bindRemove(row) {
        var removeBtn = row.querySelector('.cck-remove-cookie');
        if (!removeBtn) return;
        removeBtn.addEventListener('click', function () {
          row.remove();
          reindex();
        });
      }

      if (list) {
        list.querySelectorAll('.cck-cookie-row').forEach(bindRemove);
      }

      // prefill: { categoryKey, suggestedCategory, name, provider, duration, purpose }
      // suggestedCategory is only applied if it matches one of this install's
      // actual category options — categories are fully admin-configurable, so
      // a library/detected entry can only ever hint at one.
      function addCookieRow(prefill) {
        if (!template || !list) return null;

        if (emptyState) {
          emptyState.remove();
          emptyState = null;
        }

        prefill = prefill || {};

        var count = list.querySelectorAll('.cck-cookie-row').length;
        var html  = template.innerHTML.replace(/__INDEX__/g, count);
        var tmp   = document.createElement('div');
        tmp.innerHTML = html;
        var newRow = tmp.firstElementChild;

        var categorySelect = newRow.querySelector('select[name$="[categoryKey]"]');
        var wantedCategory = prefill.categoryKey || prefill.suggestedCategory;
        if (categorySelect && wantedCategory) {
          var hasOption = Array.prototype.some.call(categorySelect.options, function (opt) {
            return opt.value === wantedCategory;
          });
          if (hasOption) categorySelect.value = wantedCategory;
        }

        ['name', 'provider', 'duration', 'purpose'].forEach(function (field) {
          if (prefill[field] === undefined) return;
          var input = newRow.querySelector('[name$="[' + field + ']"]');
          if (input) input.value = prefill[field];
        });

        list.appendChild(newRow);
        bindRemove(newRow);

        return newRow;
      }

      if (addBtn) {
        addBtn.addEventListener('click', function () {
          var newRow = addCookieRow({});
          var firstInput = newRow && newRow.querySelector('select, input[type="text"]');
          if (firstInput) firstInput.focus();
        });
      }

      // Exposed so the page-level "Add from Library" / "Detected" features
      // below (which only ever run on the standalone Cookies page, where
      // exactly one .cck-cookies-group exists) can insert rows into it.
      group.__cckAddCookieRow = addCookieRow;
    });

    /* ---- Add from Library (standalone Cookies page only) ---- */
    var cckCookiesGroup = document.querySelector('.cck-cookies-group');
    var librarySelect   = document.getElementById('cck-library-select');
    var libraryAddBtn   = document.getElementById('cck-add-from-library');
    var libraryDataEl   = document.getElementById('cck-library-data');
    var libraryById     = {};

    if (libraryDataEl) {
      try {
        (JSON.parse(libraryDataEl.textContent) || []).forEach(function (entry) {
          libraryById[entry.id] = entry;
        });
      } catch (e) { /* malformed library data — Add from Library just no-ops */ }
    }

    if (libraryAddBtn && librarySelect && cckCookiesGroup && cckCookiesGroup.__cckAddCookieRow) {
      libraryAddBtn.addEventListener('click', function () {
        var entry = libraryById[librarySelect.value];
        if (!entry) return;

        var newRow = cckCookiesGroup.__cckAddCookieRow(entry);
        if (newRow) newRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    }

    /* ---- Detected, not yet documented — Document / Dismiss (standalone Cookies page only) ---- */
    var detectedList = document.getElementById('cck-detected-list');
    if (detectedList) {
      detectedList.addEventListener('click', function (e) {
        var documentBtn = e.target.closest('.cck-document-detected');
        var dismissBtn  = e.target.closest('.cck-dismiss-detected');

        if (documentBtn) {
          var name   = documentBtn.getAttribute('data-name');
          var newRow = (cckCookiesGroup && cckCookiesGroup.__cckAddCookieRow)
            ? cckCookiesGroup.__cckAddCookieRow({ name: name })
            : null;
          if (newRow) newRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
          var item = documentBtn.closest('.cck-detected-list__item');
          if (item) item.remove();
          return;
        }

        if (dismissBtn) {
          var dismissName = dismissBtn.getAttribute('data-name');
          var listItem    = dismissBtn.closest('.cck-detected-list__item');
          dismissBtn.disabled = true;

          Craft.sendActionRequest('POST', 'cookie-consent-flow/cookies/dismiss-detected', {
            data: { name: dismissName },
          }).then(function () {
            if (listItem) listItem.remove();
          }).catch(function () {
            dismissBtn.disabled = false;
            Craft.cp.displayError('An error occurred.');
          });
        }
      });
    }

    /* ------------------------------------------------------------------
       Multi Site Override — "Use Global Setting" checkboxes.
       Each checkbox carries data-target pointing at the id of the field
       wrapper whose inputs it should disable/enable. Checked = inherit
       global value (input disabled); unchecked = editable override.

       Lightswitch fields are handled separately from every other field
       type: Craft's Craft.LightSwitch widget binds its click/keyboard
       listeners exactly once, at construction, and only if its inner
       hidden input isn't disabled at that moment — the fields are
       therefore server-rendered as never-disabled (see
       _override-field.twig) so those listeners always get bound, and this
       code only ever toggles the OUTER button's own `disabled` (which
       Craft's init never inspects) plus a CSS class for the greyed-out
       look. The inner hidden input itself is deliberately never touched
       again after render — disabling it here would silently reproduce the
       exact same dead-switch bug from our own code.
    ------------------------------------------------------------------ */
    function setOverrideFieldState(checkbox) {
      var targetId = checkbox.getAttribute('data-target');
      var target   = targetId && document.getElementById(targetId);
      if (!target) return;

      var disable = checkbox.checked;
      target.classList.toggle('cck-override-field--inherited', disable);
      target.querySelectorAll('input, select, textarea, button.cck-btn-option').forEach(function (el) {
        if (el.type === 'checkbox' && el.classList.contains('cck-use-global-checkbox')) return;
        // Never touch a lightswitch's own nested hidden input here — it's
        // handled separately below. Craft's Craft.LightSwitch widget only
        // binds its click/keyboard listeners once, at construction, and
        // only if that hidden input isn't disabled at that exact moment.
        // The field is always server-rendered with it enabled specifically
        // so listeners get bound; disabling it here (even transiently,
        // before Craft's own init runs) would silently recreate that same
        // dead-switch bug from our own code.
        if (el.closest('.lightswitch')) return;
        el.disabled = disable;
      });
      target.querySelectorAll('.lightswitch').forEach(function (ls) {
        // The outer button is what we actually lock/unlock — Craft's init
        // gate only ever inspects the inner hidden input (untouched above),
        // so toggling this button's own `disabled` is safe to do at any
        // time, in either direction, without breaking Garnish's listeners.
        ls.disabled = disable;
        ls.classList.toggle('cck-lightswitch--disabled', disable);
      });
      target.querySelectorAll('select').forEach(function (sel) {
        // Same story as the lightswitch above: selectize renders its own
        // proxy UI over the real (hidden) <select>, so setting `.disabled`
        // on that element above only affects an element nobody sees. Its
        // own enable()/disable() API is what actually locks the visible
        // widget. (Selectize's "selectize" class lands on the *wrapping*
        // div, not the <select> itself — the only reliable way to find an
        // enhanced select is to check for its attached instance data.)
        var instance = window.jQuery && window.jQuery(sel).data('selectize');
        if (instance) {
          disable ? instance.disable() : instance.enable();
        }
      });

      var row = checkbox.closest('.cck-override-row');
      if (row) {
        row.classList.toggle('cck-override-row--overridden', !disable);
        row.setAttribute('data-overridden', disable ? '0' : '1');
        // Group-level cards (e.g. Cookie Categories, whose toggle doubles as
        // its own field checkbox) own their [data-group-badge] themselves —
        // see the .cck-group-toggle handler below, which always runs after
        // this and would just be overwritten anyway.
        var badge = row.querySelector('.cck-badge:not([data-group-badge])');
        if (badge) {
          badge.classList.toggle('cck-badge--overridden', !disable);
          badge.classList.toggle('cck-badge--inherited', disable);
          badge.textContent = disable ? cckT('Inherited') : cckT('Overridden');
        }
      }
    }

    // Minimal client-side copy of the two badge strings so JS doesn't need
    // a full translation layer for this one toggle. The server-rendered
    // (translated) text is the source of truth on load; this only runs
    // after a user interaction re-labels the badge in the same request.
    function cckT(key) {
      var dict = (window.cckStrings || {});
      return dict[key] || key;
    }

    // Every hidden per-field checkbox still needs its disabled/enabled
    // state applied once on load (it reflects whatever was actually
    // persisted last time), even though only the group toggle below can
    // change it going forward.
    document.querySelectorAll('.cck-use-global-checkbox').forEach(function (checkbox) {
      setOverrideFieldState(checkbox);
    });

    /* ------------------------------------------------------------------
       Multi Site Override — card collapse/expand. Independent of the
       "Use Global Settings" switch (a card can be collapsed while
       overridden, or expanded while inherited), but the group toggle below
       drives it automatically as a convenience: switching a section to
       "override" expands it so its fields are immediately visible;
       switching back to "inherited" collapses it again, since there's
       nothing to look at once every field is disabled. Collapsing this way
       is what keeps a site with many inherited sections from turning into a
       long scroll of faded, disabled fields.

       The whole header is clickable (not just the chevron/title), except
       for the toggle/badge/label area — that has its own independent click
       behaviour (the switch) and shouldn't also collapse the card.
    ------------------------------------------------------------------ */
    function setCardCollapsed(card, collapsed) {
      if (!card) return;
      card.setAttribute('data-collapsed', collapsed ? '1' : '0');
      var btn = card.querySelector('.cck-override-card__disclosure');
      if (btn) btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    document.querySelectorAll('.cck-override-card__header').forEach(function (header) {
      header.addEventListener('click', function (e) {
        if (e.target.closest('.cck-override-card__toggle')) return;

        var card = header.closest('[data-group-card]');
        if (!card) return;
        setCardCollapsed(card, card.getAttribute('data-collapsed') !== '1');
      });
    });

    /* ------------------------------------------------------------------
       Multi Site Override — group-level "Use Global Settings" toggle.
       Replaces per-field checkboxes: one visible switch per settings card
       drives every hidden .cck-use-global-checkbox inside that card's body
       (data-group-target), reusing setOverrideFieldState() per field so the
       actual disable/enable + row badge logic isn't duplicated. Also updates
       the card's own header badge, fade state, and collapse state.
    ------------------------------------------------------------------ */
    document.querySelectorAll('.cck-group-toggle').forEach(function (groupToggle) {
      groupToggle.addEventListener('change', function () {
        var targetId = groupToggle.getAttribute('data-group-target');
        var target   = targetId && document.getElementById(targetId);
        var card     = groupToggle.closest('[data-group-card]');
        var inherited = groupToggle.checked;

        if (target) {
          target.querySelectorAll('.cck-use-global-checkbox').forEach(function (checkbox) {
            if (checkbox === groupToggle) return;
            checkbox.checked = inherited;
            setOverrideFieldState(checkbox);
          });

          // Categories block: the group toggle IS the field checkbox (only
          // one field in that "group"), so drive its target directly too.
          if (target.id === groupToggle.getAttribute('data-target')) {
            setOverrideFieldState(groupToggle);
          }
        }

        if (card) {
          card.classList.toggle('cck-override-card--inherited', inherited);
          card.setAttribute('data-overridden', inherited ? '0' : '1');
          setCardCollapsed(card, inherited);

          var badge = card.querySelector('[data-group-badge]');
          if (badge) {
            // A manual toggle always resolves to fully inherited or fully
            // overridden — clear any leftover "partial" state (only possible
            // pre-refactor data could have started in) along with its
            // explanatory tooltip, which no longer applies.
            badge.classList.remove('cck-badge--partial');
            badge.removeAttribute('title');
            badge.classList.toggle('cck-badge--inherited', inherited);
            badge.classList.toggle('cck-badge--overridden', !inherited);
            badge.textContent = inherited ? cckT('Inherited') : cckT('Site Override');
          }
        }
      });
    });

    /* ------------------------------------------------------------------
       Multi Site Override — search and per-panel empty states. Pure
       client-side: rows are queried once and cached, filtering just
       toggles a class on each cached row.
    ------------------------------------------------------------------ */
    // Cache each panel's rows once so filtering never re-queries the DOM.
    var panelRowCache = Array.prototype.slice.call(document.querySelectorAll('.cck-site-panel')).map(function (panel) {
      return {
        panel: panel,
        rows: Array.prototype.slice.call(panel.querySelectorAll('.cck-override-row')),
      };
    });

    // Same idea, but keyed by card, so an active search/filter can force a
    // collapsed card open when one of its fields matches (and restore its
    // prior collapsed state once the filter is cleared) — otherwise a
    // matching field could be sitting inside a card that's still collapsed.
    var cardRowCache = Array.prototype.slice.call(document.querySelectorAll('[data-group-card]')).map(function (card) {
      return {
        card: card,
        rows: card.matches('.cck-override-row')
          ? [card]
          : Array.prototype.slice.call(card.querySelectorAll('.cck-override-row')),
      };
    });

    if (panelRowCache.length) {
      var searchInput = document.getElementById('cck-override-search');

      function applyFilters() {
        var query        = (searchInput && searchInput.value || '').trim().toLowerCase();
        var filtersActive = !!query;

        panelRowCache.forEach(function (entry) {
          var panel         = entry.panel;
          var visibleCount  = 0;
          var overriddenCount = 0;

          entry.rows.forEach(function (row) {
            var visible = !query || (row.getAttribute('data-search') || '').indexOf(query) !== -1;

            row.classList.toggle('hidden', !visible);
            if (visible) visibleCount++;
            if (row.getAttribute('data-overridden') === '1') overriddenCount++;
          });

          var defaultEmpty  = panel.querySelector('[data-default-empty-state]');
          var filteredEmpty = panel.querySelector('.cck-empty-state--filtered');

          if (defaultEmpty) {
            defaultEmpty.classList.toggle('hidden', !(overriddenCount === 0 && !query));
          }
          if (filteredEmpty) {
            filteredEmpty.classList.toggle('hidden', !(query && visibleCount === 0));
          }

          // Remember the panel's open/closed state from just before
          // filtering started, so clearing the filter restores it exactly
          // (rather than leaving every matched panel forced open).
          if (filtersActive) {
            if (panel.dataset.cckPrevOpen === undefined) {
              panel.dataset.cckPrevOpen = panel.open ? '1' : '0';
            }
            if (visibleCount > 0) panel.open = true;
          } else if (panel.dataset.cckPrevOpen !== undefined) {
            panel.open = panel.dataset.cckPrevOpen === '1';
            delete panel.dataset.cckPrevOpen;
          }
        });

        // Runs after every row's hidden/visible state above is settled, so
        // "does this card have a visible row" is accurate.
        cardRowCache.forEach(function (entry) {
          var card = entry.card;

          if (filtersActive) {
            if (card.dataset.cckPrevCollapsed === undefined) {
              card.dataset.cckPrevCollapsed = card.getAttribute('data-collapsed') || '0';
            }
            var anyVisible = entry.rows.some(function (row) {
              return !row.classList.contains('hidden');
            });
            setCardCollapsed(card, !anyVisible);
          } else if (card.dataset.cckPrevCollapsed !== undefined) {
            setCardCollapsed(card, card.dataset.cckPrevCollapsed === '1');
            delete card.dataset.cckPrevCollapsed;
          }
        });
      }

      if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
        searchInput.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            searchInput.value = '';
            applyFilters();
            searchInput.blur();
          }
        });
      }

      applyFilters();
    }

    /* ------------------------------------------------------------------
       Multi Site Override — "Expand All Sections" / "Collapse All Sections"
       per site panel. Bulk-applies setCardCollapsed() to every group card in
       that panel; label flips to whichever action makes sense next (if any
       card is currently collapsed, the button expands everything, otherwise
       it collapses everything).
    ------------------------------------------------------------------ */
    document.querySelectorAll('.cck-expand-all').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var siteId = btn.getAttribute('data-expand-all');
        var panel  = document.querySelector('.cck-site-panel[data-site-id="' + siteId + '"]');
        if (!panel) return;

        var cards = panel.querySelectorAll('[data-group-card]');
        var anyCollapsed = Array.prototype.some.call(cards, function (card) {
          return card.getAttribute('data-collapsed') === '1';
        });

        cards.forEach(function (card) {
          setCardCollapsed(card, !anyCollapsed);
        });

        btn.textContent = anyCollapsed ? cckT('Collapse All Sections') : cckT('Expand All Sections');
      });
    });

    /* ------------------------------------------------------------------
       Multi Site Override — remember which accordion panels were expanded
       across page loads via localStorage.
    ------------------------------------------------------------------ */
    (function () {
      var STORAGE_KEY = 'cckSiteOverridesAccordion';
      var panels = document.querySelectorAll('.cck-site-panel[data-site-id]');
      if (!panels.length) return;

      var stored = {};
      try {
        stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}');
      } catch (e) {
        stored = {};
      }

      panels.forEach(function (panel) {
        var siteId = panel.getAttribute('data-site-id');
        if (Object.prototype.hasOwnProperty.call(stored, siteId)) {
          panel.open = !!stored[siteId];
        }

        panel.addEventListener('toggle', function () {
          stored[siteId] = panel.open;
          try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(stored));
          } catch (e) { /* storage unavailable — accordion still works, just isn't remembered */ }
        });
      });
    }());

    /* ------------------------------------------------------------------
       Multi Site Override — remember each card's collapsed/expanded state
       across page loads via localStorage, same pattern as the accordion
       above but keyed per site+card instead of per site panel.

       A MutationObserver on each card's own `data-collapsed` attribute (set
       by every one of setCardCollapsed()'s callers — header click, the
       group toggle's auto-collapse/expand, "Expand All Sections") is used
       instead of hooking each call site individually, so a future caller of
       setCardCollapsed() gets persistence for free without having to
       remember to wire it up.
    ------------------------------------------------------------------ */
    (function () {
      var STORAGE_KEY = 'cckSiteOverridesCardCollapse';
      var cards = document.querySelectorAll('[data-group-card][data-card-key]');
      if (!cards.length || typeof MutationObserver === 'undefined') return;

      var stored = {};
      try {
        stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}');
      } catch (e) {
        stored = {};
      }

      function keyFor(card) {
        var panel  = card.closest('.cck-site-panel[data-site-id]');
        var siteId = panel ? panel.getAttribute('data-site-id') : '0';
        return siteId + ':' + card.getAttribute('data-card-key');
      }

      function persist() {
        try {
          window.localStorage.setItem(STORAGE_KEY, JSON.stringify(stored));
        } catch (e) { /* storage unavailable — collapse still works, just isn't remembered */ }
      }

      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
          var card = mutation.target;
          stored[keyFor(card)] = card.getAttribute('data-collapsed') === '1';
        });
        persist();
      });

      cards.forEach(function (card) {
        var key = keyFor(card);
        if (Object.prototype.hasOwnProperty.call(stored, key)) {
          setCardCollapsed(card, stored[key]);
        }
        observer.observe(card, {attributes: true, attributeFilter: ['data-collapsed']});
      });
    }());

    /* ------------------------------------------------------------------
       Multi Site Override — "Copy Global Settings" (client-side only: populates
       each field's current value from its stored global value without
       touching the "Use Global Setting" checkbox).
    ------------------------------------------------------------------ */
    document.querySelectorAll('.cck-copy-global').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var siteId = btn.getAttribute('data-copy-global');
        var panel  = document.querySelector('.cck-site-panel[data-site-id="' + siteId + '"]');
        if (!panel) return;

        panel.querySelectorAll('.cck-override-row[data-global-value]').forEach(function (row) {
          var globalValue = row.getAttribute('data-global-value');
          var type        = row.getAttribute('data-field-type');

          if (type === 'asset') {
            // Craft's element-select widget keeps its own JS-side state
            // (selected elements, thumbnail markup) that can't be faked by
            // writing to a plain input — quietly skip it. The "Use Global
            // Setting" toggle (not this quick-fill button) is still the way
            // to actually revert this field to the global logo.
            return;
          } else if (type === 'lightswitch') {
            var lightswitchBtn = row.querySelector('.lightswitch');
            var hidden          = row.querySelector('input[type="hidden"]');
            var on               = globalValue === '1';
            if (lightswitchBtn) {
              lightswitchBtn.classList.toggle('on', on);
              lightswitchBtn.setAttribute('aria-checked', on ? 'true' : 'false');
            }
            if (hidden) hidden.value = on ? (hidden.getAttribute('value') || '1') : '';
          } else if (type === 'multiselect') {
            // Selectize wraps the real <select> and keeps its own copy of
            // the selection — writing straight to the <select> wouldn't be
            // reflected in the UI, so go through the selectize instance.
            var select    = row.querySelector('select');
            var selectize = select && window.jQuery && window.jQuery(select).data('selectize');
            var codes     = globalValue ? globalValue.split(',').filter(Boolean) : [];
            if (selectize) {
              selectize.setValue(codes, false);
            } else if (select) {
              Array.prototype.forEach.call(select.options, function (opt) {
                opt.selected = codes.indexOf(opt.value) !== -1;
              });
              select.dispatchEvent(new Event('change', {bubbles: true}));
            }
          } else {
            var input = row.querySelector('input:not([type="hidden"]):not([type="checkbox"]), select, textarea');
            if (!input) return;
            // Craft's native color field stores the hex value without its
            // leading '#' in the text input (the '#' is a separate static
            // prefix in the markup).
            input.value = (type === 'color') ? globalValue.replace(/^#/, '') : globalValue;
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
          }
        });
      });
    });

    /* ------------------------------------------------------------------
       Multi Site Override — "Reset Multi Site Override" / "Copy From ▼ … Copy",
       both persisted immediately via Craft's own AJAX helper (the whole
       page is already one big <form>, so these can't be their own nested
       forms). A shared confirm() guard keeps the two handlers DRY.
    ------------------------------------------------------------------ */
    function cckConfirmAndSend(message, action, data) {
      if (!window.confirm(message)) return;

      Craft.sendActionRequest('POST', action, {data: data})
        .then(function (response) {
          Craft.cp.displaySuccess((response.data && response.data.message) || 'Done.');
          window.location.reload();
        })
        .catch(function (error) {
          var msg = (error.response && error.response.data && error.response.data.message) || 'An error occurred.';
          Craft.cp.displayError(msg);
        });
    }

    document.querySelectorAll('.cck-reset-site').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var siteId = btn.getAttribute('data-reset-site');
        cckConfirmAndSend(
          'Remove every override for this site and return it to Global Settings?',
          'cookie-consent-flow/settings/reset-multi-site-override',
          {siteId: siteId}
        );
      });
    });

    document.querySelectorAll('.cck-copy-into').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var toSiteId  = btn.getAttribute('data-copy-into');
        var select    = document.querySelector('.cck-copy-from-select[data-site="' + toSiteId + '"]');
        var fromSiteId = select && select.value;
        if (!fromSiteId) return;

        cckConfirmAndSend(
          'Replace this site’s overrides with a copy of the selected site’s? This cannot be undone.',
          'cookie-consent-flow/settings/copy-multi-site-override',
          {fromSiteId: fromSiteId, toSiteId: toSiteId}
        );
      });
    });

    /* ------------------------------------------------------------------
       Dashboard live preview — Desktop / Tablet / Mobile switcher
    ------------------------------------------------------------------ */
    var previewFrame   = document.querySelector('.cck-preview-frame');
    var deviceButtons  = document.querySelectorAll('.cck-preview-device-btn');

    if (previewFrame && deviceButtons.length) {
      deviceButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var device = btn.getAttribute('data-device');

          previewFrame.classList.remove('cck-preview--tablet', 'cck-preview--mobile');
          if (device === 'tablet' || device === 'mobile') {
            previewFrame.classList.add('cck-preview--' + device);
          }

          deviceButtons.forEach(function (b) { b.classList.toggle('sel', b === btn); });
        });
      });
    }

  });

}());

