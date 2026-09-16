# Cookie Consent Flow Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this
project adheres to [Semantic Versioning](https://semver.org/).

## 1.0.0 - 2026-09-16

First release.

### Consent

- Responsive consent banner in four layouts, with a keyboard-operable
  preference centre that traps and restores focus.
- Admin-defined cookie categories, with locked and default-on switches.
  Locked categories are enforced on both the client and the server.
- Script and iframe blocking with `data-cck-category` / `data-cck-src`.
- Consent expiry and policy versioning, plus an **Invalidate Existing Consent**
  action that asks every visitor again without discarding existing records.
- JavaScript API (`window.CookieConsent`) and `cookieConsent:*` DOM events.
- Twig API for the banner, preference button, reset button and cookie table.

### Privacy signals

- Global Privacy Control, honoured by default.
- Do Not Track, available and off by default, treated separately from GPC
  because it carries no general legal force.

### Google Consent Mode v2

- Advanced and Basic implementations, with all seven v2 signals mapped per
  category rather than hard-coded.
- `wait_for_update`, URL passthrough and ads data redaction.
- Auto-injection into `<head>`, or manual placement via
  `craft.cookieConsent.consentModeScript()`.
- A `cookie_consent_update` data-layer event for Tag Manager.

### Geo targeting

- Show the consent experience only in configured countries, via validated
  CDN/proxy country headers, with `GeoProviderInterface` for real lookups.
- A visitor outside the target countries gets no banner and content runs
  according to that policy — without a stored decision or a consent record,
  since they never made one.
- Resolved per visitor against an uncacheable endpoint, so no country is ever
  baked into cached HTML.

### Records, statistics and retention

- Consent records with privacy-safe storage; raw IPs and cookie values are
  never stored.
- Filtering by site, outcome, source, category, country, policy version and
  date range, with outcome totals, per-category rates and a daily trend over
  the same filtered set.
- CSV and JSON export of exactly the filtered set, behind its own permission,
  stating explicitly when the 100,000-record limit is reached.
- `cookie-consent-flow/retention/clear` console command with `--days`,
  `--site`, `--dry-run` and `--force`.

### Cookie disclosure

- Per-cookie name, provider, purpose and duration, shown in the preference
  centre and renderable as an accessible table.
- Browser cookie-name detection (names only, never values, never tied to a
  visitor) to surface undocumented cookies, with a starter library of
  well-known cookies.

### Multisite

- Global settings with sparse per-site overrides for banner content, colours,
  layout, categories, cookie lists, geo and Consent Mode.
- Settings, storage keys, visitor identifiers and records are all site-aware.

### Caching and privacy

- Injected HTML is identical for every visitor: no CSRF token, resolved
  country or stored decision is ever rendered into a cacheable page.
- CSRF tokens and geo answers are fetched at runtime; per-visitor endpoints
  send `private, no-store` and `Vary: Cookie`.
- No request is made and no cookie is set on a visitor's first page view.
  Cookie-name detection is deferred until a decision exists, so the plugin is
  never the reason an undecided visitor has cookies.

### Security

- Anonymous endpoints are CSRF-protected, allow-listed, capped and rate
  limited per visitor using Craft's proxy-aware IP handling, so a CDN edge is
  not mistaken for a single visitor and a spoofed forwarded header cannot mint
  a fresh bucket.
- Consent saves record only categories the site actually has, and always
  record locked categories, rather than trusting the posted list.
- Consent Mode signals are allow-listed before reaching `gtag()`.
- Inline CSS values and privacy-policy URL schemes are allow-listed; the
  banner description is purified.
- CSV export cells that could be read as spreadsheet formulas are neutralised.
- Endpoint errors return machine-readable codes; exception detail goes to the
  Craft log, never to a visitor.
- Exports exclude the IP hash and user-agent string.

### Accessibility

- The banner is a labelled region; the centre popup is a real dialog with
  `aria-modal`, focus management and focus restoration. Escape closes the
  preference centre without recording a decision.
- Visible focus states, adequate touch targets and reduced-motion support.
- The control-panel preview is deliberately not announced as a dialog.

### Compatibility and schema

- Craft CMS 5.0+, PHP 8.2+, MySQL 8+ and PostgreSQL 13+.
- `src/migrations/Install.php` is the single canonical schema migration and
  creates the complete schema on install.
