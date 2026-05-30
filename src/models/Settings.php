<?php

namespace sfsinfotech\craftcookieconsentflow\models;

use craft\base\Model;

/**
 * Cookie Consent Flow – settings model.
 *
 * Settings are persisted via Craft's built-in plugin-settings mechanism
 * (JSON blob in craft_plugins.settings). All colour/layout values are
 * used as CSS custom-property values in the frontend banner.
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
