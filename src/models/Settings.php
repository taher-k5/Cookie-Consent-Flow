<?php

namespace sfsinfotech\craftcookieconsentflow\models;

use Craft;
use craft\base\Model;
use craft\helpers\HtmlPurifier;
use sfsinfotech\craftcookieconsentflow\services\ConsentModeService;

/**
 * Cookie Consent Flow – settings model.
 *
 * Settings are persisted relationally in the plugin's own `cookieconsent_settings`
 * table — one row per Craft site plus a global row, one column per setting
 * (see SettingsRecord / SettingsService) — not Craft's built-in plugin-settings
 * mechanism. All colour/layout values are used as CSS custom-property values
 * in the frontend banner.
 */
class Settings extends Model
{
    // Banner – general

    public bool   $bannerEnabled = true;

    /**
     * Layout variant: 'bottom-bar' | 'top-bar' | 'center-popup' | 'corner-popup'
     */
    public string $bannerLayout   = 'bottom-bar';

    /** Position used when layout is 'corner-popup': 'bottom-left' | 'bottom-right' */
    public string $cornerPosition = 'bottom-right';

    // Banner – content

    /**
     * Asset ID of the logo shown in the banner/preferences modal, or null
     * for no logo. Stored as a plain nullable ID (like every other setting
     * column) rather than a Craft relation/junction table — this plugin has
     * exactly one logo per settings row, not a many-relation field, so the
     * extra machinery a real Assets field type brings isn't needed. If the
     * asset is later deleted, getLogoAsset()/getLogoUrl() simply return null
     * (same "fails open" handling used elsewhere in this plugin) rather than
     * erroring — the stale ID is harmless and gets overwritten the next time
     * an admin picks a new logo.
     */
    public ?int $logoAssetId = null;

    public string $bannerHeading     = 'We value your privacy';
    public string $bannerDescription = 'We use cookies to enhance your browsing experience, serve personalised content, and analyse our traffic. Please indicate your consent preferences.';

    public string $privacyPolicyUrl      = '';
    public string $privacyPolicyLinkText = 'Privacy Policy';

    // Button labels
    public string $acceptButtonText      = 'Accept All';
    public string $rejectButtonText      = 'Reject All';
    public string $customizeButtonText   = 'Customize';
    public string $savePreferencesText   = 'Save Preferences';
    public string $closeButtonText       = 'Close';

    // Banner colours
    public string $bannerBgColor     = '#ffffff';
    public string $bannerBorderColor = '#e5e7eb';
    public string $overlayColor      = 'rgba(0,0,0,0.5)';

    // Text
    public string $headingColor     = '#111827';
    public string $descriptionColor = '#6b7280';
    public string $linkColor        = '#2563eb';

    // Accept button
    public string $acceptBgColor        = '#2563eb';
    public string $acceptTextColor      = '#ffffff';
    public string $acceptBorderColor    = '#2563eb';
    public string $acceptHoverBgColor   = '#1d4ed8';
    public string $acceptHoverTextColor = '#ffffff';

    // Reject button
    public string $rejectBgColor        = '#f9fafb';
    public string $rejectTextColor      = '#374151';
    public string $rejectBorderColor    = '#d1d5db';
    public string $rejectHoverBgColor   = '#f3f4f6';
    public string $rejectHoverTextColor = '#374151';

    // Customize button
    public string $customizeBgColor        = 'transparent';
    public string $customizeTextColor      = '#6b7280';
    public string $customizeBorderColor    = '#d1d5db';
    public string $customizeHoverBgColor   = '#f3f4f6';
    public string $customizeHoverTextColor = '#374151';

    // Save preferences button
    public string $saveBgColor   = '#2563eb';
    public string $saveTextColor = '#ffffff';

    // Close icon
    public string $closeIconColor = '#9ca3af';

    // Layout controls
    public string $borderRadius  = '8px';
    public string $padding       = '24px';
    public string $maxWidth      = '600px';
    /** Max height for popup layouts (e.g. '80vh' or '600px'). Empty/blank means no limit. */
    public string $maxHeight     = '90vh';
    public bool   $fullWidth     = false;
    public bool   $shadow        = true;
    public bool   $fixedPosition = true;

    // Cookie categories
    /**
     * Array of category definition objects:
     * [
     *   'key'         => string,   // machine name  e.g. 'analytics'
     *   'label'       => string,   // display name
     *   'description' => string,   // shown in preferences modal
     *   'default'     => bool,     // pre-checked in preferences panel
     *   'locked'      => bool,     // always enabled, toggle disabled
     * ]
     * @var array<int, array<string, mixed>>
     */
    public array $categories = [
        [
            'key'         => 'necessary',
            'label'       => 'Essential',
            'description' => 'Required for the website to function properly. Cannot be disabled.',
            'default'     => true,
            'locked'      => true,
            'gcmSignals'  => ['security_storage', 'functionality_storage'],
        ],
        [
            'key'         => 'analytics',
            'label'       => 'Analytics',
            'description' => 'Help us understand how visitors interact with our website.',
            'default'     => false,
            'locked'      => false,
            'gcmSignals'  => ['analytics_storage'],
        ],
        [
            'key'         => 'marketing',
            'label'       => 'Marketing',
            'description' => 'Used to deliver personalised advertisements relevant to you.',
            'default'     => false,
            'locked'      => false,
            'gcmSignals'  => ['ad_storage', 'ad_user_data', 'ad_personalization'],
        ],
        [
            'key'         => 'preferences',
            'label'       => 'Preferences',
            'description' => 'Allow the website to remember choices you make (language, region, etc.).',
            'default'     => false,
            'locked'      => false,
            'gcmSignals'  => ['personalization_storage'],
        ],
    ];

    // Cookie disclosure list (per-cookie name/provider/purpose/duration)
    /**
     * Documented cookies, grouped by category via each entry's `categoryKey`.
     * Persisted relationally (CookieDefinitionRecord), exactly like
     * `$categories` — this in-memory array is just the resolved shape
     * (CookieDefinitionService::getAll()'s output), not a stored column.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $cookies = [];

    // Geo-targeting
    public bool  $geoEnabled         = false;
    public array $geoTargetCountries = [];

    // Consent logging
    public bool $logEnabled       = true;
    public int  $logRetentionDays = 365;

    // Consent expiry & re-consent
    /**
     * Days after which stored consent is treated as stale and the banner is
     * shown again (ICO/CNIL-style guidance recommends re-asking within
     * ~6–12 months). 0 disables expiry entirely. Global-only, like the
     * logging fields above — not per-site overridable.
     */
    public int $consentExpiryDays = 180;

    /**
     * Bump this (any change is sufficient — a counter, a date, etc.) whenever
     * the cookie policy or category list changes materially. Stored consent
     * carrying a different value is treated as invalid, forcing every
     * visitor to re-consent. Global-only, like the logging fields above.
     */
    public string $policyVersion = '1';

    // Google Consent Mode v2
    /**
     * Master switch. When off, the plugin emits no `gtag('consent', …)`
     * commands at all and Google tags are governed purely by the
     * `data-cck-category` blocking convention.
     */
    public bool $consentModeEnabled = false;

    /**
     * 'advanced' — emit a conservative `consent default` (everything optional
     * denied) before Google tags run, so tags may load and send cookieless
     * pings, then `consent update` once the visitor decides.
     * 'basic' — emit no default command; Google tags must themselves be
     * tagged with `data-cck-category` and simply do not load before consent.
     *
     * @see ConsentModeService for the behavioural difference.
     */
    public string $consentModeType = 'advanced';

    /**
     * Whether the plugin injects the consent-default snippet into `<head>`
     * automatically. Turn off to place it yourself with
     * `{{ craft.cookieConsent.consentModeScript() }}` — useful when you need
     * it above a hard-coded GTM snippet.
     */
    public bool $consentModeAutoInject = true;

    /**
     * Milliseconds Google waits for a `consent update` before acting on the
     * default state. 0 omits `wait_for_update` entirely.
     */
    public int $consentModeWaitForUpdate = 500;

    /** Passes click identifiers through URLs when ad_storage is denied. */
    public bool $consentModeUrlPassthrough = true;

    /** Redacts ad click identifiers in network requests when ad_storage is denied. */
    public bool $consentModeAdsDataRedaction = true;

    // Browser privacy signals
    /**
     * Honour `navigator.globalPrivacyControl`. GPC is legally recognised in
     * several US state privacy laws; when present and true, the visitor is
     * treated as having rejected every optional category without being shown
     * the banner. Note this is the plugin applying the site's configured
     * behaviour — it is not a statement about where GPC is binding.
     */
    public bool $respectGpc = true;

    /**
     * Honour the legacy `navigator.doNotTrack` header. Off by default: DNT
     * has no general legal force and is widely enabled by default in some
     * browsers, so treating it as a rejection is a site-owner's choice.
     */
    public bool $respectDnt = false;

    // Multi-site overrides
    /** @var array<int, array<string, mixed>> Keyed by Craft site ID. */
    public array $siteOverrides = [];

    /**
     * The settings that hold a CSS colour, and so share one storage column
     * width and one validation bound.
     *
     * Kept as a list because three things have to agree about it: the column
     * definition in `Install.php`, the `string` rule below, and
     * `safeCssColor()`. When they disagree the failure is asymmetric — a value
     * the validator accepts but the column cannot hold passes the control
     * panel and then fails at INSERT on strict MySQL or PostgreSQL.
     */
    public const COLOR_FIELDS = [
        'bannerBgColor', 'bannerBorderColor', 'overlayColor',
        'headingColor', 'descriptionColor', 'linkColor',
        'acceptBgColor', 'acceptTextColor', 'acceptBorderColor',
        'acceptHoverBgColor', 'acceptHoverTextColor',
        'rejectBgColor', 'rejectTextColor', 'rejectBorderColor',
        'rejectHoverBgColor', 'rejectHoverTextColor',
        'customizeBgColor', 'customizeTextColor', 'customizeBorderColor',
        'customizeHoverBgColor', 'customizeHoverTextColor',
        'saveBgColor', 'saveTextColor', 'closeIconColor',
    ];

    /**
     * Maximum stored length of a colour value, in characters.
     *
     * Comfortably clears the longest thing `safeCssColor()` accepts — a fully
     * spelled-out `hsla(214.285, 100.000%, 50.000%, 0.875)` is 43, the longest
     * CSS named colour (`lightgoldenrodyellow`) is 20, and an 8-digit hex is 9
     * — while staying a bounded VARCHAR rather than TEXT, which is all a
     * colour ever needs to be.
     */
    public const COLOR_MAX_LENGTH = 64;

    /**
     * Fields a site is allowed to override. Consent logging is intentionally
     * excluded — it's an operational/compliance setting for the whole
     * install, not per-site branding.
     */
    public const OVERRIDABLE_FIELDS = [
        'bannerEnabled', 'bannerLayout', 'cornerPosition',
        'logoAssetId',
        'bannerHeading', 'bannerDescription',
        'privacyPolicyUrl', 'privacyPolicyLinkText',
        'acceptButtonText', 'rejectButtonText', 'customizeButtonText',
        'savePreferencesText', 'closeButtonText',
        'bannerBgColor', 'bannerBorderColor', 'overlayColor',
        'headingColor', 'descriptionColor', 'linkColor',
        'acceptBgColor', 'acceptTextColor', 'acceptBorderColor',
        'acceptHoverBgColor', 'acceptHoverTextColor',
        'rejectBgColor', 'rejectTextColor', 'rejectBorderColor',
        'rejectHoverBgColor', 'rejectHoverTextColor',
        'customizeBgColor', 'customizeTextColor', 'customizeBorderColor',
        'customizeHoverBgColor', 'customizeHoverTextColor',
        'saveBgColor', 'saveTextColor', 'closeIconColor',
        'borderRadius', 'padding', 'maxWidth', 'maxHeight',
        'fullWidth', 'shadow', 'fixedPosition',
        'categories', 'cookies',
        'geoEnabled', 'geoTargetCountries',
        'consentModeEnabled', 'consentModeType', 'consentModeAutoInject',
        'consentModeWaitForUpdate', 'consentModeUrlPassthrough', 'consentModeAdsDataRedaction',
    ];

    /**
     * Field metadata for the Multi Site Override UI, grouped for display.
     * Drives a single reusable override-field partial instead of
     * hand-writing a field per site per property.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    /**
     * ISO 3166-1 country code => localized name, for the geo-targeting
     * multi-select (both the Banner Settings and Multi Site Override
     * pages). Memoized per request since Craft's country repository does
     * its own locale lookups on every call.
     */
    public static function getCountryOptions(): array
    {
        static $options = null;

        return $options ??= Craft::$app->getAddresses()->getCountryList();
    }

    public static function getOverrideFieldGroups(): array
    {
        return [
            'General' => [
                ['key' => 'bannerEnabled', 'type' => 'lightswitch', 'label' => 'Enable Cookie Banner'],
                ['key' => 'bannerLayout', 'type' => 'select', 'label' => 'Banner Layout',
                    'instructions' => 'On mobile screens, the banner always appears as a bottom bar regardless of this setting.',
                    'options' => [
                    ['value' => 'bottom-bar', 'label' => 'Bottom Bar'],
                    ['value' => 'top-bar', 'label' => 'Top Bar'],
                    ['value' => 'center-popup', 'label' => 'Center Popup'],
                    ['value' => 'corner-popup', 'label' => 'Corner Popup'],
                ]],
                ['key' => 'cornerPosition', 'type' => 'select', 'label' => 'Corner Position', 'options' => [
                    ['value' => 'bottom-right', 'label' => 'Bottom Right'],
                    ['value' => 'bottom-left', 'label' => 'Bottom Left'],
                ]],
                ['key' => 'fixedPosition', 'type' => 'lightswitch', 'label' => 'Fixed / Sticky Position'],
                ['key' => 'fullWidth', 'type' => 'lightswitch', 'label' => 'Full Width'],
                ['key' => 'shadow', 'type' => 'lightswitch', 'label' => 'Shadow'],
                ['key' => 'borderRadius', 'type' => 'text', 'label' => 'Border Radius'],
                ['key' => 'padding', 'type' => 'text', 'label' => 'Padding'],
                ['key' => 'maxWidth', 'type' => 'text', 'label' => 'Max Width'],
                ['key' => 'maxHeight', 'type' => 'text', 'label' => 'Max Height'],
            ],
            'Content' => [
                ['key' => 'logoAssetId', 'type' => 'asset', 'label' => 'Logo'],
                ['key' => 'bannerHeading', 'type' => 'text', 'label' => 'Heading'],
                ['key' => 'bannerDescription', 'type' => 'textarea', 'label' => 'Description'],
                ['key' => 'privacyPolicyUrl', 'type' => 'text', 'label' => 'Privacy Policy URL'],
                ['key' => 'privacyPolicyLinkText', 'type' => 'text', 'label' => 'Privacy Policy Link Label'],
                ['key' => 'acceptButtonText', 'type' => 'text', 'label' => 'Accept All Button'],
                ['key' => 'rejectButtonText', 'type' => 'text', 'label' => 'Reject All Button'],
                ['key' => 'customizeButtonText', 'type' => 'text', 'label' => 'Customize Button'],
                ['key' => 'savePreferencesText', 'type' => 'text', 'label' => 'Save Preferences Button'],
                ['key' => 'closeButtonText', 'type' => 'text', 'label' => 'Close Button'],
            ],
            'Colors' => [
                ['key' => 'bannerBgColor', 'type' => 'color', 'label' => 'Banner Background'],
                ['key' => 'bannerBorderColor', 'type' => 'color', 'label' => 'Banner Border'],
                ['key' => 'overlayColor', 'type' => 'text', 'label' => 'Overlay (popups)'],
                ['key' => 'headingColor', 'type' => 'color', 'label' => 'Heading Text'],
                ['key' => 'descriptionColor', 'type' => 'color', 'label' => 'Description Text'],
                ['key' => 'linkColor', 'type' => 'color', 'label' => 'Link'],
                ['key' => 'acceptBgColor', 'type' => 'color', 'label' => 'Accept Background'],
                ['key' => 'acceptTextColor', 'type' => 'color', 'label' => 'Accept Text'],
                ['key' => 'acceptBorderColor', 'type' => 'color', 'label' => 'Accept Border'],
                ['key' => 'acceptHoverBgColor', 'type' => 'color', 'label' => 'Accept Hover Background'],
                ['key' => 'acceptHoverTextColor', 'type' => 'color', 'label' => 'Accept Hover Text'],
                ['key' => 'rejectBgColor', 'type' => 'color', 'label' => 'Reject Background'],
                ['key' => 'rejectTextColor', 'type' => 'color', 'label' => 'Reject Text'],
                ['key' => 'rejectBorderColor', 'type' => 'color', 'label' => 'Reject Border'],
                ['key' => 'rejectHoverBgColor', 'type' => 'color', 'label' => 'Reject Hover Background'],
                ['key' => 'rejectHoverTextColor', 'type' => 'color', 'label' => 'Reject Hover Text'],
                ['key' => 'customizeBgColor', 'type' => 'text', 'label' => 'Customize Background'],
                ['key' => 'customizeTextColor', 'type' => 'color', 'label' => 'Customize Text'],
                ['key' => 'customizeBorderColor', 'type' => 'color', 'label' => 'Customize Border'],
                ['key' => 'customizeHoverBgColor', 'type' => 'color', 'label' => 'Customize Hover Background'],
                ['key' => 'customizeHoverTextColor', 'type' => 'color', 'label' => 'Customize Hover Text'],
                ['key' => 'saveBgColor', 'type' => 'color', 'label' => 'Save Preferences Background'],
                ['key' => 'saveTextColor', 'type' => 'color', 'label' => 'Save Preferences Text'],
                ['key' => 'closeIconColor', 'type' => 'color', 'label' => 'Close Icon'],
            ],
            'Geo-targeting' => [
                ['key' => 'geoEnabled', 'type' => 'lightswitch', 'label' => 'Enable Geo-targeting'],
                ['key' => 'geoTargetCountries', 'type' => 'multiselect', 'label' => 'Target Countries',
                    'instructions' => 'Countries where the banner should be shown. Leave blank to show everywhere.',
                    'options' => self::getCountryOptions()],
            ],
            'Google Consent Mode' => [
                ['key' => 'consentModeEnabled', 'type' => 'lightswitch', 'label' => 'Enable Consent Mode v2'],
                ['key' => 'consentModeType', 'type' => 'select', 'label' => 'Implementation', 'options' => [
                    ['value' => 'advanced', 'label' => 'Advanced'],
                    ['value' => 'basic', 'label' => 'Basic'],
                ]],
                ['key' => 'consentModeAutoInject', 'type' => 'lightswitch', 'label' => 'Auto-inject into <head>'],
                ['key' => 'consentModeWaitForUpdate', 'type' => 'text', 'label' => 'wait_for_update (ms)'],
                ['key' => 'consentModeUrlPassthrough', 'type' => 'lightswitch', 'label' => 'URL Passthrough'],
                ['key' => 'consentModeAdsDataRedaction', 'type' => 'lightswitch', 'label' => 'Ads Data Redaction'],
            ],
        ];
    }

    /**
     * Looks up the human-readable label for a select-type override field's
     * current value (e.g. 'bottom-bar' → 'Bottom Bar'), for display in the
     * Multi Site Override summary cards. Returns null if the field isn't a
     * select field or the value has no matching option.
     */
    public static function getOverrideFieldOptionLabel(string $field, mixed $value): ?string
    {
        foreach (self::getOverrideFieldGroups() as $fields) {
            foreach ($fields as $fieldDef) {
                if ($fieldDef['key'] !== $field || !isset($fieldDef['options'])) {
                    continue;
                }

                foreach ($fieldDef['options'] as $option) {
                    if ($option['value'] === $value) {
                        return $option['label'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Returns a field's current value for display in the override form.
     * geoTargetCountries stays an array (the multiselect's `values`);
     * every other field is returned unchanged.
     */
    public function getFieldDisplayValue(string $field): mixed
    {
        if ($field === 'geoTargetCountries') {
            return $this->geoTargetCountries;
        }

        return $this->$field;
    }

    // Multi-site helpers
    /**
     * Returns the raw stored overrides for a site (only the fields that
     * differ from global — never the full settings set).
     *
     * @return array<string, mixed>
     */
    public function getSiteOverrideValues(int $siteId): array
    {
        return $this->siteOverrides[$siteId] ?? [];
    }

    /**
     * Whether a given field currently has a site-specific value stored,
     * as opposed to inheriting the global value.
     */
    public function isFieldOverridden(int $siteId, string $field): bool
    {
        return array_key_exists($field, $this->getSiteOverrideValues($siteId));
    }

    /**
     * Counts how many of a group's fields currently have a site-specific
     * value stored. Used to distinguish a fully-inherited group (0), a
     * fully-overridden one (count === total fields), and a partially
     * overridden one (anything in between — only possible from data saved
     * before group-level toggling existed, since the current UI always
     * flips every field in a group together).
     *
     * @param string[] $fieldKeys
     */
    public function getGroupOverrideCount(int $siteId, array $fieldKeys): int
    {
        return count(array_intersect_key(
            $this->getSiteOverrideValues($siteId),
            array_flip($fieldKeys)
        ));
    }

    /**
     * Counts the fields actually overridden for a site (excludes the
     * internal `_updatedAt` bookkeeping entry — only real settings count).
     */
    public function getSiteOverrideCount(int $siteId): int
    {
        return count(array_intersect_key(
            $this->getSiteOverrideValues($siteId),
            array_flip(self::OVERRIDABLE_FIELDS)
        ));
    }

    /**
     * Returns the unix timestamp the site's overrides were last saved, or
     * null if the site has never had overrides saved.
     */
    public function getSiteOverrideUpdatedAt(int $siteId): ?int
    {
        $updatedAt = $this->getSiteOverrideValues($siteId)['_updatedAt'] ?? null;

        return $updatedAt !== null ? (int) $updatedAt : null;
    }

    /**
     * Returns a clone of this model with the given site's overrides applied
     * on top of the global values. Because the result is still a `Settings`
     * instance, every existing accessor (getCssVars(), getSafeDescription(),
     * getCategoryKeys(), etc.) works unchanged — callers never need to know
     * whether a value came from global settings or a site override.
     */
    public function resolveForSite(int $siteId): self
    {
        $resolved = clone $this;

        foreach ($this->getSiteOverrideValues($siteId) as $field => $value) {
            if (in_array($field, self::OVERRIDABLE_FIELDS, true)) {
                $resolved->$field = $value;
            }
        }

        return $resolved;
    }

    // Helpers
    /**
     * Returns all category keys as a flat string array.
     *
     * @return string[]
     */
    public function getCategoryKeys(): array
    {
        return array_values(array_map(fn($c) => $c['key'] ?? '', $this->categories));
    }

    /**
     * Returns keys of categories that are locked (always-on).
     *
     * @return string[]
     */
    public function getLockedCategoryKeys(): array
    {
        return array_values(array_map(
            fn($c) => $c['key'],
            array_filter($this->categories, fn($c) => !empty($c['locked']))
        ));
    }

    /**
     * Returns keys of categories that are pre-checked in the preferences
     * panel. Optional categories default to *off* unless an admin explicitly
     * opts them in, so this is only ever used to seed the UI — never to infer
     * consent that a visitor hasn't given.
     *
     * @return string[]
     */
    public function getDefaultCategoryKeys(): array
    {
        return array_values(array_map(
            fn($c) => $c['key'],
            array_filter($this->categories, fn($c) => !empty($c['default']) || !empty($c['locked']))
        ));
    }

    /**
     * Returns the category → Google Consent Mode signal map, e.g.
     * `['analytics' => ['analytics_storage'], …]`. Categories with no
     * mapping are omitted. Nothing here is hard-coded per category key —
     * the mapping is whatever the admin configured on each category.
     *
     * @return array<string, string[]>
     */
    public function getCategoryGcmSignals(): array
    {
        $map = [];

        foreach ($this->categories as $category) {
            $key     = $category['key'] ?? '';
            $signals = $category['gcmSignals'] ?? [];

            if ($key === '' || !is_array($signals) || $signals === []) {
                continue;
            }

            $map[$key] = array_values(array_intersect($signals, ConsentModeService::SIGNALS));
        }

        return array_filter($map);
    }

    /**
     * Resolves the configured logo, or null if none is set / the asset was
     * since deleted (a stale id is treated the same as "no logo" rather than
     * erroring — see the $logoAssetId property doc).
     */
    public function getLogoAsset(): ?\craft\elements\Asset
    {
        return $this->logoAssetId ? \craft\elements\Asset::find()->id($this->logoAssetId)->one() : null;
    }

    /** Convenience accessor for templates: the logo's URL, or null. */
    public function getLogoUrl(): ?string
    {
        return $this->getLogoAsset()?->getUrl();
    }

    /**
     * Returns the banner description with only a small safe subset of HTML
     * allowed (links, bold, italic, line breaks). The admin field for this
     * value intentionally permits basic HTML, so it must be purified before
     * being output on the front end — it is rendered on every page, for
     * every visitor, and (without an assigned permission) any control panel
     * user, not only admins, can edit it.
     */
    public function getSafeDescription(): string
    {
        return HtmlPurifier::process($this->bannerDescription, [
            'HTML.Allowed' => 'a[href|title|target|rel],strong,b,em,i,br',
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
            'AutoFormat.Linkify' => false,
        ]);
    }

    /**
     * Normalises a colour value: if it is a bare 3- or 6-character hex string
     * (no leading #), prepends '#' so it is a valid CSS colour.
     * Values that already start with '#', or are non-hex (rgba, transparent, etc.)
     * are returned unchanged.
     */
    private function normalizeColor(string $value): string
    {
        $v = trim($value);
        if ($v !== '' && $v[0] !== '#' && preg_match('/^[0-9a-fA-F]{3}$|^[0-9a-fA-F]{6}$/', $v)) {
            return '#' . $v;
        }
        return $v;
    }

    /**
     * Returns a color that is safe to place inside an inline style block.
     * Settings are editable by delegated CP users, so HTML escaping alone is
     * insufficient here: a value containing `</style>` would leave CSS
     * context entirely. Keep the accepted syntax deliberately small and fall
     * back to the shipped default for anything malformed.
     */
    private function safeCssColor(string $value, string $fallback): string
    {
        $value = $this->normalizeColor($value);

        // Bounded for the same reason the column is: `[a-z]+` and the
        // functional-notation branch below are both otherwise unlimited, so
        // without this the accepted syntax and the storage width could not be
        // made to agree.
        if (mb_strlen($value) > self::COLOR_MAX_LENGTH) {
            return $fallback;
        }

        if (preg_match('/^(#[0-9a-f]{3,8}|[a-z]+)$/i', $value)) {
            return $value;
        }

        if (preg_match('/^(rgb|rgba|hsl|hsla)\([0-9.,%\s+\/-]+\)$/i', $value)) {
            return $value;
        }

        return $fallback;
    }

    /** Safe subset used by the four configurable layout dimensions. */
    private function safeCssLength(string $value, string $fallback): string
    {
        $value = trim($value);

        return preg_match('/^(0|(?:\d+(?:\.\d+)?)(?:px|rem|em|%|vh|vw|vmin|vmax))$/i', $value)
            ? $value
            : $fallback;
    }

    /**
     * A front-end-safe privacy-policy URL. Relative URLs are supported; only
     * http(s) and mailto are accepted when a scheme is present.
     */
    public function getSafePrivacyPolicyUrl(): string
    {
        $url = trim($this->privacyPolicyUrl);
        if ($url === '' || preg_match('/[\x00-\x20<>]/', $url)) {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return $scheme === null || in_array(strtolower((string) $scheme), ['http', 'https', 'mailto'], true)
            ? $url
            : '';
    }

    /**
     * Serialises all colour and layout settings as a CSS custom-properties block.
     * Rendered as an inline <style> in the banner template.
     */
    public function getCssVars(): string
    {
        $defaults = new self();
        $color = fn(string $value, string $field): string => $this->safeCssColor($value, $defaults->$field);
        $length = fn(string $value, string $field): string => $this->safeCssLength($value, $defaults->$field);

        $shadow = $this->shadow
            ? '0 4px 24px rgba(0,0,0,0.12), 0 1px 6px rgba(0,0,0,0.08)'
            : 'none';

        $vars = [
            '--cck-banner-bg'       => $color($this->bannerBgColor, 'bannerBgColor'),
            '--cck-banner-border'   => $color($this->bannerBorderColor, 'bannerBorderColor'),
            '--cck-overlay'         => $color($this->overlayColor, 'overlayColor'),
            '--cck-heading-color'   => $color($this->headingColor, 'headingColor'),
            '--cck-desc-color'      => $color($this->descriptionColor, 'descriptionColor'),
            '--cck-link-color'      => $color($this->linkColor, 'linkColor'),

            '--cck-accept-bg'           => $color($this->acceptBgColor, 'acceptBgColor'),
            '--cck-accept-text'         => $color($this->acceptTextColor, 'acceptTextColor'),
            '--cck-accept-border'       => $color($this->acceptBorderColor, 'acceptBorderColor'),
            '--cck-accept-hover-bg'     => $color($this->acceptHoverBgColor, 'acceptHoverBgColor'),
            '--cck-accept-hover-text'   => $color($this->acceptHoverTextColor, 'acceptHoverTextColor'),

            '--cck-reject-bg'           => $color($this->rejectBgColor, 'rejectBgColor'),
            '--cck-reject-text'         => $color($this->rejectTextColor, 'rejectTextColor'),
            '--cck-reject-border'       => $color($this->rejectBorderColor, 'rejectBorderColor'),
            '--cck-reject-hover-bg'     => $color($this->rejectHoverBgColor, 'rejectHoverBgColor'),
            '--cck-reject-hover-text'   => $color($this->rejectHoverTextColor, 'rejectHoverTextColor'),

            '--cck-customize-bg'           => $color($this->customizeBgColor, 'customizeBgColor'),
            '--cck-customize-text'         => $color($this->customizeTextColor, 'customizeTextColor'),
            '--cck-customize-border'       => $color($this->customizeBorderColor, 'customizeBorderColor'),
            '--cck-customize-hover-bg'     => $color($this->customizeHoverBgColor, 'customizeHoverBgColor'),
            '--cck-customize-hover-text'   => $color($this->customizeHoverTextColor, 'customizeHoverTextColor'),

            '--cck-save-bg'     => $color($this->saveBgColor, 'saveBgColor'),
            '--cck-save-text'   => $color($this->saveTextColor, 'saveTextColor'),
            '--cck-close-color' => $color($this->closeIconColor, 'closeIconColor'),

            '--cck-radius'    => $length($this->borderRadius, 'borderRadius'),
            '--cck-padding'   => $length($this->padding, 'padding'),
            '--cck-max-width' => $this->fullWidth ? '100%' : $length($this->maxWidth, 'maxWidth'),
            '--cck-max-height'=> $length($this->maxHeight, 'maxHeight'),
            '--cck-shadow'    => $shadow,
        ];

        $lines = [];
        foreach ($vars as $prop => $value) {
            $lines[] = "  {$prop}: {$value};";
        }

        return ':root {' . "\n" . implode("\n", $lines) . "\n" . '}';
    }

    // Validation
    public function rules(): array
    {
        return [
            [
                [
                    'bannerEnabled', 'geoEnabled', 'logEnabled', 'fullWidth', 'shadow', 'fixedPosition',
                    'consentModeEnabled', 'consentModeAutoInject',
                    'consentModeUrlPassthrough', 'consentModeAdsDataRedaction',
                    'respectGpc', 'respectDnt',
                ],
                'boolean',
            ],
            [['consentModeType'], 'in', 'range' => ['advanced', 'basic']],
            [['consentModeWaitForUpdate'], 'integer', 'min' => 0, 'max' => 10000],
            [['bannerLayout'], 'in', 'range' => ['bottom-bar', 'top-bar', 'center-popup', 'corner-popup']],
            [['cornerPosition'], 'in', 'range' => ['bottom-left', 'bottom-right']],
            [
                [
                    'bannerHeading', 'bannerDescription', 'privacyPolicyUrl', 'privacyPolicyLinkText',
                    'acceptButtonText', 'rejectButtonText', 'customizeButtonText',
                    'savePreferencesText', 'closeButtonText',
                    'borderRadius', 'padding', 'maxWidth', 'maxHeight',
                ],
                'string',
            ],
            // Bounded to the storage width, so an over-long colour is refused
            // on the settings screen with a message naming the field, rather
            // than passing validation and failing at INSERT.
            [self::COLOR_FIELDS, 'string', 'max' => self::COLOR_MAX_LENGTH],
            [['categories', 'cookies', 'geoTargetCountries', 'siteOverrides'], 'safe'],
            [['logRetentionDays', 'consentExpiryDays'], 'integer', 'min' => 0],
            [['logoAssetId'], 'integer'],
            [['policyVersion'], 'string', 'max' => 50],
        ];
    }
}
