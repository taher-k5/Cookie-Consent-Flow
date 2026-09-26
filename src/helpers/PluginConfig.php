<?php

namespace sfsinfotech\craftcookieconsentflow\helpers;

use Craft;

/**
 * Deployment configuration from `config/cookie-consent-flow.php`.
 *
 * Everything an editor manages — banner copy, categories, colours, geo
 * countries, retention days — lives in the plugin's own tables and is edited
 * in the control panel, per site. What lives here is the small set of facts
 * about the *deployment* that an editor cannot know and should not be able to
 * change from a browser: which request header the site's CDN writes the
 * visitor's country into, and whether expired records may be purged by
 * Craft's garbage collection.
 *
 * The file used to be read by nothing at all — the plugin's settings model is
 * database-backed, so Craft's usual "config file overrides settings" route did
 * not apply — which left a config file that looked valid and silently did
 * nothing. Unknown keys are now reported in the Craft log instead, naming the
 * control panel as the place those settings are managed.
 *
 * Multi-environment arrays (`'*' => [...], 'production' => [...]`) work, as
 * for any Craft config file.
 *
 * ```php
 * // config/cookie-consent-flow.php
 * return [
 *     // The header your CDN sets and strips from client requests.
 *     'geoCountryHeader' => 'CF-IPCountry',
 *
 *     // Purge records older than the retention period during Craft's
 *     // garbage collection (`php craft gc`, and probabilistically on web
 *     // requests), in batches.
 *     'automaticRetention' => true,
 * ];
 * ```
 */
final class PluginConfig
{
    /** The config file's name under `config/`, without the extension. */
    public const FILE = 'cookie-consent-flow';

    /**
     * Every supported key and its default.
     *
     * - `geoCountryHeader` (string|string[]|null): the request header(s) a
     *   proxy you control writes the visitor's ISO country code into. Null
     *   means none is trusted, and geo-targeting then shows the banner to
     *   everyone, which is the fail-open direction.
     * - `automaticRetention` (bool): whether Craft's garbage collection purges
     *   consent records older than the configured retention period.
     * - `retentionBatchSize` (int): rows deleted per statement when purging,
     *   so a large purge never holds one long-running lock.
     */
    public const DEFAULTS = [
        'geoCountryHeader'   => null,
        'automaticRetention' => false,
        'retentionBatchSize' => 1000,
    ];

    /** @var array<string, mixed>|null */
    private static ?array $_resolved = null;

    /** @var array<string, mixed>|null Test/override values; see override(). */
    private static ?array $_override = null;

    /** One configured value, or its default. */
    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    /**
     * The resolved configuration: defaults, then the file's values, with any
     * key the file sets that this plugin does not support reported once.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$_override !== null) {
            return array_merge(self::DEFAULTS, self::$_override);
        }

        if (self::$_resolved !== null) {
            return self::$_resolved;
        }

        $file = self::_readFile();

        $unknown = array_diff(array_keys($file), array_keys(self::DEFAULTS));

        if ($unknown !== []) {
            Craft::warning(
                'config/' . self::FILE . '.php sets unsupported key(s) ' . implode(', ', $unknown)
                . '. Banner, category, cookie, geo-country, logging and Consent Mode settings are stored in the'
                . ' database and edited in the control panel; this file only accepts: '
                . implode(', ', array_keys(self::DEFAULTS)) . '.',
                __METHOD__
            );
        }

        return self::$_resolved = array_merge(self::DEFAULTS, array_intersect_key($file, self::DEFAULTS));
    }

    /**
     * The trusted country header names, normalised to a list. Empty when none
     * is configured.
     *
     * @return string[]
     */
    public static function geoCountryHeaders(): array
    {
        $value = self::get('geoCountryHeader');
        $names = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(static fn($name): string => trim((string) $name), $names),
            static fn(string $name): bool => (bool) preg_match('/^[A-Za-z0-9-]{1,100}$/D', $name)
        ));
    }

    /**
     * Whether automatic retention is on. A real boolean — `'false'`, `'no'`
     * and `'0'` (from an environment variable, say) are off. Plain
     * truthiness read those strings as "on" and purged consent evidence.
     */
    public static function automaticRetention(): bool
    {
        return filter_var(self::get('automaticRetention'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /** Positive batch size for retention deletes, clamped to something sane. */
    public static function retentionBatchSize(): int
    {
        return max(1, min(50000, (int) self::get('retentionBatchSize')));
    }

    /**
     * Replaces the configuration for the rest of the request — for tests, and
     * for code that needs to evaluate a hypothetical configuration. Null
     * restores the file.
     *
     * @param array<string, mixed>|null $values
     */
    public static function override(?array $values): void
    {
        self::$_override = $values;
        self::$_resolved = null;
    }

    /** @return array<string, mixed> */
    private static function _readFile(): array
    {
        $app = Craft::$app;

        if (!$app || !$app->has('config') || !method_exists($app, 'getConfig')) {
            return [];
        }

        try {
            $values = $app->getConfig()->getConfigFromFile(self::FILE);
        } catch (\Throwable $e) {
            Craft::error('Could not read config/' . self::FILE . '.php: ' . $e->getMessage(), __METHOD__);

            return [];
        }

        return is_array($values) ? $values : [];
    }
}
