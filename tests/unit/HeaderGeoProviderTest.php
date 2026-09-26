<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use Craft;
use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\geo\HeaderGeoProvider;
use sfsinfotech\craftcookieconsentflow\helpers\PluginConfig;

/**
 * Header-based country resolution.
 *
 * These headers are attacker-controllable on any host that does not strip a
 * client-supplied copy at the edge, so the provider must never pass a value
 * through unvalidated — whatever it returns is compared against the site's
 * target-country list, and a junk value there would produce a junk decision.
 */
final class HeaderGeoProviderTest extends TestCase
{
    /** The pair most of the tests below trust, as a site behind two proxies might. */
    private const TRUSTED = ['CF-IPCountry', 'X-Country-Code'];

    protected function tearDown(): void
    {
        PluginConfig::override(null);
    }

    /**
     * Swaps in a web request carrying the given headers. The suite's bootstrap
     * creates a console application, whose request object has no headers at all.
     *
     * @param array<string, string> $headers
     * @param string[]|null         $trusted Headers configured as trusted on the
     *                                       provider; null configures none.
     */
    private function withHeaders(array $headers, ?array $trusted = self::TRUSTED): HeaderGeoProvider
    {
        $request = new \yii\web\Request();

        foreach ($headers as $name => $value) {
            $request->getHeaders()->set($name, $value);
        }

        Craft::$app->set('request', $request);

        return new HeaderGeoProvider($trusted !== null ? ['headers' => $trusted] : []);
    }

    public function testReadsCloudflareHeader(): void
    {
        self::assertSame('GB', $this->withHeaders(['CF-IPCountry' => 'GB'])->getCountryCode());
    }

    public function testUppercasesLowercaseValues(): void
    {
        self::assertSame('DE', $this->withHeaders(['CF-IPCountry' => 'de'])->getCountryCode());
    }

    public function testFallsBackToGenericProxyHeader(): void
    {
        self::assertSame('FR', $this->withHeaders(['X-Country-Code' => 'FR'])->getCountryCode());
    }

    public function testEarlierHeaderWins(): void
    {
        self::assertSame(
            'GB',
            $this->withHeaders(['CF-IPCountry' => 'GB', 'X-Country-Code' => 'FR'])->getCountryCode()
        );
    }

    /**
     * Cloudflare's placeholders for "unknown" and "Tor exit node" are not
     * countries and must not be treated as one.
     */
    public function testCloudflarePlaceholdersAreTreatedAsUnknown(): void
    {
        self::assertNull($this->withHeaders(['CF-IPCountry' => 'XX'])->getCountryCode());
        self::assertNull($this->withHeaders(['CF-IPCountry' => 'T1'])->getCountryCode());
    }

    public function testNoHeadersResolvesToNull(): void
    {
        self::assertNull($this->withHeaders([])->getCountryCode());
    }

    /**
     * Anything that isn't a bare two-letter code is malformed or injected.
     * It is discarded rather than forwarded to the targeting comparison.
     */
    public function testMalformedValuesAreRejected(): void
    {
        foreach (['UNITED KINGDOM', 'G', 'GBR', '<script>', 'GB,FR', '1'] as $value) {
            self::assertNull(
                $this->withHeaders(['CF-IPCountry' => $value])->getCountryCode(),
                "Malformed header value {$value} should be rejected"
            );
        }
    }

    /** A malformed first header must not mask a valid later one. */
    public function testFallsThroughPastAMalformedHeader(): void
    {
        self::assertSame(
            'ES',
            $this->withHeaders(['CF-IPCountry' => 'INVALID', 'X-Country-Code' => 'ES'])->getCountryCode()
        );
    }

    // -------------------------------------------------------------------------
    // M2 — only a header the install names is believed
    // -------------------------------------------------------------------------

    /**
     * The regression. Out of the box every one of the well-known names was
     * read, so a visitor behind Cloudflare could send `X-Country-Code: US`
     * — a header Cloudflare does not overwrite — and be treated as outside
     * the target countries.
     */
    public function testNoHeaderIsTrustedUntilOneIsConfigured(): void
    {
        foreach (['CF-IPCountry', 'X-Country-Code', 'X-Geo-Country', 'CloudFront-Viewer-Country'] as $header) {
            self::assertNull(
                $this->withHeaders([$header => 'US'], null)->getCountryCode(),
                "{$header} must not be believed without configuration"
            );
        }
    }

    public function testConfiguredHeaderFromThePluginConfigIsRead(): void
    {
        PluginConfig::override(['geoCountryHeader' => 'CF-IPCountry']);

        self::assertSame('GB', $this->withHeaders(['CF-IPCountry' => 'GB'], null)->getCountryCode());
    }

    /** A spoofed header of another name is ignored when the configured one is present… */
    public function testSpoofedUnrelatedHeaderIsIgnored(): void
    {
        PluginConfig::override(['geoCountryHeader' => 'CF-IPCountry']);

        self::assertSame(
            'DE',
            $this->withHeaders(['CF-IPCountry' => 'DE', 'X-Country-Code' => 'US'], null)->getCountryCode()
        );
    }

    /** …and when it is absent: the forged value is not a fallback. */
    public function testWrongHeaderAloneResolvesToUnknown(): void
    {
        PluginConfig::override(['geoCountryHeader' => 'CF-IPCountry']);

        self::assertNull($this->withHeaders(['X-Country-Code' => 'US'], null)->getCountryCode());
    }

    public function testMissingConfiguredHeaderResolvesToUnknown(): void
    {
        PluginConfig::override(['geoCountryHeader' => 'CF-IPCountry']);

        self::assertNull($this->withHeaders([], null)->getCountryCode());
    }

    public function testUnknownValueFromConfiguredHeaderResolvesToUnknown(): void
    {
        PluginConfig::override(['geoCountryHeader' => ['CloudFront-Viewer-Country']]);

        self::assertNull($this->withHeaders(['CloudFront-Viewer-Country' => 'ZZ'], null)->getCountryCode());
    }

    /** Provider-level configuration wins over the file, so a custom chain is explicit. */
    public function testProviderHeadersTakePrecedenceOverTheConfigFile(): void
    {
        PluginConfig::override(['geoCountryHeader' => 'CF-IPCountry']);

        self::assertSame(
            'FR',
            $this->withHeaders(['CF-IPCountry' => 'DE', 'X-Geo' => 'FR'], ['X-Geo'])->getCountryCode()
        );
    }

    /** Header names are validated; a nonsense entry cannot become a lookup. */
    public function testInvalidConfiguredHeaderNamesAreDropped(): void
    {
        PluginConfig::override(['geoCountryHeader' => ['CF-IPCountry', "Bad Header\r\n", '']]);

        self::assertSame(['CF-IPCountry'], PluginConfig::geoCountryHeaders());
    }

    /** Outside a web request (console, queue) there is no country and no error. */
    public function testConsoleRequestResolvesToUnknown(): void
    {
        Craft::$app->set('request', new \yii\console\Request());

        self::assertNull((new HeaderGeoProvider(['headers' => ['CF-IPCountry']]))->getCountryCode());
    }
}
