# Cookie Consent Flow — reference notes

Craft CMS 5 plugin providing a configurable cookie consent banner, consent categories,
geo-targeting, consent logging, and script/embed blocking. This file is a living reference —
keep it in sync whenever a change affects architecture, compliance posture, or conventions.

## Architecture

- `src/Plugin.php` — bootstrap: services, CP nav/routes, Twig variable, dashboard widget,
  permissions, CP asset registration, and the response-injection hook that auto-appends the
  banner to every front-end HTML response.
- `src/models/Settings.php` — all plugin settings (banner text/layout/colours, categories,
  geo, logging). Persisted in the plugin's own `cookieconsent_settings` table via
  `SettingsRecord` — **not** Craft's built-in plugin-settings mechanism
  (`craft_plugins.settings`). The table is relational, one real column per setting: one row
  per Craft site plus a global row (`siteId = 0, isGlobal = 1`). On a site's row, a `NULL`
  column means "inherit from global" — that's what preserves the existing per-field override
  semantics (the Multi Site Override page's per-field "use global" toggle) while giving every
  setting its own queryable column instead of a JSON blob. `geoTargetCountries` is still a
  JSON-encoded text column (whole-array in/out, never queried per-element). `categories` is
  **not** a column — cookie categories live in their own `cookieconsent_category` table, one
  row per category, foreign-keyed to `cookieconsent_settings.id` (`ON DELETE CASCADE`) via
  `CookieCategoryRecord`, created/upgraded in `Install.php` itself (this plugin isn't
  published yet, so there's no installed-base constraint requiring an incremental migration —
  `Install.php` handles fresh installs, the legacy single-JSON-blob upgrade, and a leftover
  `categories` column from an earlier dev install, all in one place). A settings row with
  zero category rows means "inherit from global"
  — same meaning as a NULL column, just expressed as absence of child rows instead.
  `SettingsService` owns this: a settings row's categories are read/written via
  `_loadCategoriesForSettingsRow()` / `_saveCategoriesForSettingsRow()` /
  `_deleteCategoriesForSettingsRow()` / `_hasCategoryOverride()`, called from
  `loadSettings()`, `saveGlobalSettings()`, `saveSiteOverrides()`, and `copySiteOverrides()`
  alongside the normal column-based fields. `Settings::$categories`'s in-memory shape (array
  of `key/label/description/default/locked`) is unchanged, so nothing outside
  `SettingsService` (controllers, the Twig variable, templates, JS) needed to change.
  `SettingsService::loadSettings()` reconstructs the in-memory `Settings` object (global
  properties + a sparse `$siteOverrides` map built from every site row's non-NULL columns) so
  every existing consumer — `resolveForSite()`, `isFieldOverridden()`, the CP templates —
  keeps working unchanged. `Plugin::getSettings()` / `setSettings()` are overridden
  accordingly: `getSettings()` delegates to `SettingsService::loadSettings()`, and
  `setSettings()` is a deliberate no-op so the legacy Craft-supplied blob (always empty now)
  can never clobber the freshly loaded model. Also home to `getCssVars()` (banner theming) and
  `getSafeDescription()` (sanitized banner description — see Security). `$siteOverrides`
  (keyed by site ID, only differing fields stored) plus `resolveForSite(siteId)` — clones the
  model with that site's overrides applied on top of global values — power multi-site support;
  see `SettingsService` below for the actual resolution entry point.
- `src/services/SettingsService.php` (`cookieSettings` component) — the single place that
  resolves "effective" (global + site-override merged) settings via
  `getEffectiveSettings(?siteId)`, request-cached per site ID. Also owns
  `saveGlobalSettings()`/`saveSiteOverrides()`, the shared field-normalization logic used by
  both the Global Settings and Multi Site Override CP pages. Every render path (`renderBanner()`,
  `renderPreferencesButton()`, the frontend auto-inject hook in `Plugin.php`, etc.) goes through
  this service instead of calling `Plugin::getSettings()` directly, so they're automatically
  site-aware. Consent logging (`logEnabled`/`logRetentionDays`) is intentionally **not**
  overridable per site — it's operational/compliance config for the whole install.
- `src/services/ConsentService.php` — save/get/stats/paginated-logs/purge for consent records.
- `src/services/GeoService.php` — header-based country resolution (Cloudflare `CF-IPCountry`,
  `X-Country-Code`); fails open (shows banner) if unresolvable.
- `src/controllers/` — `ConsentController` (anonymous save/status AJAX), `SettingsController`
  and `LogsController` (both permission-gated, see Security), `DashboardController` (renders
  the live banner preview).
- `src/web/assets/banner/cookie-banner.js` — front-end runtime: banner/modal visibility,
  localStorage-backed consent state, server sync, and the gated-content engine (see
  "Script/embed blocking" below).
- `src/templates/banner/_banner.twig` — the actual banner + preferences modal markup, shared
  between the real front-end render path and the CP dashboard's live preview (same partial,
  included in both places).

## Script / embed blocking (the core compliance mechanism)

Non-essential `<script>`/`<iframe>` tags must be inert until their category is accepted:

```html
<script type="text/plain" data-cck-category="analytics"
        data-cck-src="https://www.googletagmanager.com/gtag/js?id=XXX"></script>
<iframe data-cck-category="marketing" data-cck-src="https://www.youtube.com/embed/XXX"></iframe>
```

`cookie-banner.js` scans for these on page load (if consent already exists) and immediately
after the visitor accepts/customizes, cloning matching nodes into real executable elements.
`data-cck-category` accepts a comma/space-separated list (activates on ANY match). Documented
in the README's "Script & Embed Blocking" section — keep both in sync if the convention changes.

**Known limitation:** this can only prevent loading, not retroactively kill an already-running
script within the same page view. Documented, not a bug.

Public API: `window.CookieConsentKit.refreshGatedContent()` re-scans against current consent —
needed if a page injects gated markup after load (AJAX/SPA navigation).

## Security posture

- **CSRF**: `ConsentController::actionSave()` validates the token explicitly.
- **Permissions**: `cookieConsentFlow:manageSettings` (Settings + Banner controllers) and
  `cookieConsentFlow:viewLogs` (Logs controller) are registered via
  `UserPermissions::EVENT_REGISTER_PERMISSIONS` in `Plugin.php`, enforced via `beforeAction()`
  overrides. Before this existed, *any* CP user (not just admins) could reach these pages —
  admins always bypass permission checks by Craft's design, so this only restricts non-admin
  accounts. Added specifically because `bannerDescription` allows raw HTML (see below).
- **XSS**: `Settings::$bannerDescription` intentionally allows basic HTML ("links, strong") per
  its own admin field instructions, and is rendered on *every* front-end page for *every*
  visitor. It is sanitized through `craft\helpers\HtmlPurifier` via `Settings::getSafeDescription()`
  (allow-list: `a[href|title|target|rel], strong, b, em, i, br`; schemes restricted to
  `http/https/mailto`) — templates must call `settings.safeDescription`, **never**
  `settings.bannerDescription|raw` directly. If a new template ever needs this field, route it
  through the same getter.
- **Consent log data**: `ipHash` is a *salted* SHA-256 (new random salt per call, via
  `ConsentHelper::hashIp()`) — this is intentionally non-correlatable across records for
  privacy, but as a side effect it cannot be used to deduplicate/group by IP. That's a
  deliberate trade-off, not a bug.

## Compliance status

Grounded against GDPR/ePrivacy/UK-GDPR/CCPA-style requirements — **not a claim of guaranteed
legal compliance**, which no software can make (and this plugin's docs should never claim it).

**Done:**
- No non-essential script/embed execution before consent (script/embed blocking above).
- Consent withdrawal/modification anytime (`resetConsent()`, `renderPreferencesButton()`).
- Consent categories, timestamp, and choices stored per visitor (`ConsentLogRecord`).
- Granular per-category consent (not just accept/reject-all).
- Locked/"necessary" categories always included, never user-togglable.

**Pending (real compliance gaps, not just polish) — next planned work:**
- **Consent expiry**: `hasConsent()` in `cookie-banner.js` never checks age — once stored,
  consent never expires. Guidance (ICO/CNIL) recommends re-asking within ~6–12 months. Needs a
  `Settings::$consentExpiryDays` setting + a timestamp-age check.
- **Re-consent on policy/version change**: no `policyVersion`/`bannerVersion` field exists on
  `Settings` or `ConsentLogRecord` at all. If an admin changes categories or cookie policy, there
  is currently no mechanism to invalidate existing visitors' stored consent. Needs a new DB
  migration (existing dev site already has the `cookieconsent_log` table from `Install.php`, so
  this must be an incremental migration, not an edit to `Install.php`) plus a version-mismatch
  check in `hasConsent()`.
- **GeoIP integration**: only trusts Cloudflare/proxy headers; no fallback lookup for hosts
  without them (fails open — legally safe, just not optimized).
- Consent log export (CSV/JSON) — pure convenience, not compliance-critical.

**Considered and deliberately left as-is:**
- **Equal prominence for Accept/Reject buttons.** Some EU regulators (e.g. CNIL) have fined
  sites for making Reject visually secondary to Accept (faint outline vs. solid CTA), and this
  plugin's Reject button does default that way (`Settings::$rejectBgColor` etc. — a light
  outline, while Accept defaults to a solid blue CTA). A same-weight solid default was tried and
  then explicitly rejected by the site owner: colours are already fully admin-configurable, so
  enforcing a default here was judged unnecessary — the tool exposes the choice, using it
  correctly is on whoever configures the banner. **Do not "fix" this again** without being asked.

## Dead code / cleanup candidates

- `CHANGELOG.md` only documents the original scaffold — several real feature commits since
  aren't reflected. Update when doing a real release pass.

## Session-state caveats (this specific dev install)

- Plugin git repo (`plugins/Cookie-Consent-Flow`, its own `.git`) had a large amount of
  session work sitting uncommitted as of 2026-07-27 — Consent Logs feature, dashboard live
  preview + device switcher, widget fix, and the security/compliance fixes in this file. Check
  `git status`/`git log` before assuming anything here is committed.

## Conventions worth preserving

- Commit message style in this repo: lowercase, short, descriptive (e.g. `fix banner color
  settings UI`) — not Conventional Commits format.
- CSS/JS asset changes go in `src/web/assets/{banner,cp}/` — `banner/` is front-end (loaded via
  `BannerAsset`, CSS-only variant hand-registered by `DashboardController` for the preview),
  `cp/` is control-panel-only (loaded via `CpAsset` on every CP request).
