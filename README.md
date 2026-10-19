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
- Data subject requests: visitors can see their consent ID, and you can find,
  export or delete one visitor's records — or, on sites with user accounts,
  all of one user's records
- A dashboard, a Consent Overview dashboard widget and user permissions in
  the control panel
- Multisite support: global settings with optional per-site overrides, and
  one shared decision for the language sites of a site group if you choose
- Works with static page caching: the injected HTML is the same for every
  visitor
- Keyboard-accessible banner and preference centre
- Twig and JavaScript APIs

## Requirements

- Craft CMS `^5.0`
- PHP `>=8.2.0`

Version 1.0.0 was tested on Craft 5.0.0 and the then-current 5.x release;
version 1.0.5 has been tested on Craft 5.10. Earlier Craft 5 releases have
published security advisories, so run the latest 5.x.

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

The first time each user opens a Cookie Consent page, a short welcome tour
shows where these screens are, covering only the screens their permissions
allow. Once they finish or close it, it does not open again for that user. It
can be replayed with **Replay Tour** at the bottom of
**Cookie Consent → Settings**.

## Configuration

Editors manage all settings in the control panel under **Cookie Consent**:

| Page | What you configure |
| --- | --- |
| **Banner** | Layout, content, colours, and geo-targeting |
| **Cookies** | The cookies listed for each category, and detected cookies |
| **Settings** | Categories, consent logging, retention and the visitor's consent ID, Google Consent Mode, privacy signals, consent expiry, consent scope (multisite), and **Invalidate Existing Consent** |
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
version, date range and visitor ID. You can export the filtered records as CSV
or JSON, up to 100,000 records per export.

Set a retention period (365 days by default, at most 36,500, and `0` keeps
records forever)
under **Settings → Consent Logging**, then delete older records with:

```bash
php craft cookie-consent-flow/retention/clear --dry-run=1
php craft cookie-consent-flow/retention/clear --force=1
```

The command also accepts `--days=N` and `--site=handle` (or a site ID). You can also set
`automaticRetention` to `true` to have Craft's garbage collection delete them.

### Data subject requests

Records are pseudonymous: the only link between a person and their records is
the visitor ID. To let visitors find theirs, turn on **Show Consent ID to
Visitors** under **Settings → Consent Logging** (off by default). The
preference centre then shows "Your consent ID" with a **Copy** button, and a
link to your privacy policy when one is set.

- The ID is shown from the first page view and stays the same across page
  loads and visits, as with OneTrust and Usercentrics. Before the visitor's
  first decision it is made in the browser and stored there
  (`cck_cid_{scope}`). Their first record is saved under it. If the server
  cannot use it — another record already has it, and it was not this same
  browser's — it issues a new ID, and the preference centre shows that one.
- After the first decision the ID comes from the server, which reads it from
  the visitor's signed, `httpOnly` cookie (`cck_visitor_{scope}`). It is never
  written into the page HTML, so page caching is unaffected.
- The server always decides which ID a record is saved under, so a copied or
  edited value cannot join another visitor's records.
- This stores an identifier before the visitor decides, so check the setting
  against your own legal advice. With it off (the default), nothing
  identifying is stored before a decision, and turning it off removes stored
  IDs on the visitor's next page view.
- While the setting is on, page scripts can read the ID too (see
  `CookieConsent.getConsentId()` below). It identifies only the visitor's
  consent records.
- The ID belongs to one browser. A visitor who used another browser or
  device, or cleared their cookies, has another ID for those records.

When a visitor quotes their ID:

- **Access:** paste it into **Visitor ID** on **Consent Records** and export
  the result.
- **Erasure:** with the **Delete a visitor's consent records** permission,
  search for the ID and click **Delete records for this visitor**. It
  deletes the records shown: on the selected site, or on every site you can
  access. A record's page offers the same for every site you can access.
  Records on other sites are never deleted, and each deletion is written to
  the Craft log. From the command line:

```bash
php craft cookie-consent-flow/records/delete --visitor=<id> --dry-run=1
php craft cookie-consent-flow/records/delete --visitor=<id> --force=1
```

The command also accepts `--site=handle` (or a site ID). Whether to delete
proof-of-consent records when asked is your legal decision; export them first
if you may need them.

### Linking records to logged-in users

On a site with front-end accounts (Craft's login and registration; Craft
Solo allows only one user account, so this needs Craft Team or Pro), turn on
**Link Consent Records to Logged-in Users** under **Settings → Consent
Logging** (off by default; consent logging must be on too). The user always
comes from Craft's own session, and the visitor ID from the plugin's signed
visitor cookie — never from anything the browser claims. The plugin keeps
two links:

- A decision made while logged in records the Craft user.
- Every visitor ID the user's browsers carry is linked to the user: when they
  register on the front end, when they log in, and at each decision while
  logged in. A visitor who decided anonymously and then registers or logs in
  has that earlier decision linked straight away — Craft does not log a newly
  registered user in by default, so registration links it too.
  After they clear their cookies, the new visitor ID is linked the next time
  they are logged in.

A visitor who decided as a guest is not asked again when they log in or
register — their browser already holds the decision — and that decision is
**attached to their account**: its latest record on each site gets the user,
as long as it is still in effect (current policy version, not expired, not
already another user's). It stays one record, and what was decided, when and
under which policy is unchanged. Older guest records of the browser are left
as they were.

Anonymous visitors are unaffected, and nothing is linked while an admin uses
**Log in as** on a user.

To answer a request from a user, enter their email, username or ID in
**User** on **Consent Records** (shown to admins, and to users who may edit
users). Each record is marked:

| Mark | Meaning |
| --- | --- |
| **Logged in** | Decided while logged in as this user |
| **Linked at login** | Decided as a guest, then attached to this user as they logged in or registered |
| **Their browser** | Decided logged out, on a browser only this user logged in on |
| **Shared browser** | Decided logged out, on a browser other users also logged in on: it may be someone else's |

Export covers the result, with `userId` and a `match` column giving the mark.
**Delete records for this user** deletes the **Logged in** and **Linked at
login** records, and optionally the **Their browser** ones. **Shared
browser** records are never deleted this way. From the command line:

```bash
php craft cookie-consent-flow/records/delete --user=anna@example.com --dry-run=1
php craft cookie-consent-flow/records/delete --user=anna@example.com --include-browsers=1 --force=1
```

**When a User Is Deleted** chooses what happens to their records, as soon as
the user is deleted (moving them to the trash included):

- **Anonymise records** (default): the records stay as proof of consent,
  without the user.
- **Delete records**: the records linked to them (**Logged in** and **Linked
  at login**) are deleted. Logged-out records of their browsers are left to
  retention.

Either way their browser links are removed, and restoring the user from the
trash does not bring them back.

Users in the trash can still be searched for and their records deleted.
Export lists the `userId` column, and the user's `match`, only to those who
may see users.

Linked records identify a person, so they are personal data rather than
pseudonymous: mention this in your privacy policy. A browser the user never
logged in on cannot be linked to them. To those who may see consent records
and users, each user's account page in the control panel shows how many
consent records they have, with a link to them.

## Multisite

Global settings apply to every site. On the **Multisite** page, each site can
override banner content, colours and layout, categories, cookie lists,
geo-targeting and Google Consent Mode. You can copy the global settings to a
site or go back to them at any time.

By default, consent choices and visitor IDs are kept separate for each site,
so consent given on one site does not count on another. When a site is
deleted, its overrides, cookie lists and detected cookies are removed; its
consent records are kept and are removed by retention.

### Sharing consent across a site group

A multi-language website is usually one site group with a site per language
(`example.com/`, `example.com/en/`, `example.com/de/`). For the visitor it is
one website, so asking again on every language is unnecessary. Under
**Settings → Advanced**, set **Consent Scope** to **Per site group**, and a
decision made on one language counts on every site in its group.

- Consent records keep the site the decision was made on, so records,
  exports, statistics and per-site permissions are unchanged.
- Banner text, category labels and descriptions stay per-site overrides, so
  each language keeps its own wording.
- The policy version and consent expiry are global, so they are always the
  same across a group.
- Give the sites of a group the same category keys. A decision covers the
  optional categories the visitor has been asked about: a site with an
  optional category the visitor was not asked about asks again, rather than
  leaving it silently off. That includes a category that was locked on the
  site where the visitor decided. Once answered, it counts on every site.
- A choice made on one site keeps what the visitor chose for categories only
  other sites have. **Reject all** is the exception: it turns off every
  optional category, on every site of the group.
- Sharing works only between sites on the same domain. Browsers keep storage
  separately for each origin (scheme, host and port), so sites of one group
  on different domains or subdomains (`example.hr`, `en.example.com`) still
  ask separately. With **Per site group** selected, the settings page lists
  the groups this applies to.
- Resetting consent on one site clears the decision for every site in its
  group.
- Changing the setting asks every visitor again, because the decision is then
  stored under a different name. A decision stored under the previous setting
  is removed, so it cannot count again if you change the setting back. The
  visitor ID cookie is also named after the scope, so records made under each
  setting use a different visitor ID.
- If you use static page caching, clear the cache after changing the setting.
  Until then, cached pages use the previous setting and may ask again.

To group sites some other way, listen for `EVENT_RESOLVE_CONSENT_SCOPE` and
set the key yourself. Sites with the same key share a decision. The key may
use letters, numbers, dashes and underscores (up to 64 characters):

```php
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\events\ResolveConsentScopeEvent;
use yii\base\Event;

Event::on(
    Plugin::class,
    Plugin::EVENT_RESOLVE_CONSENT_SCOPE,
    function (ResolveConsentScopeEvent $event) {
        if (in_array($event->site->handle, ['hr', 'en', 'de'], true)) {
            $event->scopeKey = 'brand';
        }
    }
);
```

## Permissions

- **Manage cookie consent configuration**
- **View consent records**
  - **Export consent records**
  - **Delete a visitor's consent records**

Being able to view records does not include exporting or deleting them.
Searching by user, and seeing which user a record belongs to, also needs
permission to edit users (or an admin account). The control panel
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
CookieConsent.getConsentId().then(function (id) { /* null unless Show Consent ID is on */ });

document.addEventListener('cookieConsent:changed', function (event) {
  console.log(event.detail);
});
```

The events are `ready`, `loaded`, `shown`, `suppressed`, `preferences`,
`changed`, `accepted`, `rejected`, `custom`, `reset`, `syncFailed` and
`consentId` (the visitor's consent ID became known), each prefixed with
`cookieConsent:`.

Pages may be cached, so don't use a visitor's consent state to change
server-rendered HTML. Check consent in the browser instead.

Buttons you add yourself work with `data-cck-action`, set to `accept-all`,
`reject-all`, `open-preferences`, `close-preferences`, `save-preferences`,
`reset-consent` or `copy-consent-id`.

Resetting consent removes the decision from the browser and asks again. The
visitor ID cookie is `httpOnly` and stays, so later records remain linked to
earlier ones, and cookies set by other scripts are not deleted.

PHP developers can listen for `EVENT_BEFORE_BANNER_RENDER` (cancellable),
`EVENT_AFTER_CONSENT_SAVE`, which fires after a consent record has been
saved, and `EVENT_RESOLVE_CONSENT_SCOPE`, which chooses which sites share a
decision (see [Multisite](#sharing-consent-across-a-site-group)).

## Cookies and storage used by the plugin

| Name | Where | Purpose |
| --- | --- | --- |
| `cck_consent_{scope}` | localStorage, or a first-party cookie (365 days) if localStorage can't be written | The visitor's decision |
| `cck_consent_{scope}_pending` | localStorage, or a first-party cookie (365 days) if localStorage can't be written | A decision not yet recorded on the server |
| `cck_reported_{siteId}` | localStorage | Cookie names reported in the last day |
| `cck_geo_{siteId}_{policyVersion}` | sessionStorage | The geo-targeting answer for the tab |
| `cck_visitor_{scope}` | `httpOnly` cookie (1 year) | Visitor ID, set only when a record is written |
| `cck_cid_{scope}` | localStorage, or a first-party cookie (365 days) if localStorage can't be written | The consent ID shown to the visitor; only with **Show Consent ID to Visitors** on |

`{scope}` is the site ID by default, and `g{groupId}` when
**Consent Scope** is **Per site group** (see
[Multisite](#sharing-consent-across-a-site-group)).

Recording a decision also starts a Craft session (`CraftSessionId` and the
CSRF cookie). Before the visitor decides, only two things can be stored:

- the geo-targeting answer, in sessionStorage, when geo-targeting is on. It
  holds `show` or `hide`, nothing about the visitor, and saves a lookup on
  every page view in that tab;
- the consent ID, when **Show Consent ID to Visitors** is on.

With both off, nothing is stored before a decision.

## Caching and proxies

The injected HTML is the same for every visitor. Exclude these URLs from your
page cache. The plugin's own endpoints are requested on each site's base URL
(for example `/en/actions/cookie-consent-flow/consent/save`), so exclude them
under every site's path:

- `actions/cookie-consent-flow/consent/save`
- `actions/cookie-consent-flow/consent/geo`
- `actions/cookie-consent-flow/consent/status`
- `actions/cookie-consent-flow/consent/id`
- `actions/cookie-consent-flow/cookie-detection/report`
- `actions/users/session-info`

These endpoints are rate limited per visitor, per minute: 20 saves, 30 status,
30 consent ID and 30 geo requests, and 10 cookie reports of at most 100 names each. Behind a
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
