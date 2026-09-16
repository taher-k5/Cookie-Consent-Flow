<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\Plugin;

/**
 * Locating the closing body tag the banner is spliced in front of.
 *
 * The guard that decides whether to inject and the splice that performs it
 * have to agree about what counts as a closing body tag. They did not: the
 * guard matched case-insensitively and the splice used a case-sensitive
 * search, so a document written with `</BODY>` passed the guard, received the
 * Consent Mode snippet, and then silently got no banner — a failure visible
 * only on the templates that happened to be written that way.
 */
final class BannerInjectionTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function bodyTagProvider(): array
    {
        return [
            'lowercase'        => ['</body>'],
            'uppercase'        => ['</BODY>'],
            'mixed case'       => ['</Body>'],
            'trailing space'   => ['</body >'],
            'trailing newline' => ["</body\n>"],
        ];
    }

    /**
     * @dataProvider bodyTagProvider
     */
    public function testEverySpellingOfTheClosingTagIsFound(string $tag): void
    {
        $html  = '<html><body><p>Hi</p>' . $tag . '</html>';
        $found = Plugin::findBodyClose($html);

        self::assertNotNull($found, "{$tag} was not recognised");
        self::assertSame($tag, substr($html, $found[0], $found[1]));
    }

    /**
     * The offset and length are what the splice uses, so re-inserting the
     * matched text has to reproduce the document byte for byte — including
     * whichever casing the page itself used.
     */
    public function testMatchCanBeSplicedBackLosslessly(): void
    {
        $html = '<html><body>x</BODY></html>';

        [$pos, $length] = Plugin::findBodyClose($html);
        $spliced = substr_replace($html, substr($html, $pos, $length), $pos, $length);

        self::assertSame($html, $spliced);
    }

    /** A response with no body tag is left alone entirely. */
    public function testDocumentWithoutABodyTagIsNotMatched(): void
    {
        self::assertNull(Plugin::findBodyClose('{"consent":null}'));
        self::assertNull(Plugin::findBodyClose('<html><body>never closed</html>'));
    }

    /**
     * The *last* occurrence wins, matching the previous behaviour: a page that
     * displays the string as escaped example markup must still have the banner
     * appended at the real end of the document.
     */
    public function testTheLastClosingTagIsTheOneUsed(): void
    {
        $html = '<html><body><code>&lt;/body&gt;</code></body>' . "\n" . '</BODY></html>';

        [$pos, $length] = Plugin::findBodyClose($html);

        self::assertSame('</BODY>', substr($html, $pos, $length));
        self::assertSame(1, substr_count(substr($html, $pos), '</BODY>'));
    }
}
