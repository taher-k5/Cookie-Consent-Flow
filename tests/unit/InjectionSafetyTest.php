<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\web\Response;

/**
 * What the response hook may touch, and what it writes when it does.
 *
 * - M7: only HTML pages. A JSON, GraphQL, XML or RSS body that happens to
 *   contain `</body>` in rich text must reach its consumer byte for byte.
 * - H1: the runtime script at most once per page, however it got there.
 * - L8: nothing an admin can type into a setting can end the configuration
 *   element or throw the HTML parser into a state that swallows the runtime.
 */
final class InjectionSafetyTest extends TestCase
{
    private const PAGE = '<!doctype html><html><head></head><body><p>Hi</p></body></html>';

    private function response(string $content, ?string $contentType, string $format = Response::FORMAT_HTML, int $status = 200): Response
    {
        $response          = new Response();
        $response->format  = $format;
        $response->content = $content;
        $response->setStatusCode($status);

        if ($contentType !== null) {
            $response->getHeaders()->set('content-type', $contentType);
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // M7 — HTML responses only
    // -------------------------------------------------------------------------

    public function testHtmlPageIsInjectable(): void
    {
        self::assertTrue(Plugin::isInjectableResponse($this->response(self::PAGE, 'text/html; charset=UTF-8')));
    }

    public function testXhtmlPageIsInjectable(): void
    {
        self::assertTrue(Plugin::isInjectableResponse($this->response(self::PAGE, 'application/xhtml+xml')));
    }

    /** A rendered Craft template before its type header is known still counts. */
    public function testTemplateFormatWithoutAContentTypeIsInjectable(): void
    {
        self::assertTrue(Plugin::isInjectableResponse($this->response(self::PAGE, null, 'template')));
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: string}>
     */
    public static function nonHtmlProvider(): array
    {
        $richText = '{"body":"<p>Article</p></body>"}';

        return [
            'JSON (Element API, asJson)' => [$richText, 'application/json; charset=UTF-8', Response::FORMAT_JSON],
            'GraphQL'                    => ['{"data":{"entry":{"html":"<div></body></div>"}}}', 'application/graphql-response+json', Response::FORMAT_JSON],
            'XML sitemap'                => ['<?xml version="1.0"?><urlset><loc>x</body></loc></urlset>', 'application/xml', Response::FORMAT_RAW],
            'RSS feed'                   => ['<rss><item><description>&lt;/body&gt;</body></description></item></rss>', 'application/rss+xml', Response::FORMAT_RAW],
            'Atom feed'                  => ['<feed><content></body></content></feed>', 'application/atom+xml', Response::FORMAT_RAW],
            'plain text'                 => ['Example </body> text', 'text/plain', Response::FORMAT_RAW],
            'JavaScript'                 => ['var x = "</body>";', 'application/javascript', Response::FORMAT_RAW],
            'JSON format, no header yet' => [$richText, null, Response::FORMAT_JSON],
            'raw format, no header'      => [self::PAGE, null, Response::FORMAT_RAW],
        ];
    }

    /**
     * @dataProvider nonHtmlProvider
     */
    public function testNonHtmlResponsesAreNeverTouched(string $content, ?string $type, string $format): void
    {
        self::assertFalse(Plugin::isInjectableResponse($this->response($content, $type, $format)));
    }

    public function testRedirectIsNotInjectable(): void
    {
        self::assertFalse(Plugin::isInjectableResponse($this->response(self::PAGE, 'text/html', Response::FORMAT_HTML, 302)));
    }

    public function testErrorAndNoContentResponsesAreNotInjectable(): void
    {
        foreach ([204, 404, 500] as $status) {
            self::assertFalse(
                Plugin::isInjectableResponse($this->response(self::PAGE, 'text/html', Response::FORMAT_HTML, $status)),
                "status {$status}"
            );
        }
    }

    public function testEmptyResponseIsNotInjectable(): void
    {
        self::assertFalse(Plugin::isInjectableResponse($this->response('', 'text/html')));
    }

    public function testHtmlWithoutAClosingBodyIsNotInjectable(): void
    {
        self::assertFalse(Plugin::isInjectableResponse($this->response('<html><p>fragment</p>', 'text/html')));
    }

    public function testDownloadStreamIsNotInjectable(): void
    {
        $response         = $this->response(self::PAGE, 'text/html');
        $response->stream = fopen('php://memory', 'rb');

        self::assertFalse(Plugin::isInjectableResponse($response));
    }

    // -------------------------------------------------------------------------
    // H1 — one runtime per page
    // -------------------------------------------------------------------------

    public function testFullInjectionContainsExactlyOneRuntimeAndOneStylesheet(): void
    {
        $html = Plugin::renderInjection('<div id="cck-banner"></div>', ['siteId' => 1], '/cpresources/x/cookie-banner.css', '/cpresources/x/cookie-banner.js');

        self::assertSame(1, substr_count($html, 'cookie-banner.js'));
        self::assertSame(1, substr_count($html, 'cookie-banner.css'));
        self::assertSame(1, substr_count($html, 'id="cck-config"'));
    }

    /**
     * The regression: a page whose template had already registered the asset
     * bundle (the preferences button used to) received a second script tag
     * from auto-injection.
     */
    public function testAPageThatAlreadyLoadsTheRuntimeGetsNoSecondCopy(): void
    {
        // What Craft renders for the registered asset bundle, marker included.
        $rendered = '<html><body><footer><button data-cck-action="open-preferences">Prefs</button></footer>'
            . '<script src="/cpresources/abc123/cookie-banner.js?v=1727000000" data-cck-runtime></script></body></html>';

        self::assertTrue(Plugin::runtimeAlreadyIncluded($rendered));

        $html = Plugin::renderInjection('<div id="cck-banner"></div>', [], null, null);

        self::assertStringNotContainsString('<script src=', $html);
        self::assertStringContainsString('id="cck-config"', $html, 'the configuration still has to reach the runtime');
    }

    public function testInjectedTagsCarryTheMarker(): void
    {
        $html = Plugin::renderInjection('', [], '/x/cookie-banner.css', '/x/cookie-banner.js');

        self::assertTrue(Plugin::runtimeAlreadyIncluded($html));
        self::assertTrue(Plugin::stylesheetAlreadyIncluded($html));
    }

    public function testDetectionMatchesTheWaysTheMarkedTagIsWritten(): void
    {
        foreach ([
            "<script src='/assets/cookie-banner.js' data-cck-runtime></script>",
            '<SCRIPT defer SRC="https://cdn.test/x/cookie-banner.js" DATA-CCK-RUNTIME></SCRIPT>',
            '<script data-cck-runtime="1" src="/x.js?v=2"></script>',
        ] as $tag) {
            self::assertTrue(Plugin::runtimeAlreadyIncluded("<body>{$tag}</body>"), $tag);
        }
    }

    /**
     * Final audit: a theme's own file called cookie-banner.js is not the
     * runtime. Matching by file name suppressed the real runtime, so the
     * banner never appeared and visitors had no way to consent.
     */
    public function testAnUnrelatedFileOfTheSameNameIsNotTheRuntime(): void
    {
        foreach ([
            '<script src="/theme/js/cookie-banner.js"></script>',
            '<p>Our cookie-banner.js is great</p>',
            '<link rel="preload" href="/cookie-banner.js">',
        ] as $html) {
            self::assertFalse(Plugin::runtimeAlreadyIncluded("<body>{$html}</body>"), $html);
        }
    }

    public function testStylesheetDetection(): void
    {
        self::assertTrue(Plugin::stylesheetAlreadyIncluded('<link rel="stylesheet" href="/x/cookie-banner.css?v=3" data-cck-runtime>'));
        self::assertFalse(Plugin::stylesheetAlreadyIncluded('<link rel="stylesheet" href="/theme/cookie-banner.css">'));
    }

    // -------------------------------------------------------------------------
    // Final audit — the Consent Mode snippet never precedes the doctype
    // -------------------------------------------------------------------------

    public function testHeadInsertionAfterTheHeadTag(): void
    {
        self::assertSame("<!doctype html><html><head>\nX</head>", Plugin::insertIntoHead('<!doctype html><html><head></head>', 'X'));
    }

    /** HTML5 allows omitting <head>; a script before the doctype forces quirks mode. */
    public function testHeadInsertionWithoutAHeadTagStaysAfterTheDoctype(): void
    {
        self::assertSame("<!DOCTYPE html><html lang=\"en\">\nX<title>t</title>", Plugin::insertIntoHead('<!DOCTYPE html><html lang="en"><title>t</title>', 'X'));
        self::assertSame("<!DOCTYPE html>\nX<title>t</title>", Plugin::insertIntoHead('<!DOCTYPE html><title>t</title>', 'X'));
    }

    // -------------------------------------------------------------------------
    // L8 — configuration JSON is inert whatever it contains
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileValueProvider(): array
    {
        return [
            'closing script'         => ['</script><script>alert(1)</script>'],
            'double-escape trigger'  => ['<!--<script>'],
            'comment open'           => ['<!--'],
            'uppercase closing tag'  => ['</SCRIPT >'],
            'already-escaped form'   => ['</script>'],
            'ampersand entity'       => ['&lt;/script&gt;'],
            'quotes'                 => ['"\' onload="x'],
        ];
    }

    /**
     * @dataProvider hostileValueProvider
     */
    public function testConfigBlockCannotBeBrokenOutOf(string $value): void
    {
        $block = Plugin::renderConfigBlock(['policyVersion' => $value, 'siteId' => 1]);

        $inner = substr($block, strlen('<script type="application/json" id="cck-config">'), -strlen('</script>'));

        self::assertStringNotContainsString('<', $inner, 'no tag can begin inside the element');
        self::assertStringNotContainsString('>', $inner);
        self::assertSame(1, substr_count(strtolower($block), '</script'), 'only the element\'s own closing tag');

        self::assertSame($value, json_decode($inner, true)['policyVersion'], 'and the value reads back unchanged');
    }

    public function testJsonForHtmlKeepsUnicodeReadable(): void
    {
        self::assertSame('{"label":"Préférences 🍪"}', ConsentHelper::jsonForHtml(['label' => 'Préférences 🍪']));
    }

    // -------------------------------------------------------------------------
    // L28 — the CP navigation follows the user's permissions
    // -------------------------------------------------------------------------

    public function testNoPermissionMeansNoNavigation(): void
    {
        self::assertSame([], Plugin::subnavFor(false, false, true));
    }

    public function testRecordsOnlyUserSeesDashboardAndRecords(): void
    {
        self::assertSame(['dashboard', 'logs'], array_keys(Plugin::subnavFor(false, true, true)));
    }

    public function testConfigurationOnlyUserDoesNotSeeRecords(): void
    {
        self::assertSame(
            ['dashboard', 'banner', 'cookies', 'multi-site-override', 'settings'],
            array_keys(Plugin::subnavFor(true, false, true))
        );
    }

    public function testFullAccessOnSingleSiteHasNoMultisiteItem(): void
    {
        self::assertSame(
            ['dashboard', 'banner', 'cookies', 'logs', 'settings'],
            array_keys(Plugin::subnavFor(true, true, false))
        );
    }

    // -------------------------------------------------------------------------
    // L13 — the IP hash can answer the one question it exists for
    // -------------------------------------------------------------------------

    public function testSameAddressHashesTheSameUnderOneKey(): void
    {
        self::assertSame(
            ConsentHelper::hashIp('198.51.100.7', 'k'),
            ConsentHelper::hashIp('198.51.100.7', 'k'),
            'a record can be matched against a supplied address'
        );
    }

    public function testTheHashIsOfTheNetworkNotTheDevice(): void
    {
        self::assertSame(ConsentHelper::hashIp('198.51.100.7', 'k'), ConsentHelper::hashIp('198.51.100.250', 'k'));
        self::assertNotSame(ConsentHelper::hashIp('198.51.100.7', 'k'), ConsentHelper::hashIp('198.51.101.7', 'k'));

        self::assertSame(ConsentHelper::hashIp('2001:db8:1::1', 'k'), ConsentHelper::hashIp('2001:db8:1:ffff::9', 'k'));
        self::assertNotSame(ConsentHelper::hashIp('2001:db8:1::1', 'k'), ConsentHelper::hashIp('2001:db8:2::1', 'k'));
    }

    public function testWithoutTheKeyTheValueCannotBeRecomputed(): void
    {
        self::assertNotSame(ConsentHelper::hashIp('198.51.100.7', 'install-a'), ConsentHelper::hashIp('198.51.100.7', 'install-b'));
        self::assertNotSame(hash('sha256', '198.51.100.0/24'), ConsentHelper::hashIp('198.51.100.7', 'k'), 'not a bare hash');
    }

    public function testNoRawAddressAppearsInTheValue(): void
    {
        $hash = ConsentHelper::hashIp('198.51.100.7', 'k');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash, 'fits the 64-character column');
        self::assertStringNotContainsString('198', $hash);
    }

    public function testAnonymisation(): void
    {
        self::assertSame('198.51.100.0/24', ConsentHelper::anonymizeIp('198.51.100.7'));
        self::assertSame('2001:db8:1::/48', ConsentHelper::anonymizeIp('2001:db8:1:2:3:4:5:6'));
        self::assertSame('', ConsentHelper::anonymizeIp('not an ip'));
        self::assertSame('', ConsentHelper::anonymizeIp(''));
    }

    public function testMissingAddressStillProducesAValidValue(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ConsentHelper::hashIp('', 'k'));
    }
}
