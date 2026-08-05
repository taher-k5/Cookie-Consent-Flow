<?php

namespace sfsinfotech\craftcookieconsentflow\models;

use craft\base\Model;
use craft\helpers\HtmlPurifier;

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
        ],
        [
            'key'         => 'analytics',
            'label'       => 'Analytics',
            'description' => 'Help us understand how visitors interact with our website.',
            'default'     => false,
            'locked'      => false,
        ],
        [
            'key'         => 'marketing',
            'label'       => 'Marketing',
            'description' => 'Used to deliver personalised advertisements relevant to you.',
            'default'     => false,
            'locked'      => false,
        ],
        [
            'key'         => 'preferences',
            'label'       => 'Preferences',
            'description' => 'Allow the website to remember choices you make (language, region, etc.).',
            'default'     => false,
            'locked'      => false,
        ],
    ];

    // Geo-targeting
    public bool  $geoEnabled         = false;
    public array $geoTargetCountries = [];

    // Consent logging
    public bool $logEnabled       = true;
    public int  $logRetentionDays = 365;

    // Multi-site overrides
    /** @var array<int, array<string, mixed>> Keyed by Craft site ID. */
    public array $siteOverrides = [];

    /**
     * Fields a site is allowed to override. Consent logging is intentionally
     * excluded — it's an operational/compliance setting for the whole
     * install, not per-site branding.
     */
    public const OVERRIDABLE_FIELDS = [
        'bannerEnabled', 'bannerLayout', 'cornerPosition',
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
        'categories',
        'geoEnabled', 'geoTargetCountries',
    ];

    /**
     * Field metadata for the Multi Site Override UI, grouped for display.
     * Drives a single reusable override-field partial instead of
     * hand-writing a field per site per property.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function getOverrideFieldGroups(): array
    {
        return [
            'General' => [
                ['key' => 'bannerEnabled', 'type' => 'lightswitch', 'label' => 'Enable Cookie Banner'],
                ['key' => 'bannerLayout', 'type' => 'select', 'label' => 'Banner Layout', 'options' => [
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
                ['key' => 'geoTargetCountries', 'type' => 'text', 'label' => 'Target Countries',
                    'instructions' => 'Comma-separated ISO 3166-1 alpha-2 codes, e.g. GB,DE,FR.'],
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
     * Returns a field's current value for display in the override form:
     * the CSV-joined form for geoTargetCountries, unchanged otherwise.
     */
    public function getFieldDisplayValue(string $field): mixed
    {
        if ($field === 'geoTargetCountries') {
            return implode(', ', $this->geoTargetCountries);
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
     * Whether ANY field in a settings group currently has a site-specific
     * value stored. Drives the single group-level "Use Global Settings"
     * toggle on the Multi Site Override page — the toggle's own on/off
     * state doesn't correspond to a single stored field, so it's derived
     * from the fields it controls instead.
     *
     * @param string[] $fieldKeys
     */
    public function isGroupOverridden(int $siteId, array $fieldKeys): bool
    {
        foreach ($fieldKeys as $field) {
            if ($this->isFieldOverridden($siteId, $field)) {
                return true;
            }
        }

        return false;
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
     * Serialises all colour and layout settings as a CSS custom-properties block.
     * Rendered as an inline <style> in the banner template.
     */
    public function getCssVars(): string
    {
        $nc = fn(string $v): string => $this->normalizeColor($v);

        $shadow = $this->shadow
            ? '0 4px 24px rgba(0,0,0,0.12), 0 1px 6px rgba(0,0,0,0.08)'
            : 'none';

        $vars = [
            '--cck-banner-bg'       => $nc($this->bannerBgColor),
            '--cck-banner-border'   => $nc($this->bannerBorderColor),
            '--cck-overlay'         => $nc($this->overlayColor),
            '--cck-heading-color'   => $nc($this->headingColor),
            '--cck-desc-color'      => $nc($this->descriptionColor),
            '--cck-link-color'      => $nc($this->linkColor),

            '--cck-accept-bg'           => $nc($this->acceptBgColor),
            '--cck-accept-text'         => $nc($this->acceptTextColor),
            '--cck-accept-border'       => $nc($this->acceptBorderColor),
            '--cck-accept-hover-bg'     => $nc($this->acceptHoverBgColor),
            '--cck-accept-hover-text'   => $nc($this->acceptHoverTextColor),

            '--cck-reject-bg'           => $nc($this->rejectBgColor),
            '--cck-reject-text'         => $nc($this->rejectTextColor),
            '--cck-reject-border'       => $nc($this->rejectBorderColor),
            '--cck-reject-hover-bg'     => $nc($this->rejectHoverBgColor),
            '--cck-reject-hover-text'   => $nc($this->rejectHoverTextColor),

            '--cck-customize-bg'           => $nc($this->customizeBgColor),
            '--cck-customize-text'         => $nc($this->customizeTextColor),
            '--cck-customize-border'       => $nc($this->customizeBorderColor),
            '--cck-customize-hover-bg'     => $nc($this->customizeHoverBgColor),
            '--cck-customize-hover-text'   => $nc($this->customizeHoverTextColor),

            '--cck-save-bg'     => $nc($this->saveBgColor),
            '--cck-save-text'   => $nc($this->saveTextColor),
            '--cck-close-color' => $nc($this->closeIconColor),

            '--cck-radius'    => $this->borderRadius,
            '--cck-padding'   => $this->padding,
            '--cck-max-width' => $this->fullWidth ? '100%' : $this->maxWidth,
            '--cck-max-height'=> $this->maxHeight ?: '90vh',
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
            [['bannerEnabled', 'geoEnabled', 'logEnabled', 'fullWidth', 'shadow', 'fixedPosition'], 'boolean'],
            [['bannerLayout'], 'in', 'range' => ['bottom-bar', 'top-bar', 'center-popup', 'corner-popup']],
            [['cornerPosition'], 'in', 'range' => ['bottom-left', 'bottom-right']],
            [
                [
                    'bannerHeading', 'bannerDescription', 'privacyPolicyUrl', 'privacyPolicyLinkText',
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
                    'borderRadius', 'padding', 'maxWidth',
                ],
                'string',
            ],
            [['categories', 'geoTargetCountries', 'siteOverrides'], 'safe'],
            [['logRetentionDays'], 'integer', 'min' => 0],
        ];
    }
}
