<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use Craft;
use craft\base\Component;
use sfsinfotech\craftcookieconsentflow\geo\GeoProviderInterface;
use sfsinfotech\craftcookieconsentflow\geo\HeaderGeoProvider;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use yii\base\InvalidConfigException;

/**
 * Geo Service — resolves the visitor's country for geo-targeted banner display.
 *
 * Resolution is delegated to an ordered list of {@see GeoProviderInterface}
 * implementations; the first non-null answer wins. Out of the box that is
 * just {@see HeaderGeoProvider} (CDN/proxy headers), which needs no external
 * service and no dependency. A site that needs a real lookup adds its own
 * provider rather than the plugin taking a dependency on any one vendor:
 *
 * ```php
 * // config/app.php
 * 'components' => [
 *     'plugins' => [
 *         'pluginConfigs' => [
 *             'cookie-consent-flow' => [
 *                 'components' => [
 *                     'geo' => [
 *                         'class' => GeoService::class,
 *                         'providers' => [MyMaxMindProvider::class, HeaderGeoProvider::class],
 *                     ],
 *                 ],
 *             ],
 *         ],
 *     ],
 * ],
 * ```
 *
 * Every path here fails **open**: an unresolvable country means the banner is
 * shown. Suppressing a consent banner because a lookup failed is the one
 * outcome with real legal downside, so it is never the failure mode.
 */
class GeoService extends Component
{
    /**
     * Provider classes or configuration arrays, in priority order.
     * Normalised to instances on first use.
     *
     * @var array<int, class-string<GeoProviderInterface>|array<string, mixed>|GeoProviderInterface>
     */
    public array $providers = [HeaderGeoProvider::class];

    /** @var GeoProviderInterface[]|null Lazily instantiated providers. */
    private ?array $_resolvedProviders = null;

    /**
     * Per-request memo of the resolved country. `false` distinguishes
     * "resolved to nothing" from "not looked up yet", so a failed lookup
     * isn't retried on every call within one request.
     */
    private string|false|null $_countryCode = false;

    /**
     * Replaces the provider chain at runtime.
     *
     * @param array<int, class-string<GeoProviderInterface>|array<string, mixed>|GeoProviderInterface> $providers
     */
    public function setProviders(array $providers): void
    {
        $this->providers          = $providers;
        $this->_resolvedProviders = null;
        $this->_countryCode       = false;
    }

    /**
     * Returns the ISO 3166-1 alpha-2 country code for the current request,
     * or null if no provider could determine it.
     */
    public function getCountryCode(): ?string
    {
        if ($this->_countryCode !== false) {
            return $this->_countryCode;
        }

        foreach ($this->_providers() as $provider) {
            try {
                $code = $provider->getCountryCode();
            } catch (\Throwable $e) {
                // A provider that throws (network timeout, bad API key) must
                // not take the page down with it — fall through to the next
                // one, and ultimately to "unknown", which fails open.
                Craft::warning(
                    'Cookie Consent Flow geo provider ' . $provider::class . ' failed: ' . $e->getMessage(),
                    __METHOD__
                );
                continue;
            }

            if ($code !== null && $code !== '') {
                return $this->_countryCode = strtoupper($code);
            }
        }

        return $this->_countryCode = null;
    }

    /**
     * Returns true if the banner should be shown based on the visitor's
     * country and the site's geo-targeting settings.
     *
     * Takes already-resolved (global + site-override merged) settings rather
     * than resolving them itself, so per-site geo overrides are enforced —
     * every caller has effective settings in scope already.
     *
     * - Geo disabled → show.
     * - Geo enabled, no target countries → show.
     * - Geo enabled, country unresolvable → show (fail open).
     * - Geo enabled, country resolved → show only if it's in the target list.
     *
     * Note this is the *server-side* answer, used by the Twig API and by the
     * CP preview. When geo-targeting is on, the front-end runtime re-resolves
     * the country per visitor so the decision is never baked into cached HTML
     * — see `ConsentController::actionGeo()`.
     */
    public function shouldShowBanner(Settings $settings): bool
    {
        if (!$settings->geoEnabled || empty($settings->geoTargetCountries)) {
            return true;
        }

        $countryCode = $this->getCountryCode();

        if ($countryCode === null) {
            return true;
        }

        return $this->isTargeted($settings, $countryCode);
    }

    /** Whether a given country code is in a site's target list. */
    public function isTargeted(Settings $settings, string $countryCode): bool
    {
        return in_array(
            strtoupper($countryCode),
            array_map('strtoupper', $settings->geoTargetCountries),
            true
        );
    }

    /**
     * @return GeoProviderInterface[]
     */
    private function _providers(): array
    {
        if ($this->_resolvedProviders !== null) {
            return $this->_resolvedProviders;
        }

        $resolved = [];

        foreach ($this->providers as $provider) {
            if ($provider instanceof GeoProviderInterface) {
                $resolved[] = $provider;
                continue;
            }

            try {
                $instance = Craft::createObject($provider);
            } catch (InvalidConfigException $e) {
                Craft::warning(
                    'Cookie Consent Flow could not instantiate geo provider: ' . $e->getMessage(),
                    __METHOD__
                );
                continue;
            }

            if ($instance instanceof GeoProviderInterface) {
                $resolved[] = $instance;
            } else {
                Craft::warning(
                    'Cookie Consent Flow geo provider ' . get_debug_type($instance)
                    . ' does not implement GeoProviderInterface; ignoring.',
                    __METHOD__
                );
            }
        }

        return $this->_resolvedProviders = $resolved;
    }
}
