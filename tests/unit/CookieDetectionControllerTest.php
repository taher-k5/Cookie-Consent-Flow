<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\controllers\CookieDetectionController;

/**
 * Which reported cookie names are accepted.
 *
 * The accepted set is documented on CookieDetectionController::NAME_PATTERN:
 * the RFC 6265 `token` characters minus `*`. These tests hold the pattern to
 * that description byte by byte, so the comment and the code cannot drift.
 */
final class CookieDetectionControllerTest extends TestCase
{
    /** Exactly the characters the docblock lists. */
    private const DOCUMENTED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789' . "!#$%&'+-.^_`|~";

    /** RFC 6265 / RFC 2616 separators — never part of a cookie name. */
    private const SEPARATORS = '()<>@,;:\\"/[]?={}';

    private static function accepts(string $name): bool
    {
        return (bool) preg_match(CookieDetectionController::NAME_PATTERN, $name);
    }

    public function testEveryByteIsAcceptedExactlyWhenDocumented(): void
    {
        $accepted = '';
        for ($byte = 0; $byte < 256; $byte++) {
            if (self::accepts(chr($byte))) {
                $accepted .= chr($byte);
            }
        }

        $expected = str_split(self::DOCUMENTED);
        $actual   = str_split($accepted);
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function testTheDocumentedSetIsTheRfcTokenSetWithoutTheWildcard(): void
    {
        $token = '';
        for ($byte = 33; $byte < 127; $byte++) {
            if (!str_contains(self::SEPARATORS, chr($byte))) {
                $token .= chr($byte);
            }
        }

        $expected = str_split(str_replace('*', '', $token));
        $documented = str_split(self::DOCUMENTED);
        sort($expected);
        sort($documented);

        self::assertSame($expected, $documented);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedCharacterProvider(): array
    {
        $cases = [];
        foreach (str_split("!#$%&'+-.^_`|~") as $char) {
            $cases['inside a name: ' . $char] = ['pre' . $char . 'post'];
        }

        return $cases;
    }

    /**
     * @dataProvider acceptedCharacterProvider
     */
    public function testEachPunctuationCharacterIsAcceptedInsideAName(string $name): void
    {
        self::assertTrue(self::accepts($name));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedProvider(): array
    {
        $cases = [
            'asterisk alone'            => ['*'],
            'asterisk in a pattern'     => ['_ga_*'],
            'asterisk inside'           => ['a*b'],
            'backslash alone'           => ['\\'],
            'backslash inside'          => ['a\\b'],
            'space'                     => ['a b'],
            'leading space'             => [' ab'],
            'tab'                       => ["a\tb"],
            'newline inside'            => ["a\nb"],
            'trailing newline'          => ["ab\n"],
            'carriage return'           => ["ab\r"],
            'NUL'                       => ["a\0b"],
            'DEL'                       => ["a\x7fb"],
            'UTF-8 letter'              => ['é'],
            'UTF-8 inside'              => ['café'],
            'emoji'                     => ['🍪'],
            'Latin-1 byte'              => ["a\xe9"],
            'empty'                     => [''],
            'too long'                  => [str_repeat('n', 256)],
            'markup'                    => ['<svg onload=x>'],
            'quote'                     => ['a"b'],
        ];

        foreach (str_split(self::SEPARATORS) as $char) {
            $cases['separator ' . $char] = ['a' . $char . 'b'];
        }

        return $cases;
    }

    /**
     * @dataProvider rejectedProvider
     */
    public function testImpossibleOrDangerousNamesAreRejected(string $name): void
    {
        self::assertFalse(self::accepts($name));
    }

    public function testLengthBoundaries(): void
    {
        self::assertTrue(self::accepts('a'));
        self::assertTrue(self::accepts(str_repeat('n', 255)));
        self::assertFalse(self::accepts(str_repeat('n', 256)));
    }

    public function testRealWorldNames(): void
    {
        foreach (['_ga', '_ga_G-ABC123DEF4', '_gid', '_fbp', '__Host-session', '__Secure-3PSID', 'AMCV_1234ABCD%40AdobeOrg',
            '_hjSession_123456', 'CraftSessionId', 'CRAFT_CSRF_TOKEN', 'wp-settings-1', 'OptanonConsent', 'x-ms-cpim-sso:tenant'] as $name) {
            $expected = !str_contains($name, ':'); // ':' is a separator, so the last one is correctly refused
            self::assertSame($expected, self::accepts($name), $name);
        }
    }

    public function testListValidation(): void
    {
        self::assertSame([], CookieDetectionController::validateNames(null));
        self::assertSame([], CookieDetectionController::validateNames(''));
        self::assertSame([], CookieDetectionController::validateNames([]));

        self::assertNull(CookieDetectionController::validateNames('_ga'), 'not a list');
        self::assertNull(CookieDetectionController::validateNames(['_ga', 7]), 'a non-string entry');
        self::assertNull(CookieDetectionController::validateNames(['_ga', ['nested']]), 'a nested array');

        self::assertSame(['_ga', '_gid'], CookieDetectionController::validateNames(['_ga', 'a b', '*', '_gid']), 'invalid names dropped individually');
        self::assertCount(100, CookieDetectionController::validateNames(array_map(static fn($i) => "c{$i}", range(1, 150))), 'capped at 100');
        self::assertSame(['a', 'b'], CookieDetectionController::validateNames(['x' => 'a', 'y' => 'b']), 're-indexed');
    }
}
