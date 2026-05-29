<?php

namespace sfsinfotech\craftcookieconsentkit\helpers;

use Craft;
use sfsinfotech\craftcookieconsentkit\Plugin;

/**
 * Consent Helper — static utility methods shared across services and templates.
 *
 * Keeping pure-static helpers here prevents circular service dependencies.
 */
class ConsentHelper
{
    /**
     * Returns the default list of consent category identifiers.
     *
     * @return string[]
     */
    public static function defaultCategories(): array
    {
        return ['necessary', 'analytics', 'marketing', 'preferences'];
    }

    /**
     * Returns a human-readable label for a category identifier.
     * Falls back to the raw identifier if no translation is found.
     */
    public static function categoryLabel(string $category): string
    {
        return Craft::t('cookie-consent-kit', ucfirst($category));
    }

    /**
     * Generates an anonymous visitor UUID to store in a first-party cookie.
     * Uses PHP's built-in random_bytes for cryptographic randomness.
     */
    public static function generateVisitorUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0x0fff, 0x4fff),
            random_int(0x8000, 0xbfff),
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }

    /**
     * Returns a one-way hash of an IP address for privacy-safe logging.
     * The raw IP is never stored.
     */
    public static function hashIp(string $ip): string
    {
        return hash('sha256', $ip . Craft::$app->getSecurity()->generateRandomString(8));
    }
}
