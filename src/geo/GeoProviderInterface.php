<?php

namespace sfsinfotech\craftcookieconsentflow\geo;

/**
 * Resolves the current request's country.
 *
 * Providers are consulted in order and the first non-null answer wins, so a
 * provider that cannot answer must return null rather than guessing. Keeping
 * this behind an interface is what lets a site add a MaxMind/ipapi/whatever
 * lookup without the plugin depending on any commercial provider — see
 * GeoService::setProviders().
 */
interface GeoProviderInterface
{
    /**
     * Returns an ISO 3166-1 alpha-2 country code in upper case, or null if
     * this provider cannot determine the country for the current request.
     */
    public function getCountryCode(): ?string;
}
