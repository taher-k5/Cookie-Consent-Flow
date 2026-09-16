<?php

namespace sfsinfotech\craftcookieconsentflow\geo;

use Craft;
use yii\base\BaseObject;

/**
 * Default provider: reads a country code a CDN or reverse proxy has already
 * resolved and put in a request header. Zero dependencies, zero latency, and
 * correct on the hosts that actually set these (Cloudflare, Fastly, Akamai,
 * most managed Craft hosts).
 *
 * Header names are configurable because there is no standard one; the
 * defaults cover Cloudflare and the generic convention most proxies use.
 *
 * **Trust boundary:** these headers are only trustworthy when a proxy you
 * control sets them and strips any client-supplied copy. If requests can
 * reach the app without passing that proxy, a visitor can set the header
 * themselves. Geo-targeting in this plugin only ever decides whether to
 * *show* a banner and fails open, so a spoofed header cannot grant consent
 * or suppress a banner that would otherwise appear for a targeted country —
 * but do not build stricter logic on top of it without a real lookup.
 */
class HeaderGeoProvider extends BaseObject implements GeoProviderInterface
{
    /**
     * Request headers to read, in priority order.
     *
     * @var string[]
     */
    public array $headers = ['CF-IPCountry', 'X-Country-Code', 'X-Geo-Country', 'CloudFront-Viewer-Country'];

    /**
     * Placeholder values these headers use for "unknown" — treated as no
     * answer rather than as a country. 'XX'/'T1' are Cloudflare's (unknown,
     * and Tor exit node respectively).
     *
     * @var string[]
     */
    public array $unknownValues = ['XX', 'T1', 'ZZ'];

    public function getCountryCode(): ?string
    {
        $requestHeaders = Craft::$app->getRequest()->getHeaders();

        foreach ($this->headers as $header) {
            $value = strtoupper(trim((string) $requestHeaders->get($header, '')));

            if ($value === '' || in_array($value, $this->unknownValues, true)) {
                continue;
            }

            // Anything that isn't a bare alpha-2 code is a misconfigured or
            // spoofed header, not a country — ignore rather than pass junk on.
            if (preg_match('/^[A-Z]{2}$/', $value)) {
                return $value;
            }
        }

        return null;
    }
}
