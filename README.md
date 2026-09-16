# Cookie Consent Flow

Cookie consent and compliance management for Craft CMS 5.

> This plugin supplies consent tooling. Correct configuration, accurate cookie
> disclosure, and legal review remain the site owner's responsibility. No claim
> of compliance in any particular jurisdiction is made.

## What it does

Cookie Consent Flow asks your visitors what they consent to, remembers their
answer, and stops optional scripts and embeds from loading until they have
agreed. It records each decision as evidence, and tells Google's tags what the
visitor chose.

## Key features

- Responsive consent banner and preference centre
- Script and iframe blocking by category
- Per-cookie disclosure for your cookie policy
- Google Consent Mode v2
- Global Privacy Control and Do Not Track support
- Geo targeting by country
- Consent records with filtering, statistics and CSV/JSON export
- Multisite settings, categories and cookie lists
- Consent expiry and policy versioning
- Safe for static page caches and CDNs

## Why use it

Most consent plugins assume every page is rendered fresh for every visitor.
This one does not: the HTML it injects is identical for everyone, so it stays
correct behind Blitz, a reverse proxy or a CDN. Everything specific to a
visitor — their decision, their country, their CSRF token — is resolved in
their own browser at runtime.

It is also honest about what it records. A decision appears in the consent log
only when a visitor actually made one.

## Installation

Requires Craft CMS 5.0+ and PHP 8.2+.

```bash
composer require sfs-infotech/craft-cookie-consent-flow
php craft plugin/install cookie-consent-flow
```

## Basic setup

1. Open **Cookie Consent → Banner** and set your privacy-policy URL.
2. Review **Settings → Cookie Categories**.
3. Open **Cookies** and document each cookie you use.
4. Tag your optional scripts and iframes with `data-cck-category`.
5. Add a permanent preferences link to your footer:
   `{{ craft.cookieConsent.renderPreferencesButton() }}`

The banner is injected automatically before `</body>`. The defaults work
without further configuration.

## Cookie categories

Categories are what visitors actually agree to, and you define them. Four ship
by default — Essential, Analytics, Marketing and Preferences — and you can
rename, reorder, add or remove them.

Each category has a key (used in your markup), a label, a description, and two
switches:

- **Locked** — always on, and the visitor cannot decline it. Use this for
  strictly necessary cookies only.
- **Default on** — pre-ticked in the preference centre. Optional categories are
  off unless you deliberately turn this on.

## Banner and preference centre

The banner appears as a bottom bar, top bar, centre popup or corner popup, and
always falls back to a bottom bar on small screens. Content, colours, spacing
and logo are configurable.

The preference centre lets visitors choose category by category, and lists the
cookies you have documented under each one. It is a proper modal dialog:
focus moves into it, stays trapped while it is open, and returns to whatever
opened it. Escape closes it without recording anything — dismissing a question
is not an answer.

## Script and iframe blocking

Give the tag `type="text/plain"` and move its URL to `data-cck-src`. Browsers
never execute a `text/plain` script, so nothing loads until the visitor agrees.

```html
<script type="text/plain" data-cck-category="analytics"
        data-cck-src="https://example.com/analytics.js"></script>

<iframe data-cck-category="marketing"
        data-cck-src="https://www.youtube.com/embed/VIDEO_ID"
        title="Video"></iframe>
```

Inline scripts work the same way — keep the code in the tag and omit
`data-cck-src`. Use comma- or space-separated keys if any of several
categories should activate the element.

`data-cck-category` must match a category key you have configured. A script
you do not tag cannot be blocked by the plugin.

Withdrawing consent stops future loading; it cannot unload a script that has
already run on the current page. It takes full effect on the next navigation.

## Privacy signals

Both signals are browser-level statements of preference. When you enable one,
the plugin applies it as a rejection of every optional category and does not
show the banner — the visitor has already answered. The decision is recorded
like any other, with its source marked `gpc` or `dnt`.

### Global Privacy Control (GPC)

GPC is a modern signal that several US state privacy laws recognise. **On by
default.** When a visitor's browser sends it, optional categories are rejected
according to your configured policy and the banner stays hidden.

Turn it off under **Settings → Privacy Signals** if your site needs to ask
every visitor explicitly.

### Do Not Track (DNT)

DNT is the older, effectively deprecated signal. It has no general legal force
and some browsers enabled it by default, which makes reading it as a considered
choice unsafe. It is therefore **off by default** and treated separately from
GPC.

Enable it under **Settings → Privacy Signals** if you want to honour it. When
enabled it behaves exactly like GPC.

## Google Consent Mode v2

Cookie Consent Flow collects consent. Consent Mode communicates the resulting
state to Google's tags. Enable it under **Settings → Integrations** and map
signals to your categories.

**Consent Mode is not a replacement for script blocking.** It tells Google's
SDK not to use storage; it does not stop anything from loading, and it has no
effect at all on non-Google scripts. Keep using `data-cck-category`.

### Basic

No default command is sent and no Google tag is expected to load before
consent. You gate the Google tag with `data-cck-category` like anything else.

### Advanced

A conservative default is sent before any Google tag runs, with every optional
signal denied. Tags may then load and send cookieless pings, and a consent
update follows once the visitor decides.

### Signals

The four that usually matter:

| Signal | Granted when the visitor accepts |
| --- | --- |
| `analytics_storage` | your analytics category |
| `ad_storage` | your advertising category |
| `ad_user_data` | sending user data to Google for ads |
| `ad_personalization` | personalised advertising |

`functionality_storage`, `personalization_storage` and `security_storage` are
also available. Nothing is hard-coded — you decide which signals each category
grants. `security_storage` is granted by default, because denying it breaks
Google's own security features.

### Options

- **wait_for_update** — milliseconds Google waits for a decision before acting
  on the default state. Default 500; 0 omits it.
- **URL passthrough** — passes click identifiers through URLs when `ad_storage`
  is denied.
- **Ads data redaction** — redacts ad click identifiers in network requests
  when `ad_storage` is denied.
- **Auto-inject** — places the snippet at the top of `<head>` for you. Turn it
  off only if your Google tag must appear earlier, and place it yourself:

```twig
{{ craft.cookieConsent.consentModeScript() }}
```

## Geo targeting

Show the consent experience only to visitors in the countries you choose.

A visitor **inside** a target country sees the banner, and nothing optional
runs until they decide.

A visitor **outside** the target countries is one you have decided you do not
need to ask. The banner is not shown, and optional content runs according to
that policy. Importantly, no decision is stored on their device and **no
consent record is created** — they never made a choice, so nothing is written
down as though they had. If you later narrow the target countries, those
visitors simply start being asked.

If you want no banner *and* nothing optional running, leave geo targeting off
and gate that content yourself.

Country detection reads a validated two-letter header from your CDN or proxy
(Cloudflare, Fastly, CloudFront and the common conventions). An unknown country
shows the banner — failing open is the safe direction. The decision is made per
visitor against an uncacheable endpoint, so it is never baked into cached HTML.
For a real GeoIP lookup, implement `GeoProviderInterface` and add it to
`GeoService`.

## Consent logging

Every decision is recorded with a random per-site visitor ID, the accepted
categories, the outcome, the source, the policy version, the site, the time,
the country, a salted IP hash and a truncated user agent. Raw IP addresses and
cookie values are never stored.

Set a retention period under **Settings** and schedule the cleanup daily:

```bash
php craft cookie-consent-flow/retention/clear --dry-run=1
php craft cookie-consent-flow/retention/clear --force=1
```

`--days=N` overrides the configured period, `--site=handle` limits the run, and
a retention value of `0` keeps records indefinitely.

Use **Invalidate Existing Consent** in Settings when your policy changes
materially. It bumps the policy version so every visitor is asked again;
existing records are kept, because they remain accurate evidence of what was
agreed under the previous policy.

## Multisite

Global settings are the default and each site stores only what it overrides.
Turn a section back to **Use Global Settings** to drop its overrides.

Settings, categories, cookie lists, browser storage, visitor identifiers and
consent records are all site-aware, so a decision on one site is never read as
consent on another.

## Cookie disclosures

Document each cookie's name, provider, purpose and duration under **Cookies**.
These appear in the preference centre under their category, and you can render
them on a cookie-policy page:

```twig
{{ craft.cookieConsent.cookieTable() }}
```

The plugin also reports the cookie names it observes in visitors' browsers
(names only, never values, never tied to a visitor) so you can spot anything
undocumented. A starter library of well-known cookies is included to save
typing.

## Statistics and exports

**Consent Records** shows outcome totals, per-category acceptance rates and a
daily trend, and lets you filter by site, outcome, source, category, country,
policy version and date range.

Exports in CSV or JSON cover exactly the filtered set on screen, and say so
explicitly if they hit the 100,000-record limit. Exporting is a separate
permission from viewing.

## Twig and front-end usage

```twig
{{ craft.cookieConsent.renderPreferencesButton() }}
{{ craft.cookieConsent.resetConsentButton() }}
{{ craft.cookieConsent.cookieTable() }}

{% for cookie in craft.cookieConsent.cookies('analytics') %}
  {{ cookie.name }} — {{ cookie.purpose }}
{% endfor %}
```

`craft.cookieConsent.settings`, `.categories`, `.cookiesByCategory`,
`.isBannerEnabled` and `.countryCode` are also available.

Do not branch cached HTML on a visitor's state — that bakes one visitor's
answer into the page everyone else is served. Gate content in the browser
instead, with `data-cck-category` or the events below.

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

Events are `ready`, `loaded`, `shown`, `suppressed`, `preferences`, `changed`,
`accepted`, `rejected`, `custom`, `reset` and `syncFailed`, each prefixed with
`cookieConsent:`. They are dispatched on `document` and bubble, so a listener
on either `document` or `window` receives them.

## Developer integration

PHP services are available from `Plugin::getInstance()` as `consent`,
`cookieSettings`, `cookieDefinitions`, `consentMode`, `geo` and `statistics`.
The plugin fires `EVENT_BEFORE_BANNER_RENDER` (cancellable) and
`EVENT_AFTER_CONSENT_SAVE` (notification only).

Three permissions can be delegated: manage configuration, view consent records,
and export consent records. Viewing does not imply exporting.

If your site sits behind a CDN or load balancer, add it to Craft's
[`trustedHosts`](https://craftcms.com/docs/5.x/reference/config/general.html).
Without it Craft cannot tell one visitor behind that proxy from another, and
they share a rate-limit bucket.

## Testing

```bash
composer test       # PHPUnit
composer test-js    # front-end runtime harness (requires node)
composer check-all  # both
```

`composer test-js` runs the consent runtime inside a small dependency-free stub
browser (`tests/js/`), covering the behaviour that fails silently rather than
loudly: whether gated content activates, whether any request happens before the
visitor is asked, which failed syncs are retried, and whether a reset can be
undone by a request already in flight.

Before launch, check in a real browser that rejecting prevents every optional
network request, that accepting activates only the selected categories, and
that geo rules behave behind your actual proxy. Disable any other
cookie-consent plugin first — several inject their own banner and write their
own `gtag('consent', …)` commands, which makes the results meaningless.

## Troubleshooting

- **Banner missing** — confirm it is enabled and the response contains a
  `</body>` tag.
- **Script runs too early** — it needs both `type="text/plain"` and
  `data-cck-category`.
- **Iframe loads too early** — move its URL from `src` to `data-cck-src`.
- **Consent is not logged** — check that logging is enabled, then the browser's
  network response, the Craft log, and your retention setting.
- **Banner always shows with geo on** — verify your proxy forwards a supported
  two-letter country header. Unknown locations show the banner by design.
- **Cached page cannot save consent** — exclude the plugin's anonymous action
  endpoints from your page cache.

## Support and requirements

- Craft CMS 5.0+, PHP 8.2+, MySQL 8+ or PostgreSQL 13+
- Issues: https://github.com/sfsinfotech/cookie-consent-flow/issues
- Email: hello@softwareforsolution.com

## License

MIT. See [LICENSE.md](LICENSE.md).
