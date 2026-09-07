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
  — same meaning as a NULL column, just expressed as absence of child rows instead. (Accepted
  trade-off, not a bug: a site can't distinguish "override with deliberately zero categories" from
  "inherited" — same limitation, same reasoning, now also applies to `cookieconsent_cookie`.)
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
- `src/services/CookieDefinitionService.php` (`cookieDefinitions` component) — per-cookie
  disclosure content (name/provider/purpose/duration), grouped by category key, backed by its own
  `cookieconsent_cookie` table (`CookieDefinitionRecord`). Documentation only: it plays no part in
  blocking, which is still entirely the `data-cck-category` tagging convention on scripts/iframes
  (see "Script / embed blocking" below) — this exists so GDPR/ePrivacy Art. 13 ("name what cookies
  you use and why," not just a category blurb) has somewhere to live.

  **Per-site, exactly like `cookieconsent_category`**: every row FKs via `settingsId` to
  `cookieconsent_settings.id` (`ON DELETE CASCADE`) — the global row, or a specific site's own
  override row — not a loose `siteId`. Every service method here therefore takes a `$settingsId`,
  not a site ID; resolving *which* settingsId actually applies is the caller's job:
  `getEffectiveSettingsId(int $siteId)` (site's own if `hasOverrideForSettingsId()` is true,
  otherwise global's) is what both `CookiesController` and
  `CookieConsentVariable::cookieDefinitionsByCategory()` use. Because a site's settingsId row is
  the *same* row `SettingsService` uses for banner-field/category overrides, `SettingsService`
  gained public site-row helpers this service depends on (`getGlobalSettingsId()`,
  `findSiteSettingsId()`, `getOrCreateSiteSettingsId()`, `pruneSiteSettingsRowIfEmpty()`) — and
  conversely, `SettingsService::saveSiteOverrides()`/`copySiteOverrides()` had to start checking
  `cookieDefinitions->hasOverrideForSettingsId()` before deciding to delete a site's row, so a row
  that exists *only* to hold cookie overrides doesn't get collaterally deleted by an unrelated
  banner-fields/categories save. This is real, deliberate coupling between the two services (via
  `Plugin::getInstance()`, the same pattern already used everywhere else in this plugin) — not
  something to "clean up" by decoupling, since the shared row is the actual data model.

  Editing is split across two pages, matching how categories already split across Settings vs.
  Multisite — deliberately **not** a standalone site-switcher UI on the Cookies page itself (a
  first version did this differently and was corrected):
  - **Cookies page** (`cookie-consent-flow/cookies`, `CookiesController`) edits only the
    **global** list (`SettingsService::getGlobalSettingsId()`). Also hosts the Starter Library
    picker and the Detected/undocumented panel (see below) — both are global/site-agnostic
    housekeeping, so they only ever need to exist once, not duplicated per site.
  - **Multisite page** (`site-overrides.twig`) gets a **"Cookies" card**, structurally identical
    to the existing "Cookie Categories" card in the same per-site accordion: its own "Use Global
    Cookies" toggle (`cck-group-toggle`/`cck-use-global-checkbox`/`data-group-target`/
    `.cck-override-field--inherited` — the exact same generic toggle markup/CSS/JS every other
    card already uses, so no new JS was needed for the toggle itself) and its own repeatable
    cookie-row list, posted as `sites[{siteId}][cookies][idx][field]` /
    `sites[{siteId}][__useGlobal][cookies]` — handled by
    `SettingsService::saveSiteOverrides()`/`copySiteOverrides()` alongside categories, calling
    into `CookieDefinitionService` for the actual persistence. `Settings::$cookies` (a real
    property, populated by `SettingsService::loadSettings()`/`resolveForSite()`) and `'cookies'`
    being in `Settings::OVERRIDABLE_FIELDS` is what makes `isFieldOverridden()`/
    `getSiteOverrideCount()`/the card's badge and the site summary card's "Cookies: N" stat all
    work for free, exactly like categories.

  `_cookie-row.twig` takes a `namePrefix` (default `'cookies'`, matching the standalone page's
  bare field names) so the same partial serves both pages — the Multisite card passes
  `'sites[' ~ site.id ~ '][cookies]'`. The row-repeater JS in `cookie-consent.js` is scoped per
  `.cck-cookies-group` (`querySelectorAll(...).forEach(...)`, not a single `querySelector`), the
  same pattern categories already use, since the Multisite page can have many such groups (one per
  site) on one page at once — reindexing on remove specifically targets the *last* numeric
  path segment (the row index), leaving an earlier one (the site ID) untouched.

  Surfaced to visitors via `CookieConsentVariable::cookieDefinitionsByCategory()` (resolves
  `getEffectiveSettingsId()` for the current site), rendered as a per-category `<details>` in
  `_banner.twig`'s preferences modal — a category with nothing documented shows nothing extra.

  Two things exist specifically because nobody — dev or client — actually knows exact third-party
  cookie names/durations from memory, so a blank documentation form was real friction in practice:
  - **Starter library** (`src/helpers/CookieLibrary.php`) — a static, hand-maintained catalog of
    ~20 well-known cookies (Google Analytics/Ads, Meta Pixel, Microsoft UET, LinkedIn/TikTok/Reddit/
    Snapchat Ads, Hotjar, HubSpot, Craft's own session/CSRF cookies) with name/provider/duration/
    purpose already filled in, plus a `suggestedCategory` hint. The Cookies page's "Add from
    Library" picker (`cookie-consent.js`) clones a row and pre-fills it from the picked entry — the
    `suggestedCategory` is only applied if it matches one of *this install's* actual category keys,
    since categories are fully admin-configurable and can't be assumed. Durations/purposes reflect
    each provider's docs at time of writing and drift — treat as a starting point to verify, not a
    guaranteed-accurate source (said explicitly in the CP copy too).
  - **Detection** (`DetectedCookieRecord` / `cookieconsent_detected_cookie`, `CookieDetectionController`)
    — `cookie-banner.js`'s `_reportDetectedCookies()` reads `document.cookie` on every page load
    (names only, **never values** — this is a site-inventory signal, not visitor profiling) and
    POSTs any name it hasn't reported in the last 24h (throttled via a per-site localStorage
    map of name → last-reported timestamp, `REPORT_TTL_MS`) to an anonymous action. Deliberately
    a TTL, not "report each name once forever": a permanent per-browser throttle would leave a
    browser silently blind to a name forever the moment the server-side row for it disappears for
    any reason (DB restore, a plugin reinstall during dev, an admin truncating the table to
    re-test) — the client has no way to know the server "forgot," so a day-long TTL makes
    detection self-heal instead. Runs unconditionally, before the
    `hasConsent()` branch in `init()` — deliberately not gated on consent itself, since the entire
    point is to catch cookies that exist *before* or *outside* any consent decision (necessary
    cookies, or something a rogue script set that never went through `data-cck-category` at all).
    `CookieDefinitionService::getUndocumented()` is the actual "still needs attention" list: every
    detected, non-dismissed name that doesn't match any documented cookie's `name` — matched as a
    wildcard pattern (`_ga_*` covers `_ga_G-XXXXXXX`), not just exact string equality. The Cookies
    page's "Detected, not yet documented" panel surfaces these with "Document" (prefills a new row
    with just the name — unlike the library, a bare detected name carries no known provider/
    purpose/duration) and "Dismiss" (`CookiesController::actionDismissDetected()`, permission-gated,
    unlike the anonymous report endpoint) actions.
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
- No non-essential script/embed execution before consent (script/embed blocking above). Note
  this gates only tagged content (`data-cck-category`/`data-cck-src`) — it does not scan for or
  auto-block arbitrary third-party scripts a template author forgot to tag. That's a manual-
  discipline requirement, not a bug; see the "Script / embed blocking" section above.
- Consent withdrawal/modification anytime (`resetConsent()`, `renderPreferencesButton()`).
  Previously `cookie-banner.js`'s `init()` only called `_bindGlobalEvents()` on the branch where
  no consent existed yet, so a persistent "manage preferences" button rendered via
  `renderPreferencesButton()` silently did nothing for any visitor who had already consented —
  fixed by always binding the delegation regardless of consent state.
- **Per-cookie disclosure** (`CookieDefinitionService`, Cookies CP page): GDPR/ePrivacy Art. 13
  expect visitors to be told which specific cookies are used and why, not just a category-level
  description — admins document name/provider/purpose/duration per cookie, shown to visitors as
  an expandable list under each category in the preferences modal. Documentation only; doesn't
  affect blocking. Backed by a starter library of well-known third-party cookies and a browser
  detection scan (see the `CookieDefinitionService` architecture entry above) so a dev/client
  never has to already know exact cookie names from memory to fill this in.
- Consent categories, timestamp, and choices stored per visitor (`ConsentLogRecord`), now
  including the `policyVersion` in effect at the time (see below).
- Granular per-category consent (not just accept/reject-all).
- Locked/"necessary" categories always included, never user-togglable.
- **Consent expiry**: `Settings::$consentExpiryDays` (default 180, 0 disables) is passed to the
  frontend via `cckConfig`; `cookie-banner.js`'s `hasConsent()` compares `Date.now()` against the
  stored decision's `timestamp` and treats an expired decision as no decision — the banner
  reappears and `refreshGatedContent()`/`init()` stop treating stale consent as a live grant.
- **Re-consent on policy/version change**: `Settings::$policyVersion` (default `'1'`) is stored on
  every consent record (`cookie-banner.js` `_commit()`) and in `ConsentLogRecord.policyVersion`
  (server-resolved from settings, not trusted from the client). `hasConsent()` also invalidates
  a stored decision whose `policyVersion` doesn't match the currently configured one. Bumping
  `Settings::$policyVersion` after a category/policy change is a manual admin action (Advanced
  settings tab) — there's no automatic diffing of the category list. Implemented directly in
  `Install.php` (new `cookieconsent_settings.consentExpiryDays`/`policyVersion` columns,
  `cookieconsent_log.policyVersion` column) rather than an incremental migration, consistent with
  this plugin's pre-release "no installed-base constraint" convention — an already-installed dev
  copy needs an uninstall/reinstall to pick up the new columns (`schemaVersion` bumped accordingly
  each time — currently 1.6.0, after the `cookieconsent_cookie` and `cookieconsent_detected_cookie`
  tables, `cookieconsent_cookie` gaining its `settingsId` FK, and `cookieconsent_settings` gaining
  `logoAssetId`, added since). `logoAssetId` was added as a plain nullable int column directly via
  `ALTER TABLE` on the one shared dev install instead of an uninstall/reinstall — unlike the
  earlier schema changes, an additive nullable column needs no destructive drop-and-recreate to
  pick up, so there was no reason to wipe consent logs / documented cookies / detected-cookie
  history over it. This is still consistent with the "no incremental migration" convention (the
  column lives directly in `Install.php`'s `INT_FIELDS` for any future fresh install) — it's just
  that *this* one-time sync to an already-running dev DB happened to be safe to do non-destructively.
- **Banner logo**: `Settings::$logoAssetId` (nullable int, a plain id — not a real Craft
  relation/junction-table field, since there's exactly one logo per settings row) is overridable
  per site exactly like every other banner-content field, via the same `getOverrideFieldGroups()`
  metadata-driven mechanism (`'Content'` group, `type: 'asset'`) — `_override-field.twig` and
  `cookie-consent.js`'s "Copy Global Settings" quick-fill both gained a dedicated branch for this
  type, since a Craft element-select widget can't be driven through the same
  read-a-value/write-a-value logic as a plain text/color/select input. `Settings::getLogoAsset()`/
  `getLogoUrl()` resolve the id to a real `craft\elements\Asset`, returning null (not erroring) for
  an unset or since-deleted id — same fails-open pattern as everything else nullable in this
  model. Rendered in both the front-end banner and the preferences modal header
  (`_banner.twig`), purely decorative (`alt=""`) since the heading text next to it already
  conveys the same meaning.

**Pending (real compliance gaps, not just polish) — next planned work:**
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
- **Cookie name detection runs before/without consent.** `cookie-banner.js`'s
  `_reportDetectedCookies()` reads `document.cookie` and reports names to the server on every
  page load, deliberately unconditional on `hasConsent()` — gating it behind consent would defeat
  the entire point (catching necessary cookies and anything that bypassed `data-cck-category`
  entirely). This is judged fine specifically because it only ever sends cookie **names**, never
  values, never tied to a visitor identity beyond the ordinary request itself, and exists purely
  as admin-facing site-inventory telemetry (the same act as an admin opening DevTools themselves)
  — not visitor tracking/profiling. If this ever changes to send anything more than bare names,
  that reasoning stops applying and it needs to be gated properly.

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
- The "Multi Site Override" CP page is labeled **"Multisite"** in the UI (nav item, page title,
  buttons, flash messages) — renamed for brevity. Route (`multi-site-override`), controller/action
  names (`SettingsController::actionMultiSiteOverride()` etc.), CSS class names, and code comments
  were deliberately left as `multi-site-override`/`Multi Site Override` throughout — only the
  user-visible strings changed, to avoid an unnecessary URL/API-shape churn for a copy change.
