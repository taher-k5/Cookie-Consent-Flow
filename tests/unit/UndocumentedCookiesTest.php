<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\services\CookieDefinitionService;

/**
 * The Cookies page's "undocumented" list.
 *
 * Documented names are wildcard patterns, so they are excluded in PHP; the
 * list's limit used to be applied in SQL first, so once 500 detected names
 * were documented, real undocumented cookies behind them were never listed.
 */
final class UndocumentedCookiesTest extends TestCase
{
    /** @return \Generator<int, array{name: string, firstSeen: string, lastSeen: string}> */
    private static function rows(array $names, int &$read): \Generator
    {
        foreach ($names as $name) {
            $read++;
            yield ['name' => $name, 'firstSeen' => '2026-09-01 00:00:00', 'lastSeen' => '2026-09-28 00:00:00'];
        }
    }

    public function testUndocumentedNamesBehindMoreThan500DocumentedOnesAreListed(): void
    {
        $names = [];
        for ($i = 0; $i < 600; $i++) {
            $names[] = $i % 2 === 0 ? "_ga_{$i}" : "documented_{$i}";
        }
        $names[] = 'real_undocumented_a';
        $names[] = 'real_undocumented_b';

        $documented = ['_ga_*'];
        for ($i = 1; $i < 600; $i += 2) {
            $documented[] = "documented_{$i}";
        }

        $read = 0;
        $out  = CookieDefinitionService::takeUndocumented(self::rows($names, $read), $documented, 500);

        self::assertSame(['real_undocumented_a', 'real_undocumented_b'], array_column($out, 'name'));
        self::assertSame(602, $read, 'every row was needed, so every row was read');
    }

    public function testTheListIsCappedAndStopsReadingOnceFull(): void
    {
        $names = [];
        for ($i = 0; $i < 700; $i++) {
            $names[] = "name_{$i}";
        }

        $read = 0;
        $out  = CookieDefinitionService::takeUndocumented(self::rows($names, $read), ['name_0', 'name_1'], 500);

        self::assertCount(500, $out);
        self::assertSame('name_2', $out[0]['name'], 'documented names are skipped, order is kept');
        self::assertSame('name_501', $out[499]['name']);
        self::assertSame(502, $read, 'no row past the 500th undocumented one is asked for');
    }

    public function testPatternsAreCaseSensitiveAndAnchored(): void
    {
        self::assertTrue(CookieDefinitionService::matchesAnyPattern('_ga_ABC', ['_ga_*']));
        self::assertFalse(CookieDefinitionService::matchesAnyPattern('_GA_ABC', ['_ga_*']));
        self::assertFalse(CookieDefinitionService::matchesAnyPattern('x_ga', ['_ga']));
        self::assertFalse(CookieDefinitionService::matchesAnyPattern('_ga.x', ['_ga']), 'a dot is not a wildcard');
    }
}
