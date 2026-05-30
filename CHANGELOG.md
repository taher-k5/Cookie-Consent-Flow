# Cookie Consent Flow Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/) and this project adheres to [Semantic Versioning](https://semver.org/).

## Unreleased

### Added
- Initial plugin scaffold for Craft CMS 5
- Plugin base class with service registration and CP nav
- Settings model and database-backed settings record
- Consent log database record
- Install migration (creates `cookieconsent_settings` and `cookieconsent_log` tables)
- `ConsentService` stub (save/get/purge)
- `GeoService` stub (country code resolution)
- `ConsentController` AJAX endpoints (save, status)
- `SettingsController` CP settings page
- `DashboardController` CP dashboard page
- `CookieConsentVariable` Twig variable (`craft.cookieConsent.*`)
- `ConsentWidget` CP dashboard widget stub
- `ConsentHelper` static utility methods
- CP asset bundle (CSS + JS placeholders)
- Twig templates: dashboard, settings, widget
- Translations folder placeholder
