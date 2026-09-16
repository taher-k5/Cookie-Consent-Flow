# Technical audit and release certification

Internal engineering record for the 1.0.0 release. User documentation is in
`README.md`; development conventions are in `CLAUDE.md`.

Last reviewed: 2026-09-16. Scope: the full working tree.

## Scope

The plugin was reviewed end to end across several passes: consent flow and
storage, the anonymous endpoints, settings and multisite persistence, the
control panel, the front-end runtime, Consent Mode, geo targeting, retention,
export, and the schema. This document records what was found, what was
verified, and what remains unverified.

## Major issues found and fixed

1. **Geo targeting blocked optional content permanently.** A suppressed banner
   established no state at all, so every `data-cck-category` script and iframe
   stayed inert and Consent Mode kept its denied default — for every visitor
   outside the target countries, indefinitely. A suppression now applies the
   site's geo policy while writing no stored decision and no consent record, so
   the log keeps meaning "a visitor chose this".

2. **Rate limiting counted the proxy, not the visitor.** The throttle keyed on
   the raw socket peer, so every visitor behind one CDN edge shared a bucket
   and a busy site would refuse legitimate consent saves. Now keyed on Craft's
   proxy-aware `getUserIP()`, which honours forwarded headers only where
   `trustedHosts` is configured.

3. **Cookie detection ran before the visitor was asked anything,** causing
   Craft to issue a CSRF cookie pre-consent. Deferred to a settled state; the
   endpoint's CSRF requirement is unchanged.

4. **`</BODY>` silently lost the banner.** The guard matched
   case-insensitively while the splice did not, so an uppercase or whitespaced
   closing tag received the Consent Mode snippet and then no banner.

5. **Retention and record filters ignored UTC.** `dateCreated` is stored in
   UTC but both were built from the server clock, so on any non-UTC install the
   boundaries sat hours out — keeping records due for deletion, or deleting
   records still within retention.

6. **A permanently refused consent sync retried forever.** Any non-2xx queued
   the payload and the queue was re-sent on every page load. Only failures that
   could plausibly succeed later (no response, 408, 429, 5xx) are queued now,
   and queueing is capped.

7. **`resetConsent()` could be undone by a request already in flight.** The
   earlier sync's failure handler wrote the withdrawn decision back into the
   queue. Each decision and reset now carries a generation; handlers from a
   superseded one do nothing.

8. **Colour settings could pass validation and fail at INSERT.** The columns
   were 30 characters while the accepted syntax was unbounded. Both ends are
   now bounded by one constant, asserted by `SchemaConsistencyTest`.

9. **Dashboard category statistics ignored the active filters** while the
   outcome totals honoured them, so one screen showed two different datasets.

10. **CSV exports were open to spreadsheet formula injection** and omitted
    `fputcsv()`'s `$escape` argument, which PHP 8.4+ deprecates.

Earlier passes also fixed: consent saving on statically cached pages (no token
is embedded; one is fetched at runtime), geo decisions baked into cached HTML,
a leaked keydown listener in the focus trap, stored consent trusted verbatim,
a settings-table failure taking down every front-end page, a 500 on every
consent save from an invalid cache-invalidation call, CSRF rejections returning
404 with no usable body, and `saveConsent()` being uncallable outside a web
request.

## Verification

### Automated

- PHPUnit: 110 tests, 235 assertions — pass.
- Front-end runtime harness: 42 tests — pass.
- PHP syntax, JavaScript syntax, `composer validate`, `git diff --check` —
  clean.
- Translations: 260 strings used, 260 catalogued.
- Schema consistency between `Install.php` and the settings model — asserted.

### MySQL 8 (DDEV, PHP 8.4, `Asia/Kolkata`)

The non-UTC timezone is what made the retention check meaningful. A record
placed inside the 5½-hour window the old local-time cutoff would have wrongly
deleted survived correctly; a 400-day record was purged and a 364-day record
kept.

Also verified: banner and runtime injected exactly once into `</body>`,
`</BODY>` and `</body >` with each closing tag preserved verbatim; geo endpoint
responses and cache headers; pages served to two different countries were
byte-identical; consent save rejected a missing token and an unknown action,
and an accepted save stripped an unknown category and force-added the locked
one; Consent Mode emitted one default at the top of `<head>`.

### PostgreSQL 16.15 (clean Craft 5 project, package install)

The plugin was installed through Composer as the package a user receives, not
as a symlinked working copy.

- Fresh install created all five tables, eight indexes and both cascading
  foreign keys; all 24 colour columns are `varchar(64)`.
- Booleans round-trip correctly — the one genuinely driver-specific risk, since
  PostgreSQL returns `t`/`f` where a naive cast reads both as true.
- A 39-character `hsla(...)` saved and re-read; 64 characters saved; 65 refused
  by validation with a field-named message rather than by the database.
- Settings update, category replacement, duplicate-key rollback, JSON column
  round-trip, consent save, retention purge and the daily-trend `DATE()`
  grouping all behaved as on MySQL.
- `_widenColorColumns()` was exercised directly: columns were reduced to
  `varchar(30)` to simulate a pre-fix database, the canonical migration re-run,
  and all 24 widened with data intact. A second run altered nothing.
- Uninstall dropped every table cleanly, and a reinstall from the final package
  recreated the full schema. A front-end page, a CSRF-protected consent save
  (unknown category stripped, locked category added), the geo endpoint and the
  retention command were all exercised afterwards.

### Real browser (headless Chrome, CDP)

39 automated checks plus geo and Consent Mode Basic, each in an isolated
browser context, all passing.

- A fresh visitor triggers no request and is issued no cookie — read from the
  browser's own cookie jar.
- Accept, Reject and Custom activate exactly the tagged content they should;
  Reject All makes no optional network request.
- Geo: a target-country visitor sees the banner and nothing runs; a
  non-target visitor sees no banner, gated script and iframe do run, Consent
  Mode is granted, and no stored decision or consent record is created.
- Consent Mode: denied defaults with `wait_for_update`, emitted exactly once;
  correct grant/deny updates per outcome; Basic emits no default but still
  updates. Reject All keeps the locked category's signals granted.
- Accessibility: focus enters the preference centre, is trapped across 25 tab
  presses, Escape closes it recording nothing, focus returns to the opener, and
  the banner is operable by keyboard alone.
- The control-panel preview renders `role="region"` with no `aria-modal` even
  on the centre-popup layout, while the same layout on the front end renders
  `role="dialog"` with `aria-modal`.
- No console errors across a full consent flow.

### Control panel, multisite and export

`VIEW_LOGS` does not imply `EXPORT_LOGS`. Site overrides apply to their own
site and inherit the rest; a non-overridable global setting cannot be reached
through the per-site form; reset returns a site to inheritance. Invalid layout,
corner position, Consent Mode type and over-length policy version are refused
with field-named messages. A renamed category leaves its disclosures orphaned
and visible to an admin, and the orphan is absent from the rendered visitor
modal.

A real authenticated CSV export was taken with hostile values seeded: a site
name of `=cmd|calc!A1` and policy versions beginning `=`, `+`, `-` and `@` were
each rendered as literal text, while UTF-8, embedded commas, quotes and a
newline round-tripped through a strict parse with no formula-capable cell
remaining. Numeric values were untouched, and the JSON export correctly does
not apply the CSV-specific guard.

## Known limitations

Genuinely unverified, and not claimed:

- **Static analysis has not been run,** and is not configured in this
  repository. `craftcms/ecs` resolves a 2022 toolchain built for Craft 4 whose
  config API no longer matches and which floods PHP 8.5 with deprecations, and
  `craftcms/phpstan` is a configuration-only package that ships no binary.
  Rather than leave two advertised commands that cannot run, both scripts and
  both dependencies were removed. Adding working static analysis is future
  work.
- **Visual and responsive rendering has not been reviewed by a human.** The
  browser pass asserts behaviour, semantics and focus, not appearance. Banner
  layouts at mobile widths and the records chart at small sizes still want an
  eye.
- **Blitz and production CDNs were not tested.** Cache safety was demonstrated
  by serving byte-identical HTML to two countries, not behind a real cache.
- **Control-panel forms were exercised on PostgreSQL only;** the browser pass
  used the MySQL site.

## Product boundary

The plugin blocks only correctly tagged scripts and iframes. It cannot discover
the purpose of a cookie, verify disclosures, unload code already executed in the
current page, or replace jurisdiction-specific legal review.

## Release decision

No functional or security blocker remains. The items above are known,
documented and non-blocking. Recommended for a final human release check —
specifically a look at mobile rendering and a decision on static analysis.
