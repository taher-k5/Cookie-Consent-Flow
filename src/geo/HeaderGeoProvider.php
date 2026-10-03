<?php

namespace sfsinfotech\craftcookieconsentflow\geo;

use Craft;
use sfsinfotech\craftcookieconsentflow\helpers\PluginConfig;
use yii\base\BaseObject;

/**
 * Default provider: reads a country code a CDN or reverse proxy has already
 * resolved and put in a request header. Zero dependencies, zero latency, and
 * correct on the hosts that actually set these (Cloudflare, Fastly, Akamai,
 * CloudFront, most managed Craft hosts).
 *
 * ## Trust boundary
 *
 * A request header is whatever the client sent unless something you control
 * overwrote it. This provider used to read a list of well-known header names
 * by default — `CF-IPCountry`, `X-Country-Code`, `X-Geo-Country`,
 * `CloudFront-Viewer-Country` — from every request, so on a site behind
 * Cloudflare a visitor could send `X-Country-Code: US` (a header Cloudflare
 * does not touch) and be treated as American. With geo-targeting on, a
 * visitor outside the target countries has optional content activated
 * without being asked, so the header decided whether a visitor was asked
 * for consent at all, and the forged value was stored as the record's
 * country.
 *
 * So **no header is trusted until you name it.** Configure the one your edge
 * sets (and strips from client requests) in `config/cookie-consent-flow.php`:
 *
 * ```php
 * return ['geoCountryHeader' => 'CF-IPCountry'];
 * ```
 *
 * or on the provider itself via the geo component's `providers` config
 * (`['class' => HeaderGeoProvider::class, 'headers' => ['CF-IPCountry']]`),
 * which takes precedence. With neither, every visitor's country is unknown,
 * which shows the banner — geo-targeting fails open, never closed.
 *
 * Only name a header that your proxy always overwrites. If requests can reach
 * the origin without passing through it, restrict the origin to the proxy's
 * addresses; otherwise the header is still the client's to choose.
 */
class HeaderGeoProvider extends BaseObject implements GeoProviderInterface
{
    /**
     * Header names that carry a trusted country code, in priority order.
     * Empty (the default) defers to `geoCountryHeader` in the plugin config
     * file; see the class docblock.
     *
     * @var string[]
     */
    public array $headers = [];

    /**
     * Placeholder values these headers use for "unknown" — treated as no
     * answer rather than as a country. 'XX'/'T1' are Cloudflare's (unknown,
     * and Tor exit node respectively).
     *
     * @var string[]
     */
    public array $unknownValues = ['XX', 'T1', 'ZZ'];

    /**
     * The headers this provider will actually read.
     *
     * @return string[]
     */
    public function trustedHeaders(): array
    {
        return $this->headers !== [] ? array_values($this->headers) : PluginConfig::geoCountryHeaders();
    }

    public function getCountryCode(): ?string
    {
        $headers = $this->trustedHeaders();

        if ($headers === []) {
            return null;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof \yii\web\Request) {
            return null;
        }

        $requestHeaders = $request->getHeaders();

        foreach ($headers as $header) {
            $value = strtoupper(trim((string) $requestHeaders->get($header, '')));

            if ($value === '' || in_array($value, $this->unknownValues, true)) {
                continue;
            }

            // Anything that isn't a bare alpha-2 code is a misconfigured or
            // spoofed header, not a country — ignore rather than pass junk on.
            if (preg_match('/^[A-Z]{2}$/D', $value)) {
                return $value;
            }
        }

        return null;
    }
}
