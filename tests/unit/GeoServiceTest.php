<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\geo\GeoProviderInterface;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\services\GeoService;

/**
 * Geo resolution and targeting.
 *
 * The property that matters most here is the direction of failure. Showing a
 * banner to someone who did not strictly need one is harmless; suppressing one
 * for someone who did is the outcome with real consequences. So every
 * unresolvable case must resolve to "show".
 */
final class GeoServiceTest extends TestCase
{
    private function settings(bool $enabled, array $countries): Settings
    {
        $settings = new Settings();
        $settings->geoEnabled = $enabled;
        $settings->geoTargetCountries = $countries;

        return $settings;
    }

    private function providerReturning(?string $code): GeoProviderInterface
    {
        return new class ($code) implements GeoProviderInterface {
            public function __construct(private ?string $code)
            {
            }

            public function getCountryCode(): ?string
            {
                return $this->code;
            }
        };
    }

    public function testGeoDisabledAlwaysShowsBanner(): void
    {
        $service = new GeoService();
        $service->setProviders([$this->providerReturning('US')]);

        self::assertTrue($service->shouldShowBanner($this->settings(false, ['GB'])));
    }

    public function testEmptyTargetListAlwaysShowsBanner(): void
    {
        $service = new GeoService();
        $service->setProviders([$this->providerReturning('US')]);

        self::assertTrue($service->shouldShowBanner($this->settings(true, [])));
    }

    public function testTargetedCountryShowsBanner(): void
    {
        $service = new GeoService();
        $service->setProviders([$this->providerReturning('GB')]);

        self::assertTrue($service->shouldShowBanner($this->settings(true, ['GB', 'DE'])));
    }

    public function testUntargetedCountryHidesBanner(): void
    {
        $service = new GeoService();
        $service->setProviders([$this->providerReturning('US')]);

        self::assertFalse($service->shouldShowBanner($this->settings(true, ['GB', 'DE'])));
    }

    /** Unresolvable country must fail open. */
    public function testUnresolvableCountryShowsBanner(): void
    {
        $service = new GeoService();
        $service->setProviders([$this->providerReturning(null)]);

        self::assertTrue($service->shouldShowBanner($this->settings(true, ['GB'])));
    }

    /** No providers at all is still a fail-open case, not an error. */
    public function testNoProvidersShowsBanner(): void
    {
        $service = new GeoService();
        $service->setProviders([]);

        self::assertTrue($service->shouldShowBanner($this->settings(true, ['GB'])));
    }

    public function testFirstNonNullProviderWins(): void
    {
        $service = new GeoService();
        $service->setProviders([
            $this->providerReturning(null),
            $this->providerReturning('DE'),
            $this->providerReturning('FR'),
        ]);

        self::assertSame('DE', $service->getCountryCode());
    }

    /**
     * A provider that throws (a timed-out lookup, a bad API key) must not take
     * the page down, and must not stop a later provider from answering.
     */
    public function testThrowingProviderIsSkipped(): void
    {
        $throwing = new class implements GeoProviderInterface {
            public function getCountryCode(): ?string
            {
                throw new \RuntimeException('lookup failed');
            }
        };

        $service = new GeoService();
        $service->setProviders([$throwing, $this->providerReturning('ES')]);

        self::assertSame('ES', $service->getCountryCode());
    }

    public function testCountryMatchingIsCaseInsensitive(): void
    {
        $service = new GeoService();

        self::assertTrue($service->isTargeted($this->settings(true, ['gb']), 'GB'));
        self::assertTrue($service->isTargeted($this->settings(true, ['GB']), 'gb'));
    }
}
