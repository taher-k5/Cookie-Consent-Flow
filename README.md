# Cookie Consent Kit

Cookie consent and compliance management for Craft CMS 5. Provides a configurable cookie banner, consent categories, geo-targeting, privacy-safe consent logging, multi-site support, a Twig variable, and a CP dashboard widget.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

## Installation

### Via Composer (local development)

```bash
# From your Craft project root
composer require sfs-infotech/craft-cookie-consent-kit:@dev

php craft plugin/install cookie-consent-kit
```

### Via the Plugin Store

Search for **Cookie Consent Kit** in the Craft Plugin Store (coming soon).

## Configuration

Navigate to **Cookie Consent → Settings** in the Craft control panel to configure:

- Cookie banner text, position, and visibility
- Consent categories (necessary, analytics, marketing, preferences)
- Consent event logging and retention period
- Geo-targeting by country code

## Twig Integration

```twig
{# Check if the banner should be displayed #}
{% if craft.cookieConsent.isBannerEnabled %}
    {# Render your banner partial here #}
{% endif %}

{# List available categories #}
{% for category in craft.cookieConsent.categories %}
    {{ category }}
{% endfor %}
```

## Front-end API

Two anonymous AJAX endpoints are registered under Craft's action URL:

| Method | URL                                         | Description                     |
|--------|---------------------------------------------|---------------------------------|
| POST   | `/actions/cookie-consent-kit/consent/save`  | Record visitor consent choices  |
| GET    | `/actions/cookie-consent-kit/consent/status`| Retrieve current consent state  |

## Development Roadmap

See [`CHANGELOG.md`](CHANGELOG.md) and the planning document for upcoming features:

- Front-end banner rendering
- Full consent record persistence
- GeoIP integration
- Per-category script blocking helper
- Multi-site settings overrides UI
- Consent log export

## License

MIT — see [`LICENSE.md`](LICENSE.md)
