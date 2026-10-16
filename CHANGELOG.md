# Changelog

## 1.0.5 - YYYY-MM-DD

### Features

- Consent Scope setting, to share one consent decision across the sites of a
  site group (for multi-language websites on one domain)
- `EVENT_RESOLVE_CONSENT_SCOPE` event, to choose which sites share a decision
- Show Consent ID to Visitors setting: the preference centre shows the
  visitor's consent ID with a copy button, the same across visits
- Search consent records by visitor ID, and delete one visitor's records (new
  permission, and the `records/delete` console command)
- `CookieConsent.getConsentId()` and the `cookieConsent:consentId` event

### Fixed

- On multisite installs whose sites differ by path (`/` and `/en/`), consent
  saves, geo-targeting and cookie reports from other sites were handled as the
  primary site

## 1.0.0 - 2026-10-03

Initial release version.

### Features

- Consent banner in four layouts, with configurable text, colours and logo
- Preference centre where visitors choose category by category
- Configurable consent categories, including locked categories
- Blocking of scripts and iframes until their category is accepted
- Cookie lists for each category, and a cookie table for cookie policy pages
- Detection of cookie names that are in use but not yet documented
- Google Consent Mode v2 (Basic and Advanced)
- Global Privacy Control and Do Not Track support
- Country-based banner targeting
- Consent expiry and policy invalidation
- Consent records with filters, statistics, CSV/JSON export and retention
- Control panel dashboard, dashboard widget and user permissions
- Welcome tour of the control panel screens, shown once to each user
- Multisite support with per-site overrides
- Support for static page caching
- Keyboard-accessible banner and preference centre
- Twig and JavaScript APIs
