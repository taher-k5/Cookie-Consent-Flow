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

`Install.php` is the idempotent schema definition and, during this rebuild
phase, the **single canonical migration**. A schema change is made there, so a
fresh install creates the complete current schema; do not add timestamped
incremental migrations or a historical migration chain. There is no supported
upgrade path from a pre-rebuild database — reinstall instead.

Once the rebuild is released and real installations carry consent evidence,
this convention has to change: from that point a schema change also needs a
timestamped incremental migration, because requiring uninstall/reinstall to
upgrade would destroy that evidence.

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
composer test
composer check-cs
composer phpstan
php craft cookie-consent-flow/retention/clear --dry-run=1
```

The unit bootstrap uses the nearest Craft Composer autoloader and a temporary
Yii runtime. Database/controller/migration behavior needs the real Craft test
site and both supported database drivers before release.

## Release checklist

- Run PHP syntax, JavaScript syntax, PHPUnit, ECS, PHPStan, and `git diff --check`.
- Test fresh install and previous-version upgrade on MySQL and PostgreSQL.
- Exercise every CP save/reset/copy/filter/export flow.
- Test accept, reject, custom, GPC, DNT, API, expiry, and policy invalidation.
- Verify rejected categories make no optional network requests.
- Repeat with static page caching, multiple sites, mobile keyboard navigation,
  and the production geo proxy/CDN.
- Keep README, changelog, schema version, and audit result synchronized.
