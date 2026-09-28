# Cookie Consent Flow — developer notes

This file is the short internal reference. User setup and API examples belong
in `README.md`; release notes belong in `CHANGELOG.md`.

## Architecture

- `Plugin.php` declares its services in `config()` (so `pluginConfigs`
  overrides such as custom geo providers apply), and registers CP
  routes/navigation/permissions, Twig, the dashboard widget, Consent Mode head
  injection and banner body injection.
- `SettingsService` owns relational global settings and sparse site overrides.
  A site-column `NULL` means inherit. Categories and cookie definitions are
  child rows keyed by the settings row.
- `ConsentService` validates persistence, reads/filter records, aggregates raw
  counts, and purges retention data. `StatisticsService` caches CP aggregates
  and invalidates them after writes/deletes.
- `GeoService` uses ordered `GeoProviderInterface` providers and fails open:
  unknown country means show the banner. `HeaderGeoProvider` trusts no header
  until one is configured (`geoCountryHeader`).
- `helpers/PluginConfig` reads `config/cookie-consent-flow.php` — deployment
  facts only (`geoCountryHeader`, `automaticRetention`, `retentionBatchSize`);
  everything editors manage is in the database.
- `helpers/Throttle` rate-limits the anonymous endpoints through `check()`: one
  bucket per visitor with a real `trustedHosts`, otherwise per claimed client
  within a per-peer cap. Only `X-Forwarded-For` is read, never pass-through
  headers such as `Client-IP`. It fails open on cache failure and logs an
  error. `Throttle::resolveIp()` is also the address the IP hash comes from.
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
9. The response hook touches only HTML responses (`Plugin::isInjectableResponse()`),
   and a page gets the runtime at most once, however it was included.
10. Never trust a client-supplied header without deployment configuration:
    forwarded IPs only from real `trustedHosts` ranges (Craft's `['any']`
    counts as none) and only via `X-Forwarded-For`, country headers only when
    named in `geoCountryHeader`. Anchor allow-list regexes with `$/D`.
11. `EVENT_AFTER_CONSENT_SAVE` fires only after the record is committed; nothing
    after the commit may turn a recorded decision into a failed response.
12. Per-site CP data (records, exports, dashboard, overrides) follows Craft's
    `editSite:<uid>` permissions via `Permissions::accessibleSites()`.
13. The `<head>` Consent Mode replay must validate stored state exactly as
    `getConsent()` does (`ConsentModeReplayTest` executes it).

## Storage

- `cookieconsent_settings`: global row (`siteId=0`) plus sparse site rows.
- `cookieconsent_category`: per-settings-row categories and Consent Mode maps.
- `cookieconsent_cookie`: visitor-facing cookie disclosure.
- `cookieconsent_detected_cookie`: observed cookie names only, never values.
- `cookieconsent_log`: consent evidence and metadata.

`Install.php` is the idempotent schema definition and, during the build phase,
the **single canonical migration**: `Install::reconcile()` creates the complete
schema on a fresh install and converges an existing table rather than
assuming an empty one. There are no incremental migrations; a development
install picks up schema changes by reinstalling the plugin.

The schema version is **1.8.0** because development builds were installed at
1.6.0 (`main`) and 1.7.0, and it must never go down — Craft refuses to apply
project config recording a higher version.

**This convention ends with the first published release.** From the next
schema change after it: update `Install.php`, add a timestamped migration that
calls `reconcile()`, and bump `Plugin::$schemaVersion`, in the same change,
because uninstall/reinstall would then destroy real consent evidence.

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
is where the runtime's silent failures are pinned down.

Against a disposable Craft install on each supported driver (never a real
site — they change settings and create users):

```bash
php tests/integration/run.php /path/to/project            # database checks
php tests/integration/http.php http://host /path/to/project
php tests/integration/cp.php http://host /path/to/project admin password
node tests/browser/run.mjs http://host /path/to/project [admin password]  # headless Chrome; CP checks need the admin
```

`http.php`, `cp.php` and `run.mjs` need the fixture templates from
`tests/integration/templates/`, a favicon in the web root (Craft's 404 page
sets cookies), a second site, and Craft Pro for the restricted-user check.

There is no static analysis. `craftcms/ecs` resolves a Craft 4-era toolchain
whose config API no longer matches and which is incompatible with current PHP,
and `craftcms/phpstan` ships no binary — so both were removed rather than left
as commands that cannot run. Adding working static analysis is open work; if
you do, add the config files in the same change.

## Release checklist

- Run PHP syntax, JavaScript syntax, `composer check-all`, and `git diff --check`.
- Run the integration, HTTP, CP and browser suites on MySQL and PostgreSQL.
- Test fresh install, uninstall and reinstall on both drivers.
- Exercise every CP save/reset/copy/filter/export flow.
- Test accept, reject, custom, GPC, DNT, API, expiry, and policy invalidation.
- Verify rejected categories make no optional network requests.
- Repeat with static page caching, multiple sites, mobile keyboard navigation,
  and the production geo proxy/CDN.
- Keep README, changelog, and schema version synchronized.
