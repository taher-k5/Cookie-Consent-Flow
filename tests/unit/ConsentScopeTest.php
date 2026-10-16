<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentScope;
use sfsinfotech\craftcookieconsentflow\models\Settings;

/**
 * Which sites share a consent decision.
 *
 * The default scope has to reproduce the names used before the setting
 * existed exactly — `cck_consent_<siteId>` and `cck_visitor_<siteId>` —
 * because those are what every visitor's browser already holds. Anything
 * else would silently ask every visitor of every site again on upgrade.
 */
final class ConsentScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        ConsentScope::clearCache();
    }

    public function testThePerSiteScopeKeepsThePreviousNames(): void
    {
        $key   = ConsentScope::defaultKey(Settings::CONSENT_SCOPE_SITE, 3, 1);
        $scope = new ConsentScope($key, [3]);

        self::assertSame('3', $key);
        self::assertSame('cck_consent_3', $scope->storageKey());
        self::assertSame('cck_visitor_3', $scope->visitorCookieName());
        self::assertSame(ConsentHelper::visitorCookieName(3), $scope->visitorCookieName(), 'integer callers keep their cookie');
    }

    public function testTheGroupScopeIsKeyedByGroup(): void
    {
        self::assertSame('g2', ConsentScope::defaultKey(Settings::CONSENT_SCOPE_GROUP, 3, 2));
        self::assertSame('cck_visitor_g2', (new ConsentScope('g2', [3, 4]))->visitorCookieName());
    }

    /** A group key can never collide with a site key. */
    public function testGroupAndSiteKeysAreDistinct(): void
    {
        self::assertNotSame(
            ConsentScope::defaultKey(Settings::CONSENT_SCOPE_SITE, 2, 2),
            ConsentScope::defaultKey(Settings::CONSENT_SCOPE_GROUP, 2, 2)
        );
    }

    public function testASiteWithoutAGroupKeepsItsOwnScope(): void
    {
        self::assertSame('5', ConsentScope::defaultKey(Settings::CONSENT_SCOPE_GROUP, 5, null));
        self::assertSame('5', ConsentScope::defaultKey(Settings::CONSENT_SCOPE_GROUP, 5, 0));
    }

    public function testAnUnknownScopeValueFallsBackToPerSite(): void
    {
        self::assertSame('5', ConsentScope::defaultKey('anything', 5, 2));
    }

    public function testSitesWithTheSameKeyShareAScope(): void
    {
        $scopes = ConsentScope::fromKeys([1 => 'g1', 2 => 'g1', 3 => '3', 4 => 'g4']);

        self::assertSame([1, 2], $scopes[1]->siteIds);
        self::assertSame([1, 2], $scopes[2]->siteIds);
        self::assertTrue($scopes[1]->isShared());

        self::assertSame([3], $scopes[3]->siteIds);
        self::assertFalse($scopes[3]->isShared());

        // A group of one site shares nothing, and needs no offered-category check.
        self::assertFalse($scopes[4]->isShared());
    }

    /**
     * A decision under the key the other setting would use is from before
     * the setting last changed, and must not count again if it changes back.
     */
    public function testStaleKeysAreTheOtherSettingsKeys(): void
    {
        $perSite = ConsentScope::fromKeys([1 => '1', 3 => '3'], [1 => 2, 3 => 2]);
        self::assertSame(['g2'], $perSite[1]->staleKeys);
        self::assertSame(['g2'], $perSite[3]->staleKeys);

        $grouped = ConsentScope::fromKeys([1 => 'g2', 3 => 'g2'], [1 => 2, 3 => 2]);
        self::assertSame(['1'], $grouped[1]->staleKeys);
        self::assertSame(['3'], $grouped[3]->staleKeys);
    }

    /** A key some site uses now is never stale, whatever a listener did. */
    public function testAKeyInUseIsNeverStale(): void
    {
        // A listener named site 3's scope "1"; site 1's own decision is live.
        $scopes = ConsentScope::fromKeys([1 => 'g2', 3 => '1'], [1 => 2, 3 => 2]);

        self::assertSame([], $scopes[1]->staleKeys, "'1' is site 3's live key");
        self::assertSame(['3'], $scopes[3]->staleKeys, "'g2' is site 1's live key");
    }

    /**
     * Resolution must never take a page down: without Craft's sites service
     * (as here), a site gets its own scope — the per-site behaviour.
     */
    public function testResolutionFailsSafeToThePerSiteScope(): void
    {
        $scope = ConsentScope::forSite(7);

        self::assertSame('7', $scope->key);
        self::assertSame([7], $scope->siteIds);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function keyProvider(): array
    {
        return [
            'site id'           => ['12', true],
            'group'             => ['g3', true],
            'custom'            => ['brand_hr-en', true],
            '64 characters'     => [str_repeat('a', 64), true],
            'empty'             => ['', false],
            '65 characters'     => [str_repeat('a', 65), false],
            'cookie separator'  => ['a;b', false],
            'equals'            => ['a=b', false],
            'space'             => ['a b', false],
            'trailing newline'  => ["g1\n", false],
            'non-ascii'         => ['hr-č', false],
        ];
    }

    /**
     * The key ends up in a cookie name, a storage key and a regular
     * expression in the `<head>` replay; a listener's key is held to this.
     *
     * @dataProvider keyProvider
     */
    public function testKeyPattern(string $key, bool $valid): void
    {
        self::assertSame($valid, (bool) preg_match(ConsentScope::KEY_PATTERN, $key));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function originProvider(): array
    {
        return [
            'https'              => ['https://Example.com/en/', 'https://example.com'],
            'default port'       => ['https://example.com:443/', 'https://example.com'],
            'http default port'  => ['http://example.com:80/de', 'http://example.com'],
            'other port'         => ['https://example.com:8443/', 'https://example.com:8443'],
            'subdomain'          => ['https://en.example.com/', 'https://en.example.com'],
            'relative'           => ['/en/', null],
            'empty'              => ['', null],
            'unset'              => [null, null],
        ];
    }

    /** @dataProvider originProvider */
    public function testOrigin(?string $url, ?string $origin): void
    {
        self::assertSame($origin, ConsentScope::origin($url));
    }

    /** Paths on one domain are one origin, so a path-per-language group can share. */
    public function testLanguagePathsShareAnOrigin(): void
    {
        self::assertSame(
            ConsentScope::origin('https://example.com/'),
            ConsentScope::origin('https://example.com/en/')
        );
    }

    public function testTheSettingIsValidated(): void
    {
        $settings = new Settings();
        self::assertSame(Settings::CONSENT_SCOPE_SITE, $settings->consentScope, 'per site by default');

        foreach ([Settings::CONSENT_SCOPE_SITE, Settings::CONSENT_SCOPE_GROUP] as $value) {
            $settings->consentScope = $value;
            self::assertTrue($settings->validate(['consentScope']), $value);
        }

        $settings->consentScope = 'everything';
        self::assertFalse($settings->validate(['consentScope']));
    }
}
