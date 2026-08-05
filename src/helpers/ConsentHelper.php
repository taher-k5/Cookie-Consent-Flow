<?php

namespace sfsinfotech\craftcookieconsentflow\helpers;

use Craft;
use sfsinfotech\craftcookieconsentflow\Plugin;

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
        return Craft::t('cookie-consent-flow', ucfirst($category));
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

    /**
     * Legacy, unnamespaced visitor cookie name from before multi-site
     * support — kept as a one-time fallback/migration source so upgrading
     * an existing install doesn't immediately forget known visitors.
     */
    public const LEGACY_VISITOR_COOKIE = 'cck_visitor';

    /**
     * Returns the per-site visitor cookie name. Namespacing by site ID
     * prevents a visitor identifier (and therefore their stored consent
     * lookup) from bleeding across separate Craft sites sharing one origin.
     */
    public static function visitorCookieName(int $siteId): string
    {
        return self::LEGACY_VISITOR_COOKIE . '_' . $siteId;
    }

    /**
     * Resolves a CP site switcher's `site` query param (accepted as either
     * a numeric site ID or a site handle) to a `craft\models\Site`, falling
     * back to the primary site whenever the param is absent, unresolvable,
     * or points at a site that no longer exists. Shared by every CP
     * controller page (Dashboard, Logs) that lets an admin pick which
     * site's data/preview to view — never throws for a stale/invalid value.
     */
    public static function resolveSiteFromParam(mixed $param): \craft\models\Site
    {
        $sitesService = Craft::$app->getSites();

        if ($param !== null && $param !== '') {
            $site = ctype_digit((string) $param)
                ? $sitesService->getSiteById((int) $param)
                : $sitesService->getSiteByHandle((string) $param);

            if ($site !== null) {
                return $site;
            }
        }

        return $sitesService->getPrimarySite();
    }
}
