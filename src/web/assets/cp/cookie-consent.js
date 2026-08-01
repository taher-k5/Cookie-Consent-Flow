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
    ------------------------------------------------------------------ */
    var categoriesList = document.getElementById('cck-categories-list');
    var addBtn         = document.getElementById('cck-add-category');
    var template       = document.getElementById('cck-category-template');

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

