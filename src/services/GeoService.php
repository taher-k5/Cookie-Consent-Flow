<?php

namespace sfsinfotech\craftcookieconsentkit\services;

use Craft;
use craft\base\Component;
use sfsinfotech\craftcookieconsentkit\Plugin;

/**
 * Geo Service — resolves visitor location for geo-targeted banner display.
 *
 * Resolution order:
 *   1. Cloudflare CF-IPCountry header (fastest, zero-dependency)
 *   2. X-Country-Code header (set by reverse-proxies / CDN rules)
 *   3. null — geo-lookup unavailable; banner defaults to visible.
 */
class GeoService extends Component
{
    /**
     * Returns the ISO 3166-1 alpha-2 country code for the current request,
     * or null if it cannot be determined.
     */
    public function getCountryCode(): ?string
    {
        $headers = Craft::$app->getRequest()->getHeaders();

        // Cloudflare
        $cf = $headers->get('CF-IPCountry');
        if ($cf && $cf !== 'XX' && $cf !== 'T1') {
            return strtoupper($cf);
        }

        // Generic proxy header
        $xcc = $headers->get('X-Country-Code');
        if ($xcc) {
            return strtoupper($xcc);
        }

        return null;
    }

    /**
     * Returns true if the banner should be shown based on the visitor's
     * country and the plugin's geo-targeting settings.
     *
     * - Geo disabled → always show.
     * - Geo enabled + empty target list → always show.
     * - Geo enabled + target list + country not resolvable → always show (fail open).
     * - Geo enabled + country resolved → show only if country is in target list.
     */
    public function shouldShowBanner(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->geoEnabled || empty($settings->geoTargetCountries)) {
            return true;
        }

        $countryCode = $this->getCountryCode();

        if ($countryCode === null) {
            return true; // Fail open — do not suppress banner if country unknown.
        }

        return in_array(strtoupper($countryCode), array_map('strtoupper', $settings->geoTargetCountries), true);
    }
}

