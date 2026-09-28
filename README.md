# Cookie Consent Flow

Cookie Consent Flow adds a cookie consent banner and preference centre to
Craft CMS sites. Visitors choose which optional cookie categories they allow,
optional scripts and embeds stay blocked until they agree, and each decision
can be recorded in the control panel.

> Cookie Consent Flow provides consent tooling. Configuring it correctly,
> documenting your cookies accurately and getting legal review remain the site
> owner's responsibility. The plugin makes no claim of compliance with any
> particular law.

## Features

- Consent banner in four layouts (bottom bar, top bar, centre popup, corner
  popup), with configurable text, colours, spacing and logo
- Preference centre where visitors choose category by category
- Consent categories you create and manage, including locked
  (always-on) categories
- Blocks scripts and iframes until their category is accepted
- Cookie list for each category, shown in the preference centre and available
  as a cookie table for your cookie policy page
- Cookie detection that shows which cookie names are in use but not yet
  documented
- Google Consent Mode v2 (Basic and Advanced)
- Global Privacy Control and Do Not Track support
- Country-based banner targeting
- Consent expiry, and a way to ask every visitor again when your policy
  changes
- Consent records with filters, statistics, CSV/JSON export and a retention
  period
- A dashboard, a Consent Overview dashboard widget and user permissions in
  the control panel
- Multisite support: global settings with optional per-site overrides
- Works with static page caching: the injected HTML is the same for every
  visitor
- Keyboard-accessible banner and preference centre
- Twig and JavaScript APIs

## Requirements

- Craft CMS `^5.0`
- PHP `>=8.2.0`

The plugin is tested on Craft 5.0.0 and the current 5.x release. Earlier
Craft 5 releases have published security advisories, so run the latest 5.x.

## Installation

```bash
composer require sfs-infotech/craft-cookie-consent-flow
php craft plugin/install cookie-consent-flow
```

You can also install it from **Settings → Plugins** in the control panel after
running `composer require`.

## Getting started

1. Go to **Cookie Consent → Banner** and set your privacy policy URL.
2. Check the default categories under **Cookie Consent → Settings → Cookie
   Categories**.
3. Document the cookies your site uses under **Cookie Consent → Cookies**.
4. Mark up your optional scripts and iframes (see
   [Blocking scripts and iframes](#blocking-scripts-and-iframes)).
5. Add a link visitors can use to change their choice later, for example in
   your footer:

   ```twig
   {{ craft.cookieConsent.renderPreferencesButton() }}
   ```

The banner is added to every HTML page automatically, just before `</body>`.

## Configuration

Editors manage all settings in the control panel under **Cookie Consent**:

| Page | What you configure |
| --- | --- |
| **Banner** | Layout, content, colours, and geo-targeting |
| **Cookies** | The cookies listed for each category, and detected cookies |
| **Settings** | Categories, consent logging and retention, Google Consent Mode, privacy signals, consent expiry, and **Invalidate Existing Consent** |
| **Multisite** | Per-site overrides (shown only on multisite installs) |
| **Consent Records** | Consent history, statistics and exports |

A few deployment settings go in `config/cookie-consent-flow.php` instead.
Multi-environment arrays work as they do in other Craft config files.

```php
<?php

return [
    'geoCountryHeader'   => null,  // Header your CDN/proxy puts the visitor's country in
    'automaticRetention' => false, // Delete expired records during Craft garbage collection
    'retentionBatchSize' => 1000,  // Records deleted per batch
];
```

Any other key in this file is reported in the Craft log.

## Consent categories

Four categories are created by default: **Essential** (`necessary`),
**Analytics**, **Marketing** and **Preferences**. You can rename, reorder,
add and remove them.

Each category has a key, a label, a description and two options:

- **Locked**: always on, and visitors cannot turn it off. Use this only for
  strictly necessary cookies.
- **Default on**: pre-selected in the preference centre. Saving the preference
  centre unchanged then records consent to it, and in many jurisdictions
  (including the EU) a pre-ticked box is not valid consent, so leave this off
  for optional categories unless your legal review says otherwise.

When a visitor accepts, rejects or saves their choice, it is stored in their
browser and restored on later visits. They are asked again when their consent
expires (180 days by default, at most 36,500, and `0` turns expiry off) or
when you use **Invalidate Existing Consent**.

## Blocking scripts and iframes

Set `type="text/plain"` on the script, move its URL to `data-cck-src`, and
name the category it belongs to:

```html
<script type="text/plain" data-cck-category="analytics"
        data-cck-src="https://example.com/analytics.js"></script>

<iframe data-cck-category="marketing"
        data-cck-src="https://www.youtube.com/embed/VIDEO_ID"
        title="Video"></iframe>
```

- Inline scripts work too: keep the code in the tag and leave out
  `data-cck-src`.
- `data-cck-category` must match a category key. If you list more than one
  key, separated by commas or spaces, the element loads when any of those
  categories is accepted.
- Activated scripts run in document order, and keep their `nonce` attribute.
- `data-cck-src` must be an `http(s)` or relative URL.
- Content in a locked category loads straight away.
- Scripts without this markup are not blocked.
- Markup added to the page later (AJAX, Sprig, infinite scroll) is activated
  when you call `CookieConsent.refreshGatedContent()`.
- Withdrawing consent stops scripts loading from then on and unloads iframes
  of the withdrawn categories. It cannot unload a script that has already run
  on the current page.

## Privacy signals

Under **Settings → Privacy Signals**:

- **Global Privacy Control (GPC)**: on by default.
- **Do Not Track (DNT)**: off by default.

When a signal you have enabled is present and the visitor has no stored
decision, every optional category is rejected, no banner is shown, and the
decision is recorded with the source `gpc` or `dnt`. A decision the visitor
made earlier stays in effect until it expires, they change it, or you use
**Invalidate Existing Consent**.

## Cookie detection

Once a visitor has made a decision, or is outside your geo-targeting
countries, the banner script reports the names of the cookies in their
browser, at most once a day per name. It never sends cookie values, and names
are not linked to a visitor. Names are case-sensitive and limited to the
characters cookie names may contain (`*` is not accepted, because it is the
wildcard in documented names such as `_ga_*`). Up to 2,000 names are kept per
site. Names you have not documented appear under **Cookies →
Detected, not yet documented**, where you can add or dismiss them. Detection
only sees cookies set in the browsers of visitors who use your site, so it may
not find every cookie.

**Add from Library** fills in details for common, well-known cookies.

To show your documented cookies on a page:

```twig
{{ craft.cookieConsent.cookieTable() }}
```

## Google Consent Mode

Enable it under **Settings → Integrations**, then choose which Consent Mode
signals each category grants. The available signals are `ad_storage`,
`ad_user_data`, `ad_personalization`, `analytics_storage`,
`functionality_storage`, `personalization_storage` and `security_storage`.

- **Advanced** (default): a default with every optional signal denied is
  sent before Google tags run, followed by an update once the visitor decides.
  `security_storage` is granted in the default, and the signals of locked
  categories are granted straight away, because those categories cannot be
  declined.
- **Basic**: no default is sent, so gate the Google tag itself with
  `data-cck-category`.

Other options are `wait_for_update` (default `500` ms, and `0` leaves it out),
URL passthrough and ads data redaction (both on by default). The script is
added to `<head>` automatically. If you turn that off, place it yourself,
optionally with your Content Security Policy nonce:

```twig
{{ craft.cookieConsent.consentModeScript() }}
{{ craft.cookieConsent.consentModeScript(cspNonce) }}
```

Every Consent Mode update also pushes a `cookie_consent_update` event to
`dataLayer`. Resetting consent sends every optional signal back to `denied`.

Consent Mode tells Google's tags what the visitor chose. It does not stop
scripts loading, so keep using `data-cck-category`.

## Geo-targeting

Under **Banner → Geo-targeting** you can show the banner only to visitors in
the countries you choose. Visitors outside those countries see no banner and
optional content runs. No decision is stored and no consent record is
created for them.

The visitor's country comes from a request header set by your CDN or proxy.
**No header is trusted until you name it** in
`config/cookie-consent-flow.php`:

```php
return [
    'geoCountryHeader' => 'CF-IPCountry', // Cloudflare
];
```

Only name a header your proxy always sets itself, and make sure visitors
can't reach your server without going through the proxy. Otherwise they can
send the header themselves and choose their own country. If the country is
unknown, or the lookup fails or takes longer than 4 seconds, the banner is
shown. The answer is remembered for the browser tab.

Developers can add another country source by implementing
`GeoProviderInterface` and registering it in `config/app.php`:

```php
'components' => [
    'plugins' => [
        'pluginConfigs' => [
            'cookie-consent-flow' => [
                'components' => [
                    'geo' => ['providers' => [\modules\MyGeoProvider::class]],
                ],
            ],
        ],
    ],
],
```

A provider that throws, whether while it is being created or while it looks
up a country, is skipped and the error is logged. The next provider is asked
instead.

## Consent records

With consent logging on (the default), each decision is stored with:

- a random visitor ID, kept in a first-party cookie
- the accepted categories, the outcome (`accept_all`, `reject_all` or `custom`)
  and the source (`banner`, `gpc`, `dnt` or `api`)
- the policy version shown to the visitor, the site and the time
- the country, if it is known
- a keyed hash of the visitor's IP network (the address truncated to /24 for
  IPv4 or /48 for IPv6)
- the user agent, truncated to 500 characters

Raw IP addresses and cookie values are never stored. Exports leave out the IP
hash and the user agent.

**Consent Records** shows outcome totals and acceptance rates for each
category, and can filter by site, outcome, source, category, country, policy
version and date range. You can export the filtered records as CSV or
JSON, up to 100,000 records per export.

Set a retention period (365 days by default, at most 36,500, and `0` keeps
records forever)
under **Settings → Consent Logging**, then delete older records with:

```bash
php craft cookie-consent-flow/retention/clear --dry-run=1
php craft cookie-consent-flow/retention/clear --force=1
```

The command also accepts `--days=N` and `--site=handle` (or a site ID). You can also set
`automaticRetention` to `true` to have Craft's garbage collection delete them.

## Multisite

Global settings apply to every site. On the **Multisite** page, each site can
override banner content, colours and layout, categories, cookie lists,
geo-targeting and Google Consent Mode. You can copy the global settings to a
site or go back to them at any time.

Consent choices, visitor IDs and consent records are kept separate for each
site, so consent given on one site does not count on another. When a site is
deleted, its overrides, cookie lists and detected cookies are removed; its
consent records are kept and are removed by retention.

## Permissions

- **Manage cookie consent configuration**
- **View consent records**
  - **Export consent records**

Being able to view records does not include exporting them. The control panel
navigation shows only the pages a user can access. On multisite installs,
records, exports, the dashboard and site overrides are also limited to the
sites the user has access to in Craft; global settings apply to every site.

## Twig and JavaScript

```twig
{{ craft.cookieConsent.renderPreferencesButton() }}
{{ craft.cookieConsent.resetConsentButton() }}
{{ craft.cookieConsent.cookieTable('analytics') }}
{% for cookie in craft.cookieConsent.cookies('analytics') %}…{% endfor %}
```

The other Twig helpers:

- `renderBanner()` renders the banner where you call it, instead of before
  `</body>`. The banner is then not added a second time.
- `consentModeScript()` outputs the Consent Mode snippet, for when
  **Auto-inject into `<head>`** is off (see
  [Google Consent Mode](#google-consent-mode)).
- `cookiesByCategory()` returns the documented cookies grouped by category
  key.
- `categories()` returns the current site's categories, and `settings()` its
  effective settings.
- `isBannerEnabled()` says whether the banner is switched on for the site. It
  does not say whether a given visitor will see it.
- `countryCode()` returns the country the request resolved to, or null. Don't
  use it in output that can be cached, because the first visitor's country
  would then be served to everyone.

```js
CookieConsent.hasConsent('analytics');
CookieConsent.getConsentState();
CookieConsent.openPreferences();
CookieConsent.updateConsent({ analytics: true, marketing: false });
CookieConsent.resetConsent();
CookieConsent.onReady(function (decision) { /* null if undecided */ });

document.addEventListener('cookieConsent:changed', function (event) {
  console.log(event.detail);
});
```

The events are `ready`, `loaded`, `shown`, `suppressed`, `preferences`,
`changed`, `accepted`, `rejected`, `custom`, `reset` and `syncFailed`, each
prefixed with `cookieConsent:`.

Pages may be cached, so don't use a visitor's consent state to change
server-rendered HTML. Check consent in the browser instead.

Buttons you add yourself work with `data-cck-action`, set to `accept-all`,
`reject-all`, `open-preferences`, `close-preferences`, `save-preferences` or
`reset-consent`.

Resetting consent removes the decision from the browser and asks again. The
visitor ID cookie is `httpOnly` and stays, so later records remain linked to
earlier ones, and cookies set by other scripts are not deleted.

PHP developers can listen for `EVENT_BEFORE_BANNER_RENDER` (cancellable) and
`EVENT_AFTER_CONSENT_SAVE`, which fires after a consent record has been
saved.

## Cookies and storage used by the plugin

| Name | Where | Purpose |
| --- | --- | --- |
| `cck_consent_{siteId}` | localStorage, or a first-party cookie (365 days) if localStorage can't be written | The visitor's decision |
| `cck_consent_{siteId}_pending` | localStorage, or a first-party cookie (365 days) if localStorage can't be written | A decision not yet recorded on the server |
| `cck_reported_{siteId}` | localStorage | Cookie names reported in the last day |
| `cck_geo_{siteId}_{policyVersion}` | sessionStorage | The geo-targeting answer for the tab |
| `cck_visitor_{siteId}` | `httpOnly` cookie (1 year) | Visitor ID, set only when a record is written |

Recording a decision also starts a Craft session (`CraftSessionId` and the
CSRF cookie). Before the visitor decides, the only thing stored is the
geo-targeting answer, in sessionStorage, when geo-targeting is on: it holds
`show` or `hide`, nothing about the visitor, and saves a lookup on every page
view in that tab. With geo-targeting off, nothing is stored before a
decision.

## Caching and proxies

The injected HTML is the same for every visitor. Exclude these URLs from your
page cache:

- `actions/cookie-consent-flow/consent/save`
- `actions/cookie-consent-flow/consent/geo`
- `actions/cookie-consent-flow/consent/status`
- `actions/cookie-consent-flow/cookie-detection/report`
- `actions/users/session-info`

These endpoints are rate limited per visitor, per minute: 20 saves, 30 status
and 30 geo requests, and 10 cookie reports of at most 100 names each. Behind a
CDN or load balancer,
list its IP ranges in Craft's `trustedHosts` so each visitor is identified
exactly; without it, visitors are told apart by the forwarded address within
a larger limit for each proxy address, and a forged header cannot escape that
limit. If the cache is unavailable, requests are allowed and an error is
logged.

## Troubleshooting

- **The banner doesn't appear.** Check that the banner is enabled, and that
  the page is an HTML response with a `</body>` tag.
- **A script runs before consent.** It needs both `type="text/plain"` and
  `data-cck-category`.
- **An iframe loads before consent.** Move its URL from `src` to
  `data-cck-src`.
- **The banner shows everywhere with geo-targeting on.** Set
  `geoCountryHeader` and check that your proxy sends that header.
- **Visitors get "429 Too Many Requests" errors.** If your site is behind a
  CDN or load balancer, list its IP ranges in Craft's `trustedHosts` setting.
  Otherwise each proxy address has one combined limit for everyone behind it.
- **Cached pages can't save consent.** Exclude the URLs listed under
  [Caching and proxies](#caching-and-proxies) from your page cache.
- **Decisions aren't recorded.** Check that consent logging is enabled, then
  look in the Craft log.

## Uninstalling

Uninstalling deletes the plugin's tables, **including all consent records**.
This cannot be undone, so export your records first if you may need them.

## Support

Email: hello@softwareforsolution.com

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Cookie Consent Flow is intended to be distributed as a commercial plugin
through the Craft Plugin Store, under the [Craft License](LICENSE.md). It is
not open-source software. Each licence covers one production environment at
a time; see [LICENSE.md](LICENSE.md) for the full terms.
