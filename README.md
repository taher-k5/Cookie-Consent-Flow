# Cookie Consent Flow

Cookie consent management for Craft CMS 5: a responsive banner, preference
centre, script blocking, cookie disclosure, Consent Mode v2, geo rules,
multisite overrides, and consent records.

> This plugin supplies consent tools. Correct configuration, accurate cookie
> disclosure, and legal review remain the site owner's responsibility.

## Requirements and installation

- Craft CMS 5.0+
- PHP 8.2+

```bash
composer require sfs-infotech/craft-cookie-consent-flow
php craft plugin/install cookie-consent-flow
```

This plugin is in a rebuild phase. It ships one canonical schema migration
(`src/migrations/Install.php`), which creates the complete schema on install,
and no chain of incremental migrations. A database created by a pre-rebuild
version is not upgraded in place — reinstall the plugin rather than migrating
it, and export any consent records you need to keep first:

```bash
php craft cookie-consent-flow/retention/clear --dry-run=1   # confirm what exists
# export from Consent Records in the control panel, then:
php craft plugin/uninstall cookie-consent-flow
php craft plugin/install cookie-consent-flow
```

## Five-minute setup

1. Open **Cookie Consent → Banner** and add your privacy-policy URL.
2. Review **Settings → Cookie Categories**. Essential is locked; optional
   categories are off by default.
3. Open **Cookies** and document each cookie's name, provider, purpose, and
   duration.
4. Block every optional script or iframe using the examples below.
5. Add `{{ craft.cookieConsent.renderPreferencesButton() }}` to a permanent
   location such as the footer.

The banner is injected automatically. Defaults are usable without additional
configuration; advanced options are grouped under Settings.

## Block optional content

```html
<!-- External script -->
<script type="text/plain" data-cck-category="analytics"
        data-cck-src="https://example.com/analytics.js"></script>

<!-- Inline script -->
<script type="text/plain" data-cck-category="analytics">
  startAnalytics();
</script>

<!-- Iframe: move src to data-cck-src -->
<iframe data-cck-category="marketing"
        data-cck-src="https://www.youtube.com/embed/VIDEO_ID"
        title="Video"></iframe>
```

Use comma- or space-separated keys when any listed category may activate an
element. Untagged scripts cannot be blocked by the plugin.

## Control-panel pages

| Page | Purpose |
| --- | --- |
| Dashboard | Site totals, category rates, trend, and banner preview |
| Banner | Content, layout, logo, colors, and geo targeting |
| Cookies | Cookie disclosure and detected undocumented names |
| Consent Records | Filter, chart, inspect, and export decisions |
| Multisite | Override only the global values that differ per site |
| Settings | Categories, logging, Consent Mode, privacy signals, expiry |

Consent records can be filtered by site, outcome, source, category, country,
policy version, and date. CSV/JSON exports use the same active filters.

## Google Consent Mode v2

Enable it under **Settings → Integrations**, then map signals on each category.

- **Advanced** sends denied defaults before Google tags load, then updates them
  after a decision.
- **Basic** expects Google tags to be blocked with `data-cck-category`.

Consent Mode does not replace script blocking. Keep automatic head injection on
unless your Google tag must appear earlier; for manual placement use:

```twig
{{ craft.cookieConsent.consentModeScript() }}
```

## Multisite and geo targeting

Global settings are the default. A site row stores only explicit overrides;
switching a group back to **Use Global Settings** removes those overrides.
Consent browser storage, visitor identifiers, records, settings, categories,
and cookie lists are site-aware.

Geo targeting reads validated country headers from supported proxies/CDNs. An
unknown country shows the banner, which is the safe failure mode. Custom lookup
providers can implement `GeoProviderInterface` and be added to `GeoService`.

## Developer API

JavaScript:

```js
CookieConsent.hasConsent('analytics');
CookieConsent.getConsentState();
CookieConsent.openPreferences();
CookieConsent.updateConsent({ analytics: true, marketing: false });
CookieConsent.resetConsent();

document.addEventListener('cookieConsent:changed', function (event) {
  console.log(event.detail);
});
```

Useful events are `ready`, `loaded`, `shown`, `suppressed`, `preferences`,
`changed`, `accepted`, `rejected`, `custom`, and `reset`, each prefixed with
`cookieConsent:`. They are dispatched on `document` and bubble, so a listener on
either `document` or `window` receives them.

Twig:

```twig
{{ craft.cookieConsent.renderPreferencesButton() }}
{{ craft.cookieConsent.resetConsentButton() }}
{{ craft.cookieConsent.cookieTable() }}

{% for cookie in craft.cookieConsent.cookies('analytics') %}
  {{ cookie.name }} — {{ cookie.purpose }}
{% endfor %}
```

`craft.cookieConsent.settings`, `.categories`, `.cookiesByCategory`,
`.isBannerEnabled`, and `.countryCode` are also available. Do not branch cached
HTML on visitor-specific state; consent activation belongs in the browser.

PHP services are available from `Plugin::getInstance()` as `consent`,
`cookieSettings`, `cookieDefinitions`, `consentMode`, `geo`, and `statistics`.
The plugin fires `EVENT_BEFORE_BANNER_RENDER` and `EVENT_AFTER_CONSENT_SAVE`.

## Retention and permissions

Schedule retention daily:

```bash
php craft cookie-consent-flow/retention/clear --dry-run=1
php craft cookie-consent-flow/retention/clear --force=1
```

`--days=N` overrides the configured period and `--site=handle` limits the run.
A retention value of `0` keeps records indefinitely.

Delegate the three permissions for managing configuration, viewing records,
and exporting records. Viewing does not imply export.

## Cache, privacy, and accessibility

Injected HTML contains no visitor state or CSRF token, so it is safe for static
page caches and CDNs. Geo decisions and fresh CSRF tokens are resolved at
runtime. Per-visitor endpoints return no-store headers.

Records store a random site-specific visitor UUID, accepted categories,
outcome, source, policy version, site, time, country, a non-correlatable salted
IP hash, and a truncated user agent. Raw IPs and cookie values are never stored;
exports omit the hash and user agent.

The banner is a labelled region. The preference centre is a keyboard-operable
modal with focus trapping/restoration, visible focus states, suitable touch
targets, and reduced-motion support.

## Testing

```bash
composer test
composer check-cs
composer phpstan
```

Before launch, also test in a real browser that rejecting prevents every
optional network request, accepting activates only the selected categories,
preferences can be changed from the footer, geo rules work behind the real
proxy/CDN, and consent survives cached pages.

## Troubleshooting

- Banner missing: confirm it is enabled and the response contains `</body>`.
- Script runs early: it must use `type="text/plain"` and `data-cck-category`.
- Iframe loads early: move its URL from `src` to `data-cck-src`.
- Consent is not logged: check logging, the browser network response, Craft
  logs, and the retention setting.
- Geo always shows: verify the proxy forwards a supported two-letter country
  header. Unknown locations intentionally show the banner.
- Cached page cannot save: exclude anonymous action endpoints from caching.

## License

MIT. See [LICENSE.md](LICENSE.md).
