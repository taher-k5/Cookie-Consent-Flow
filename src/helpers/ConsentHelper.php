<?php

namespace sfsinfotech\craftcookieconsentflow\helpers;

use Craft;

/**
 * Consent Helper — static utility methods shared across services and templates.
 *
 * Keeping pure-static helpers here prevents circular service dependencies.
 */
class ConsentHelper
{
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
     *
     * The salt is fresh randomness per call, which makes the result
     * non-reversible **and** non-correlatable: two records from the same IP
     * hash differently. That is deliberate — the column is a token that an IP
     * was present, not a means of grouping or identifying a visitor — and it
     * is why the value is useless for deduplication or abuse analysis.
     *
     * An empty address (no request context, e.g. a queue job or a command)
     * still produces a valid hash rather than erroring, because the column is
     * NOT NULL and a consent record with no IP context is still a valid record
     * of consent.
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
