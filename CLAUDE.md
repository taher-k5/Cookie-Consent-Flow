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
- `helpers/ConsentScope` decides which sites share a decision: the site ID
  by default, `g<groupId>` under the global `consentScope = group`, or a key
  set through `EVENT_RESOLVE_CONSENT_SCOPE`. The browser storage key, the
  visitor cookie and the `<head>` replay all use the scope key; records keep
  the real site ID. Resolution failures fall back to the per-site scope.
- Data subject requests: `ConsentHelper::normalizeVisitorId()` is the one
  gate for every visitor ID from outside (records search, deletion, the
  browser-made ID). With `showConsentId` on, the runtime makes an ID in memory
  before the first decision and posts it; `ConsentService::acceptsRequestedVisitorId()`
  takes it only if no record has it, or this browser claimed it (a cached
  HMAC of its CSRF cookie, else session, from its own earlier save: a retry
  whose response was lost). Never by network: a CDN or carrier gives many
  visitors one IP hash.
  The runtime keeps it in `cck_cid_<scope>` from the first page view (as
  OneTrust and Usercentrics do); it is still only a claim the server checks. `consent/id`
  and the save response expose the ID only while the setting is on. Deletion (`DELETE_LOGS`, `records/delete`) is limited to accessible
  sites and logged.
- User linking (`UserLinkService`, `linkUsers` off by default): the user
  comes only from Craft's session (never while impersonating). A logged-in
  decision sets `cookieconsent_log.userId` (FK `SET NULL`); visitor IDs from
  the validated `cck_visitor_*` cookies are linked in
  `cookieconsent_user_visitor` (FK `CASCADE`) at login, at a guest's own
  front-end registration, and at each logged-in decision. A user's records = `userId` match, plus `userId IS NULL` records
  of linked IDs; IDs linked to other users too are "shared" and never
  deleted in bulk. Deleting a user (trash included) anonymises or deletes
  their records at once (`userDeleteAction`); the FKs only back that up. At login and registration, each linked ID's latest
  anonymous record per site, if still valid, gets the user in place
  (`attachToAccount()`); records are otherwise never updated, so a later
  `dateUpdated` marks it "Linked at login" (`wasAttached()`). User data in
  the CP needs `editUsers` or admin.
- `helpers/PluginConfig` reads `config/cookie-consent-flow.php` — deployment
  facts only (`geoCountryHeader`, `automaticRetention`, `retentionBatchSize`);
  everything editors manage is in the database.
- `helpers/Throttle` rate-limits the anonymous endpoints through `check()`: one
  bucket per visitor with a real `trustedHosts`, otherwise per claimed client
  within a per-peer cap. Only `X-Forwarded-For` is read, never pass-through
  headers such as `Client-IP`. It fails open on cache failure and logs an
  error. `Throttle::resolveIp()` is also the address the IP hash comes from.
- `helpers/Onboarding` drives the CP welcome tour (`web/assets/onboarding/`).
  It is decided per page view, not at install (Craft installs plugins by
  several paths, most with no browser), and "seen" is the Craft user
  preference `cookieConsentFlowTourSeen`, so each user sees it once. Steps
  follow `Plugin::subnavFor()`, so every subnav item needs tour copy.
- `cookie-banner.js` owns browser state, expiry/policy validation, gating,
  privacy signals, Consent Mode updates, cached-page CSRF, geo resolution, and
  best-effort record/cookie-name sync.

## Invariants

1. Injected HTML must be identical for every visitor. Never render a CSRF
   token, country decision, visitor ID, or stored decision into cacheable HTML.
2. Optional code runs only after its configured category is accepted. Locked
   categories are always added on both client and server.
3. Unknown/stale categories are removed from stored and posted decisions.
4. Settings, records, and overrides remain site-aware. Storage keys and
   visitor cookies are scope-aware (`ConsentScope`); the default scope is the
   site, under the exact names used before scopes existed. In a shared scope
   a decision that was not asked about one of the site's optional categories
   is not a decision there (`offered` holds the optional keys asked about,
   merged across the scope's sites; "reject all" withdraws every one), a
   queued sync is sent only from the site it was made on, and decisions under
   the other setting's key (`staleScopeKeys`) are removed.
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

`Install.php` is the idempotent schema definition: `Install::reconcile()`
creates the complete schema on a fresh install and converges an existing table
rather than assuming an empty one.

1.0.0 is published (schema 1.8.0), so every schema change now does three
things in the same change, because uninstall/reinstall would destroy real
consent evidence: update `Install.php`, add a timestamped migration
(`mYYMMDD_HHMMSS_name.php`) whose `safeUp()` calls `reconcile()`, and bump
`Plugin::$schemaVersion`. `SchemaConsistencyTest` checks the migrations
converge on `Install.php`. The schema version must never go down — Craft
refuses to apply project config recording a higher version.

- 1.12.0 (release 1.0.5) — consent scope, consent ID, user linking
  (`m261016_300000_consent_scope_id_users`). One migration per release. While
  a release is unpublished, add its new schema to `Install.php` and give its
  single migration a **new timestamp** (plus a schema bump): Craft never runs
  an applied migration again, so a bump alone would leave development
  installs without the new columns.

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
php craft cookie-consent-flow/records/delete --visitor=<id> --dry-run=1
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
