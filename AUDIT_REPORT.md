# Technical audit

Last reviewed: 2026-09-15. Scope: current working tree, including uncommitted
changes.

## Migration architecture

This plugin is in a rebuild phase. It has one canonical schema migration,
`src/migrations/Install.php`, which creates the complete current schema on a
fresh install. There is deliberately no chain of timestamped incremental
migrations and no supported in-place upgrade from a pre-rebuild database; such
a database is reinstalled, not migrated. `Plugin::$schemaVersion` identifies the
canonical schema and is independent of the package version in `composer.json`.

## Result

The reviewed code now has a coherent end-to-end consent flow, safe defaults,
site-aware storage, filtered reporting, accessible controls, and one canonical
install schema. No claim of legal compliance is made; sites must still classify
and block their own optional scripts correctly.

## Verified automatically

- PHP syntax: all source and test files pass `php -l`.
- JavaScript syntax: both browser bundles pass `node --check`.
- Composer metadata: valid (`version` field warning only).
- Unit tests: 52 tests, 92 assertions pass.
- Git whitespace/error check: clean.

## Verified in the Craft/DDEV application

- A fresh install created the full schema: the live MySQL schema contains the
  log/settings/category columns and query indexes the current code requires.
- The front page renders one banner and one runtime configuration.
- The geo endpoint returns JSON with `private, no-store` and `Vary: Cookie`.
- A real CSRF-protected custom decision saved the expected categories, source,
  policy version, and site. Its exact test row was removed afterward.
- A rollback-only duplicate-category test returned a normal failure and kept
  the previous category rows unchanged.
- The retention command completed successfully in dry-run mode.
- The updated records template compiled in Craft; full CP interaction still
  needs an authenticated browser pass.

## Important fixes in this audit

1. Brought `Install.php` up to the current schema, so a fresh install creates
   the `source` log column, Consent Mode/privacy settings, category signal
   storage, indexes, and every plugin table.
2. Made install-time schema reconciliation idempotent and PostgreSQL-safe for
   the older JSON settings-table upgrade.
3. Made global settings, site overrides, category replacement, and site-copy
   operations transactional. A failed child-row insert no longer leaves a
   partly saved configuration.
4. Allow-listed inline CSS values and privacy-policy URL schemes, preventing a
   delegated settings editor from breaking out of page style/link context.
5. Added a responsive, accessible outcome chart to Consent Records. Its cards
   and percentages now follow the same filters as the table.
6. Prevented duplicate markup/runtime initialization when a template uses the
   manual Twig banner renderer alongside the automatic response hook.
7. Removed configuration permission labels that were not real enforcement
   boundaries; the three remaining permissions match controller behavior.
8. Replaced oversized historical user documentation with this shorter setup,
   operation, API, testing, privacy, and troubleshooting guide.

## Follow-up audit fixes

A later audit pass reviewed the whole plugin again and fixed what it found.
`Install.php` was re-verified against the current models, records and services
and needed no schema change.

1. Consent events are dispatched on `document` and bubble, so the documented
   `document.addEventListener('cookieConsent:changed', …)` integration works;
   `window` listeners and the `cck:*` legacy names are unaffected.
2. Posted settings are validated against the model's own `rules()` before being
   written, on both the global and per-site paths. An out-of-range layout,
   corner position or Consent Mode type, or an over-length policy version, can
   no longer be persisted.
3. `closeButtonText` has a field on the Banner page; it was the only shipped
   setting with no global control.
4. Exports state when they hit the 100,000-record limit — in the JSON envelope,
   as a final CSV row, and as a response header — instead of looking complete.
5. The center-popup banner is a real dialog: `role="dialog"`, `aria-modal`,
   focus moved in, trapped and restored. Escape still records nothing.
6. Resetting consent also discards a queued server sync, so a withdrawn
   decision cannot be posted afterwards.
7. The Consent Mode head replay reads the same cookie fallback as the runtime,
   so a visitor with localStorage blocked is not replayed as unconsented.
8. The tab-scoped geo cache key carries the policy version, so invalidating
   consent also retires cached geo answers.
9. All-Sites statistics label categories from the union across sites instead of
   the primary site's vocabulary alone.
10. The English catalogue matches the strings the plugin actually uses, and the
    control-panel JavaScript's messages are translatable.
11. Asset `forceCopy` is development-only.

## Architecture checked

- Banner auto-injection and manual Twig rendering
- Local storage migration, expiry, policy version, and per-site namespacing
- Accept, reject, custom, API, GPC, and DNT decisions
- Script/iframe activation and Consent Mode updates
- Cache-safe CSRF and geo requests
- Anonymous endpoint validation and throttling
- Consent records, filters, detail, export, statistics cache, and retention
- Relational global/site settings, categories, cookies, and cookie detection
- CP permissions, multisite inheritance, templates, responsive behavior, and
  keyboard accessibility

## Required release checks

Run these remaining checks before publishing:

1. Repeat fresh-install coverage on PostgreSQL.
2. Save every CP form, reset/copy site overrides, and confirm rollback after a
   deliberately invalid category row.
3. Exercise consent save/status/geo/report endpoints with valid, missing, and
   expired CSRF tokens and rate-limit boundaries.
4. Test banner layouts and the records table/chart at desktop and mobile widths.
5. Test a cached page with two clean browser sessions from different sites and
   geo locations.

## Known product boundary

The plugin blocks only correctly tagged scripts and iframes. It cannot discover
the purpose of a cookie, verify disclosures, unload code already executed in
the current page, or replace jurisdiction-specific legal review.
