<?php

namespace sfsinfotech\craftcookieconsentkit\services;

use craft\base\Component;

/**
 * Geo Service — resolves visitor location for geo-targeted consent banner display.
 *
 * TODO: Implement geo-lookup logic in future iterations.
 */
class GeoService extends Component
{
    /**
     * Returns the ISO 3166-1 alpha-2 country code for the current request,
     * or null if it cannot be determined.
     */
    public function getCountryCode(): ?string
    {
        // TODO: integrate with a GeoIP database / API (e.g. MaxMind GeoLite2,
        // Cloudflare CF-IPCountry header, or a third-party service).
        return null;
    }

    /**
     * Returns true if the banner should be shown based on the visitor's country
     * and the plugin's geo-targeting settings.
     */
    public function shouldShowBanner(): bool
    {
        // TODO: compare getCountryCode() against Settings::$geoTargetCountries
        return true;
    }
}
