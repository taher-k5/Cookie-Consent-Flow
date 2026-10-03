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
     * A pseudonymous, keyed hash of the network a consent decision came from.
     * The raw IP is never stored, and neither is the full address in any
     * reversible form.
     *
     * ## What the column is for
     *
     * Consent evidence sometimes has to answer "did these decisions come from
     * the same place?" — a burst of automated submissions, or a data-subject
     * request that supplies an address. The previous design hashed the full
     * IP with a fresh random salt per record, which made every value unique:
     * it could answer nothing, not even for the site's own administrator, and
     * the column was noise.
     *
     * ## The design now
     *
     * 1. The address is first **truncated** to its network — IPv4 to /24,
     *    IPv6 to /48 — the same generalisation commonly used for analytics
     *    anonymisation. What is hashed can no longer single out one device.
     * 2. It is then hashed with **HMAC-SHA256** under a key derived from the
     *    install's security key. Without that key the value cannot be
     *    recomputed or reversed; with it, the site can check whether an
     *    address it is given matches, which is the one question the column
     *    exists to answer.
     *
     * Values are stable per install, so records from the same network
     * correlate. Records written by earlier versions keep their old,
     * uncorrelatable values; nothing is rewritten.
     *
     * An empty or unparseable address (no request context, e.g. a queue job
     * or a command) still produces a valid value rather than erroring,
     * because the column is NOT NULL and a consent record with no IP context
     * is still a valid record of consent.
     *
     * @param string|null $key Hash key; defaults to one derived from the install secret.
     */
    public static function hashIp(string $ip, ?string $key = null): string
    {
        $key ??= hash_hmac('sha256', 'cookie-consent-flow:ip-hash', self::installSecret());

        return hash_hmac('sha256', self::anonymizeIp($ip), $key);
    }

    /**
     * The network portion of an address: IPv4 with the last octet zeroed
     * (/24), IPv6 with everything after the first three hextets zeroed (/48).
     * Returns '' for anything that is not an IP address.
     */
    public static function anonymizeIp(string $ip): string
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts    = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts) . '/24';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);

            if ($packed === false) {
                return '';
            }

            $network = substr($packed, 0, 6) . str_repeat("\0", 10);

            return inet_ntop($network) . '/48';
        }

        return '';
    }

    /**
     * The install's secret, for keyed hashes that must be stable per install
     * and unguessable without it. Craft's `securityKey`; outside a Craft
     * application (unit tests) a fixed placeholder, since there is no install
     * to be secret about.
     */
    public static function installSecret(): string
    {
        $app = Craft::$app;

        if ($app && method_exists($app, 'getConfig')) {
            return (string) $app->getConfig()->getGeneral()->securityKey;
        }

        return 'cookie-consent-flow-no-install';
    }

    /**
     * JSON that is safe to place inside an HTML `<script>` element, whether
     * executable or a `type="application/json"` data block.
     *
     * Craft's `Json::encode()` escapes `/`, so `</script>` cannot close the
     * element early — but it leaves `<` alone, and `<!--` followed by
     * `<script` puts the HTML parser into its "double-escaped" script state,
     * which swallows the next `</script>`: an admin-editable value such as the
     * policy version could stop the banner loading on every page. `<`, `>`,
     * `&`, `'` and `"` are all emitted as `\u00XX` escapes, which JSON parsers
     * read back as the same characters and the HTML parser never sees.
     */
    public static function jsonForHtml(mixed $value): string
    {
        return \craft\helpers\Json::encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * Prefix of the per-site visitor cookie (`cck_visitor_<siteId>`).
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
