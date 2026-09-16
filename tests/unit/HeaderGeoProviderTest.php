<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use Craft;
use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\geo\HeaderGeoProvider;

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
    /**
     * Swaps in a web request carrying the given headers. The suite's bootstrap
     * creates a console application, whose request object has no headers at all.
     *
     * @param array<string, string> $headers
     */
    private function withHeaders(array $headers): HeaderGeoProvider
    {
        $request = new \yii\web\Request();

        foreach ($headers as $name => $value) {
            $request->getHeaders()->set($name, $value);
        }

        Craft::$app->set('request', $request);

        return new HeaderGeoProvider();
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
}
