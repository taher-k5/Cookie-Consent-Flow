<?php

namespace sfsinfotech\craftcookieconsentkit\models;

use craft\base\Model;

/**
 * Cookie Consent Kit settings model.
 *
 * All settings are stored in the database (not project config) so that
 * they can be managed per-site without affecting the project.yaml.
 */
class Settings extends Model
{
    // -------------------------------------------------------------------------
    // Banner settings
    // -------------------------------------------------------------------------

    /** Whether the cookie banner is enabled. */
    public bool $bannerEnabled = true;

    /** Position of the banner on screen: 'bottom', 'top', 'bottom-left', 'bottom-right'. */
    public string $bannerPosition = 'bottom';

    /** Custom banner heading text (translatable via Craft's t() helper in templates). */
    public string $bannerHeading = '';

    /** Custom banner body text. */
    public string $bannerBody = '';

    // -------------------------------------------------------------------------
    // Consent categories
    // -------------------------------------------------------------------------

    /**
     * Enabled consent categories as an array of identifiers.
     * e.g. ['necessary', 'analytics', 'marketing', 'preferences']
     */
    public array $categories = ['necessary', 'analytics', 'marketing', 'preferences'];

    // -------------------------------------------------------------------------
    // Geo-targeting
    // -------------------------------------------------------------------------

    /** Whether geo-targeting is enabled. */
    public bool $geoEnabled = false;

    /**
     * ISO 3166-1 alpha-2 country codes or region codes that trigger the banner.
     * Empty array = show to everyone.
     */
    public array $geoTargetCountries = [];

    // -------------------------------------------------------------------------
    // Consent logging
    // -------------------------------------------------------------------------

    /** Whether to store consent logs in the database. */
    public bool $logEnabled = true;

    /** How many days to retain consent log records. 0 = keep forever. */
    public int $logRetentionDays = 365;

    // -------------------------------------------------------------------------
    // Multi-site
    // -------------------------------------------------------------------------

    /**
     * Per-site settings overrides, keyed by Craft site ID.
     * Each entry may override any of the top-level settings above.
     * @var array<int, array<string, mixed>>
     */
    public array $siteOverrides = [];

    // -------------------------------------------------------------------------
    // Validation rules
    // -------------------------------------------------------------------------

    public function rules(): array
    {
        return [
            [['bannerEnabled', 'geoEnabled', 'logEnabled'], 'boolean'],
            [['bannerPosition'], 'string'],
            [['bannerHeading', 'bannerBody'], 'string'],
            [['categories', 'geoTargetCountries', 'siteOverrides'], 'each', 'rule' => ['safe']],
            [['logRetentionDays'], 'integer', 'min' => 0],
            [['bannerPosition'], 'in', 'range' => ['bottom', 'top', 'bottom-left', 'bottom-right']],
        ];
    }
}
