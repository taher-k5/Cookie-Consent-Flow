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

    /**
     * The same holds for a provider that fails while it is being built — its
     * lookup database missing, an error in its init() — and for one that is
     * misconfigured: skipped, the next provider answers, nothing escapes to
     * the consent save that asked, and the banner still fails open.
     */
    public function testProvidersThatFailToInitialiseAreSkipped(): void
    {
        $service = new GeoService();
        $service->setProviders([
            ConstructorThrowingGeoProvider::class,
            InitErroringGeoProvider::class,
            '\\sfsinfotech\\DoesNotExist\\GeoProvider',
            ['class' => \stdClass::class],
            GermanyGeoProvider::class,
        ]);

        self::assertSame('DE', $service->getCountryCode());
        self::assertTrue($service->hasTrustedSource());
    }

    public function testWhenEveryProviderFailsToInitialiseTheBannerIsShown(): void
    {
        $service = new GeoService();
        $service->setProviders([ConstructorThrowingGeoProvider::class, InitErroringGeoProvider::class]);

        self::assertNull($service->getCountryCode());
        self::assertTrue($service->shouldShowBanner($this->settings(true, ['DE'])));
    }

    public function testCountryMatchingIsCaseInsensitive(): void
    {
        $service = new GeoService();

        self::assertTrue($service->isTargeted($this->settings(true, ['gb']), 'GB'));
        self::assertTrue($service->isTargeted($this->settings(true, ['GB']), 'gb'));
    }

    /**
     * A custom provider's malformed answer is not a country. It used to be
     * passed through, matching no target (so the banner was hidden and
     * optional content ran) and failing every consent INSERT.
     */
    public function testMalformedProviderCountriesAreIgnoredAndFallThrough(): void
    {
        foreach (['GBR', 'United Kingdom', 'G', '12', '<b>', 'gb-x'] as $bad) {
            $service = new GeoService();
            $service->setProviders([$this->providerReturning($bad), $this->providerReturning('de')]);

            self::assertSame('DE', $service->getCountryCode(), "{$bad} must be skipped");
        }

        $service = new GeoService();
        $service->setProviders([$this->providerReturning('GBR')]);
        self::assertNull($service->getCountryCode());
        self::assertTrue($service->shouldShowBanner($this->settings(true, ['GB'])), 'fails open');
    }
}

/** A provider whose dependency is missing: its constructor throws. */
final class ConstructorThrowingGeoProvider implements GeoProviderInterface
{
    public function __construct()
    {
        throw new \RuntimeException('GeoIP database not found');
    }

    public function getCountryCode(): ?string
    {
        return 'US';
    }
}

/** A provider with a programming error in init(). */
final class InitErroringGeoProvider extends \yii\base\BaseObject implements GeoProviderInterface
{
    public function init(): void
    {
        parent::init();
        strlen([]);
    }

    public function getCountryCode(): ?string
    {
        return 'US';
    }
}

final class GermanyGeoProvider implements GeoProviderInterface
{
    public function getCountryCode(): ?string
    {
        return 'DE';
    }
}
