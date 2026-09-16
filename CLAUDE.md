# Cookie Consent Flow — developer notes

This file is the short internal reference. User setup and API examples belong
in `README.md`; audit evidence and remaining release checks belong in
`AUDIT_REPORT.md`.

## Architecture

- `Plugin.php` registers services, CP routes/navigation/permissions, Twig,
  dashboard widget, Consent Mode head injection, and banner body injection.
- `SettingsService` owns relational global settings and sparse site overrides.
  A site-column `NULL` means inherit. Categories and cookie definitions are
  child rows keyed by the settings row.
- `ConsentService` validates persistence, reads/filter records, aggregates raw
  counts, and purges retention data. `StatisticsService` caches CP aggregates
  and invalidates them after writes/deletes.
- `GeoService` uses ordered `GeoProviderInterface` providers and fails open:
  unknown country means show the banner.
- `cookie-banner.js` owns browser state, expiry/policy validation, gating,
  privacy signals, Consent Mode updates, cached-page CSRF, geo resolution, and
  best-effort record/cookie-name sync.

## Invariants

1. Injected HTML must be identical for every visitor. Never render a CSRF
   token, country decision, visitor ID, or stored decision into cacheable HTML.
2. Optional code runs only after its configured category is accepted. Locked
   categories are always added on both client and server.
3. Unknown/stale categories are removed from stored and posted decisions.
4. Settings, storage keys, visitor cookies, records, and overrides remain
   site-aware.
5. Anonymous writes remain narrow, CSRF-protected, allow-listed, capped, and
   throttled. Error details go to Craft logs, not JSON responses.
6. Settings/category/cookie replacement is transactional. Never delete child
   rows without a rollback path.
7. Anything emitted through `|raw` must already be safely generated or
   purified. CSS values and URL schemes stay allow-listed.
8. Geo/provider/cache failures show the banner and must not take down the page.

## Storage

- `cookieconsent_settings`: global row (`siteId=0`) plus sparse site rows.
- `cookieconsent_category`: per-settings-row categories and Consent Mode maps.
- `cookieconsent_cookie`: visitor-facing cookie disclosure.
- `cookieconsent_detected_cookie`: observed cookie names only, never values.
- `cookieconsent_log`: consent evidence and metadata.

`Install.php` is the idempotent schema definition and the **single canonical
migration** for 1.0.0. It creates the complete schema on a fresh install, and
its helpers reconcile an existing table rather than assuming an empty one.

**This convention ends with 1.0.0.** Nothing was published before it, so there
was no installation to upgrade and no consent evidence to protect. Once 1.0.0
is out that stops being true: from the next schema change onwards, update
`Install.php` **and** add a timestamped incremental migration, because
requiring uninstall/reinstall to upgrade would destroy the consent evidence
real installations now hold.

## Front-end contract

Use inert placeholders:

```html
<script type="text/plain" data-cck-category="analytics"
        data-cck-src="https://example.com/script.js"></script>
<iframe data-cck-category="marketing"
        data-cck-src="https://example.com/embed"></iframe>
```

The public object is `window.CookieConsent`; public DOM events use the
`cookieConsent:` prefix. Withdrawal cannot unload already-executed JavaScript;
it affects future loading and the next navigation.

## Commands

```bash
composer test       # PHPUnit
composer test-js    # front-end runtime harness (node, no dependencies)
composer check-all  # both
php craft cookie-consent-flow/retention/clear --dry-run=1
```

The unit bootstrap uses the nearest Craft Composer autoloader and a temporary
Yii runtime. `tests/js/` runs `cookie-banner.js` against a stub browser, which
is where the runtime's silent failures are pinned down. Database, controller
and migration behavior still needs the real Craft test site on both supported
drivers.

There is no static analysis. `craftcms/ecs` resolves a Craft 4-era toolchain
whose config API no longer matches and which is incompatible with current PHP,
and `craftcms/phpstan` ships no binary — so both were removed rather than left
as commands that cannot run. Adding working static analysis is open work; if
you do, add the config files in the same change.

## Release checklist

- Run PHP syntax, JavaScript syntax, `composer check-all`, and `git diff --check`.
- Test fresh install on MySQL and PostgreSQL.
- Exercise every CP save/reset/copy/filter/export flow.
- Test accept, reject, custom, GPC, DNT, API, expiry, and policy invalidation.
- Verify rejected categories make no optional network requests.
- Repeat with static page caching, multiple sites, mobile keyboard navigation,
  and the production geo proxy/CDN.
- Keep README, changelog, schema version, and audit result synchronized.
