<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\Plugin;

/**
 * The runtime's endpoint URLs must reach the site the page belongs to, and
 * otherwise be exactly the URL Craft's `actionUrl()` builds — same host,
 * scheme, subfolder and script-name format — so a root site's requests are
 * unchanged from 1.0.0.
 */
final class SiteActionUrlTest extends TestCase
{
    private const ACTION = 'actions/cookie-consent-flow/consent/save';

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: string}>
     */
    public static function basePathProvider(): array
    {
        return [
            'root site'                    => ['https://example.com/', '', ''],
            'root site, no slash'          => ['https://example.com', '', ''],
            'language path'                => ['https://example.com/uk/', '', 'uk'],
            'nested path'                  => ['https://example.com/eu/de/', '', 'eu/de'],
            'subfolder install, root site' => ['https://example.com/sub/', '/sub', ''],
            'subfolder install, path site' => ['https://example.com/sub/uk/', '/sub', 'uk'],
            'not under the install'        => ['https://example.com/other/uk/', '/sub', ''],
            'similar prefix'               => ['https://example.com/subway/', '/sub', ''],
            'no base URL'                  => [null, '', ''],
            'relative base URL'            => ['/uk/', '', 'uk'],
            'unsafe characters'            => ['https://example.com/a b/', '', ''],
        ];
    }

    /** @dataProvider basePathProvider */
    public function testSiteBasePath(?string $siteBaseUrl, ?string $requestBaseUrl, string $expected): void
    {
        self::assertSame($expected, Plugin::siteBasePath($siteBaseUrl, $requestBaseUrl));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function urlProvider(): array
    {
        return [
            'path URLs'               => ['https://example.com/actions/cookie-consent-flow/consent/save', 'uk', 'https://example.com/uk/actions/cookie-consent-flow/consent/save'],
            'script-name URLs'        => ['https://example.com/index.php?p=actions/cookie-consent-flow/consent/save', 'uk', 'https://example.com/index.php?p=uk/actions/cookie-consent-flow/consent/save'],
            'subfolder, script name'  => ['https://example.com/sub/index.php?p=actions/cookie-consent-flow/consent/save', 'uk', 'https://example.com/sub/index.php?p=uk/actions/cookie-consent-flow/consent/save'],
            'http kept http'          => ['http://example.com/actions/cookie-consent-flow/consent/save', 'uk', 'http://example.com/uk/actions/cookie-consent-flow/consent/save'],
            'root site unchanged'     => ['https://example.com/index.php?p=actions/cookie-consent-flow/consent/save', '', 'https://example.com/index.php?p=actions/cookie-consent-flow/consent/save'],
        ];
    }

    /** @dataProvider urlProvider */
    public function testTheSitePathGoesInFrontOfTheActionOnly(string $actionUrl, string $sitePath, string $expected): void
    {
        self::assertSame($expected, Plugin::insertSitePath($actionUrl, self::ACTION, $sitePath));
    }

    public function testAnUnrecognisedUrlIsLeftAlone(): void
    {
        $url = 'https://example.com/something-else';

        self::assertSame($url, Plugin::insertSitePath($url, self::ACTION, 'uk'));
    }
}
