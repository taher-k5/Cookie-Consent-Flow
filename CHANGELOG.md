# Cookie Consent Flow Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this
project adheres to [Semantic Versioning](https://semver.org/).

> **Rebuild phase.** This plugin is being rebuilt, and ships a single canonical
> schema migration (`src/migrations/Install.php`) rather than a chain of
> incremental ones. A fresh install creates the complete current schema. There
> is deliberately no upgrade path from a pre-rebuild database: such a database
> must be reinstalled, not migrated. `Plugin::$schemaVersion` identifies that
> canonical schema and is independent of the package version below.

## Unreleased

Canonical schema 1.7.0, created in full by `Install.php` on install.

### Added

- **Google Consent Mode v2.** All seven signals (`ad_storage`, `ad_user_data`,
  `ad_personalization`, `analytics_storage`, `functionality_storage`,
  `personalization_storage`, `security_storage`), mapped per category rather
  than hard-coded. Advanced and basic implementations, a conservative
  denied-by-default state, `wait_for_update`, URL passthrough and ads data
  redaction. Auto-injected into `<head>` or placed manually with
  `craft.cookieConsent.consentModeScript()`. Also pushes a
  `cookie_consent_update` data-layer event for Tag Manager.
- **Retention console command** — `cookie-consent-flow/retention/clear`, with
  `--days`, `--site`, `--dry-run` and `--force`. The plugin had always pointed
  its console controller namespace at a directory that did not exist, so the
  documented cleanup had no way to run.
- **Consent record export** to CSV and JSON, covering exactly the filtered set
  shown on screen, behind its own permission.
- **Consent record filtering** by source, category, country, policy version and
  date range, alongside the existing site and outcome filters.
- **Invalidate Existing Consent** — a control-panel action that sets a new
  policy version so every visitor is asked again. Records are kept.
- **Global Privacy Control** support (on by default) and **Do Not Track**
  (available, off by default).
- **Geo provider interface** — `GeoProviderInterface` with a default
  `HeaderGeoProvider` reading CDN/proxy headers, so a real GeoIP lookup can be
  added without the plugin depending on any commercial service.
- **Permission-gated export** — downloading consent records is separate from
  viewing them. Configuration remains one permission because it is one form and
  controller boundary.
- **Twig cookie API** — `cookieTable()` renders an accessible, overridable
  disclosure table; `cookies()` and `cookiesByCategory()` expose the data.
- **JavaScript API** — `hasConsent(category)`, `getConsentState()`,
  `updateConsent()`, `closePreferences()`, and documented `cookieConsent:*`
  events. `window.CookieConsent` is the documented name.
- **Dashboard statistics** — outcome totals, per-category acceptance rates and
  a daily trend, through a cached aggregation layer.
- **`source` on consent records** — whether a decision came from the banner, a
  privacy signal, or the JavaScript API.
- **Rate limiting** on the anonymous consent and cookie-reporting endpoints.
- **Unit test suite** — 52 tests covering consent-signal mapping, geo
  resolution, header validation, settings and override merging, HTML
  sanitisation, and retention arithmetic.
- **Documentation** — `README.md` rewritten as a single self-contained
  reference covering Consent Mode, caching, the Twig/JavaScript/PHP APIs,
  multisite, geo, cookie detection, consent records, retention, permissions,
  accessibility, testing, the development-phase schema convention,
  troubleshooting and compliance notes.

### Fixed

- `Install.php` now creates the complete current schema on a fresh install —
  the `source` log column, the Consent Mode and privacy-signal settings
  columns, per-category Consent Mode signal storage, and the query indexes.
- Settings/category/site-copy writes are transactional, so a failed child-row
  insert preserves the previous configuration.
- Cookie/category rows validate required fields and database lengths; malformed
  CP input now returns a normal save error instead of an uncaught exception.
- Inline CSS values and privacy-policy URL schemes are allow-listed before
  front-end output.
- Consent Record cards and the new outcome chart follow the active filters.
- Manual Twig banner placement no longer receives a second auto-injected copy
  at the end of the response.

- **Consent saving failed on statically cached pages.** The CSRF token was
  rendered into the markup, so it went stale the moment the page was cached and
  was then replayed to every later visitor. No token is embedded now; the
  runtime fetches one when it first needs to save and refetches once if a token
  is rejected.
- **Geo-targeting baked one visitor's country into cached HTML.** The decision
  was made while rendering, so whichever visitor populated the cache decided
  what everyone after them saw. It is now resolved per visitor against an
  explicitly uncacheable endpoint.
- **The preference centre leaked a keydown listener on every open**, because the
  focus trap was only released on the next `Tab` press after being hidden. One
  listener is now kept and released deterministically on close.
- **The center-popup overlay was never shown or hidden**, having been rendered
  with a `hidden` attribute that nothing removed.
- **Client-side consent state was trusted verbatim.** A category deleted by an
  admin kept granting access, and a locked category absent from a stored value
  stayed absent. Stored decisions are now validated and normalised on read.
- **Malformed browser storage caused a parse failure on every page load.** The
  value is now cleared once and treated as "no decision".
- **A failed consent sync was lost silently.** It is now queued and retried.
- **A settings-table failure could take down every front-end page**, because
  the injection hook did not guard settings loading.
- **A NULL column on the global settings row read as `''`, `false` or `null`**
  instead of the setting's documented default — so any column added by a later
  schema version appeared as though the admin had set it to empty.
- **Every consent save returned a 500.** `StatisticsService::invalidate()`
  called `$cache->invalidateTags()`, which does not exist on Craft's cache —
  tag invalidation is a static `TagDependency` helper. Because
  `ConsentService::saveConsent()` calls it after every write, the record was
  stored but the endpoint still failed, so the front-end runtime queued and
  retried the save indefinitely. Found by running the plugin, not by reading it.
- **A rejected CSRF token produced a 404 with no usable body.** Craft's
  automatic check throws inside `beforeAction()`, where a controller cannot
  answer; the anonymous endpoints now own the check and return
  `400 {"error":"invalid_csrf"}`, which is what lets the runtime fetch a fresh
  token and retry. Previously the caller also received a full stack trace while
  devMode was on.
- **`ConsentService::saveConsent()` could not run outside a web request.** It
  called `getCookies()` and `getRemoteIP()` unconditionally, which throw on a
  console request — so recording consent from a queue job or a command was
  impossible.

### Changed

- The dashboard, widget and records page now read from a cached aggregation
  layer, tag-invalidated on write, instead of recomputing on every render.
- `ConsentService::getStats()` is one grouped query rather than four COUNTs.
- `ConsentService::getLogs()` takes a filter array; the previous string form is
  still accepted.
- `craft.cookieConsent.resetConsent()` is deprecated in favour of
  `resetConsentButton()` — the old name read like an action but only rendered a
  button. It still works.
- `craft.cookieConsent.cookieDefinitionsByCategory` is deprecated in favour of
  `cookiesByCategory`. It still works.
- The client-side consent envelope is version 2, adding `source`. Version 1
  values migrate in place — no visitor is asked to re-consent for this.
- "Consent Logs" is now "Consent Records" throughout the control panel.

### Security

- Anonymous endpoints (`consent/save`, `cookie-detection/report`) are rate
  limited per IP.
- Consent saving records only categories the site actually has, and always
  records locked categories, rather than trusting the posted list.
- Site-override saving cannot reach a non-overridable global setting.
- Per-category Consent Mode signals are allow-listed against the seven real
  signals before reaching `gtag()`.
- Endpoint errors return machine-readable codes and appropriate status codes;
  exception detail is logged server-side and never returned to a visitor.
- `consent/status` and `consent/geo` send `Cache-Control: private, no-store` and
  `Vary: Cookie`, so no shared cache can serve one visitor's answer to another.
- The consent widget is hidden from users without permission to view records.
- Cookie-name reports are capped at 100 names per request.
- Geo headers are validated as bare two-letter codes before use.
- Exports exclude the IP hash and user-agent string.

### Performance

- Indexes on `cookieconsent_log (siteId, dateCreated)` and `(dateCreated)` —
  the first carries the records list and export, the second makes retention
  purging a range scan rather than a full table scan.
- Index on `cookieconsent_cookie (name)` for undocumented-cookie matching.
- Per-category statistics are batched at 500 rows, so memory stays flat
  regardless of table size.
- Exports stream in batches rather than loading the result set into memory.
- The front-end runtime makes no network request on a page view where a valid
  decision already exists and nothing is pending.

### Removed

- `ConsentHelper::defaultCategories()` and `ConsentHelper::categoryLabel()` —
  both unused, and both hard-coded a category vocabulary that is actually
  admin-configurable and stored in the database.

---

## 1.0.0

Initial release.

### Added

- Craft CMS 5 plugin scaffold, services, control-panel navigation and routes
- Configurable consent banner and preference centre with per-site overrides
- Database-driven consent categories
- Script and embed blocking via `data-cck-category` / `data-cck-src`
- Per-cookie disclosure, a starter library of well-known cookies, and
  browser-side cookie-name detection
- Consent records with privacy-safe storage and a control-panel viewer
- Header-based geo-targeting
- Multi-site settings, categories and cookie overrides
- Consent expiry and policy versioning
- Banner logo support
- `craft.cookieConsent.*` Twig variable and a control-panel dashboard widget
