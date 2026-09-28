<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\controllers\ConsentController;
use sfsinfotech\craftcookieconsentflow\controllers\CookieDetectionController;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;
use sfsinfotech\craftcookieconsentflow\services\CookieDefinitionService;
use sfsinfotech\craftcookieconsentflow\services\SettingsService;
use yii\db\Expression;

/**
 * Input the plugin must refuse, or normalise, before it reaches storage.
 *
 * - M8: every value bound for a fixed-width column is validated to that
 *   width, counted in characters the way both databases count them, so the
 *   admin gets a message naming the field instead of a failed INSERT.
 * - L9: an invalidated policy version can never repeat an earlier one.
 * - L11: a recorded decision cannot claim more consent than was given.
 * - L12: a malformed anonymous payload is a 400, not a PHP warning.
 * - L16: the records category filter means the same thing on both drivers.
 * - L19: retention deletes in bounded batches.
 */
final class ValidationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // M8 — string lengths
    // -------------------------------------------------------------------------

    /** Validates one attribute on an otherwise default model. */
    private function errorsFor(string $field, mixed $value): array
    {
        $model         = new Settings();
        $model->$field = $value;
        $model->validate([$field]);

        return $model->getErrors($field);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function shortTextFieldProvider(): array
    {
        return array_combine(Settings::SHORT_TEXT_FIELDS, array_map(static fn($f) => [$f], Settings::SHORT_TEXT_FIELDS));
    }

    /**
     * @dataProvider shortTextFieldProvider
     */
    public function testShortTextAcceptsExactly255Characters(string $field): void
    {
        self::assertSame([], $this->errorsFor($field, str_repeat('a', 255)));
        self::assertNotSame([], $this->errorsFor($field, str_repeat('a', 256)), "{$field} must refuse 256 characters");
    }

    /**
     * Multibyte and four-byte characters count as one each — which is how
     * VARCHAR(255) counts them on utf8mb4 MySQL and on PostgreSQL — so a
     * 255-emoji label is valid and a 256th is not.
     *
     * @dataProvider shortTextFieldProvider
     */
    public function testShortTextCountsCharactersNotBytes(string $field): void
    {
        self::assertSame([], $this->errorsFor($field, str_repeat('🍪', 255)));
        self::assertSame([], $this->errorsFor($field, str_repeat('é', 255)));
        self::assertSame([], $this->errorsFor($field, str_repeat('中', 255)));
        self::assertNotSame([], $this->errorsFor($field, str_repeat('🍪', 256)));
    }

    public function testVeryLargeValuesAreRefused(): void
    {
        self::assertNotSame([], $this->errorsFor('bannerHeading', str_repeat('x', 1_000_000)));
        self::assertNotSame([], $this->errorsFor('bannerDescription', str_repeat('x', 1_000_000)));
    }

    public function testDescriptionBoundFitsATextColumnInFourByteCharacters(): void
    {
        self::assertSame([], $this->errorsFor('bannerDescription', str_repeat('🍪', Settings::LONG_TEXT_MAX_LENGTH)));
        self::assertNotSame([], $this->errorsFor('bannerDescription', str_repeat('a', Settings::LONG_TEXT_MAX_LENGTH + 1)));

        // MySQL TEXT holds 65,535 bytes.
        self::assertLessThanOrEqual(65535, Settings::LONG_TEXT_MAX_LENGTH * 4);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dayFieldProvider(): array
    {
        return ['retention' => ['logRetentionDays'], 'expiry' => ['consentExpiryDays']];
    }

    /**
     * Day counts are bounded well inside the signed 32-bit column and the
     * DATETIME range a retention cutoff has to land in, so an absurd value is
     * refused with a message naming the limit instead of failing at INSERT.
     *
     * @dataProvider dayFieldProvider
     */
    public function testDayCountsAreBoundedBeforeTheyReachTheColumn(string $field): void
    {
        self::assertSame([], $this->errorsFor($field, 0), '0 is the documented "no limit" value');
        self::assertSame([], $this->errorsFor($field, Settings::DAYS_MAX));
        self::assertNotSame([], $this->errorsFor($field, -1));
        self::assertNotSame([], $this->errorsFor($field, Settings::DAYS_MAX + 1));
        self::assertNotSame([], $this->errorsFor($field, 99_999_999_999));
        self::assertStringContainsString((string) Settings::DAYS_MAX, str_replace(',', '', implode(' ', $this->errorsFor($field, Settings::DAYS_MAX + 1))));

        self::assertLessThan(2_147_483_647, Settings::DAYS_MAX);

        // The largest allowed retention still yields a cutoff inside MySQL's
        // DATETIME range (from year 1000).
        $cutoff = \sfsinfotech\craftcookieconsentflow\services\ConsentService::retentionCutoff(Settings::DAYS_MAX);
        self::assertGreaterThanOrEqual(1000, (int) substr((string) $cutoff, 0, 4));
    }

    public function testErrorMessageNamesTheField(): void
    {
        $errors = $this->errorsFor('privacyPolicyUrl', str_repeat('a', 300));

        self::assertStringContainsString('255', implode(' ', $errors));
    }

    /**
     * M4's server-side half: a blank button label is refused rather than
     * stored, because it is the button's only accessible name.
     */
    public function testButtonLabelsCannotBeBlank(): void
    {
        foreach (Settings::BUTTON_LABEL_FIELDS as $field) {
            self::assertNotSame([], $this->errorsFor($field, ''), "{$field} empty");
            self::assertNotSame([], $this->errorsFor($field, '   '), "{$field} whitespace");
            self::assertSame([], $this->errorsFor($field, 'OK'), "{$field} set");
        }
    }

    public function testCategoryLimits(): void
    {
        self::assertSame([], SettingsService::validateCategories([
            ['key' => str_repeat('k', 100), 'label' => str_repeat('🍪', 255), 'description' => ''],
        ]));

        $errors = SettingsService::validateCategories([
            ['key' => str_repeat('k', 101), 'label' => 'Fine'],
            ['key' => 'ok', 'label' => str_repeat('l', 256)],
            ['key' => 'blank', 'label' => '  '],
            ['key' => '', 'label' => 'Key was all symbols'],
            ['key' => 'long', 'label' => 'Long', 'description' => str_repeat('d', Settings::LONG_TEXT_MAX_LENGTH + 1)],
        ]);

        self::assertCount(5, $errors);
    }

    /** L16: duplicate keys differing only in case are refused on every driver. */
    public function testCategoryKeysMustBeUniqueIgnoringCase(): void
    {
        $errors = SettingsService::validateCategories([
            ['key' => 'analytics', 'label' => 'Analytics'],
            ['key' => 'Analytics', 'label' => 'Analytics again'],
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('Analytics', $errors[0]);
    }

    public function testCookieLimits(): void
    {
        self::assertSame([], CookieDefinitionService::validateCookies([
            ['name' => str_repeat('n', 255), 'provider' => str_repeat('p', 255), 'duration' => str_repeat('d', 100), 'categoryKey' => 'analytics', 'purpose' => 'x'],
            ['name' => '', 'provider' => str_repeat('p', 999)], // nameless rows are skipped, not errors
        ]));

        $errors = CookieDefinitionService::validateCookies([
            ['name' => str_repeat('n', 256)],
            ['name' => 'ok', 'provider' => str_repeat('p', 256)],
            ['name' => 'ok', 'duration' => str_repeat('d', 101)],
            ['name' => 'ok', 'purpose' => str_repeat('u', Settings::LONG_TEXT_MAX_LENGTH + 1)],
            ['name' => 'ok', 'provider' => ['not', 'a', 'string']],
            'not a row',
        ]);

        self::assertCount(6, $errors);
    }

    // -------------------------------------------------------------------------
    // L9 — policy versions
    // -------------------------------------------------------------------------

    public function testPolicyVersionIsAUtcTimestamp(): void
    {
        $now = new \DateTimeImmutable('2026-09-26 16:30:05', new \DateTimeZone('Asia/Kolkata'));

        self::assertSame('2026-09-26.110005', SettingsService::nextPolicyVersion('1', $now));
    }

    /**
     * The regression: the old scheme (`Y-m-d.` + last four digits of time())
     * repeated every 10,000 seconds, so invalidations A → B could land back on
     * A and silently revalidate consent given under A.
     */
    public function testVersionsNeverRepeatAcrossADay(): void
    {
        $start = new \DateTimeImmutable('2026-09-26 00:00:00', new \DateTimeZone('UTC'));
        $seen  = [];

        for ($seconds = 0; $seconds < 86400; $seconds += 997) {
            $version = SettingsService::nextPolicyVersion('previous', $start->modify("+{$seconds} seconds"));

            self::assertArrayNotHasKey($version, $seen);
            $seen[$version] = true;
        }

        // What the old formula produced for two moments 10,000 s apart:
        self::assertSame(substr((string) 1790000000, -4), substr((string) 1790010000, -4));
    }

    public function testTwoInvalidationsInOneSecondStillDiffer(): void
    {
        $now    = new \DateTimeImmutable('2026-09-26 11:00:05', new \DateTimeZone('UTC'));
        $first  = SettingsService::nextPolicyVersion('1', $now);
        $second = SettingsService::nextPolicyVersion($first, $now);
        $third  = SettingsService::nextPolicyVersion($second, $now);

        self::assertSame(['2026-09-26.110005', '2026-09-26.110005.2', '2026-09-26.110005.3'], [$first, $second, $third]);
    }

    public function testVersionFitsItsColumn(): void
    {
        self::assertLessThanOrEqual(50, strlen(SettingsService::nextPolicyVersion('x')));
    }

    // -------------------------------------------------------------------------
    // L11 — recorded decisions are internally consistent
    // -------------------------------------------------------------------------

    private const KNOWN  = ['necessary', 'analytics', 'marketing'];
    private const LOCKED = ['necessary'];

    public function testAcceptAllWithEveryCategoryStands(): void
    {
        self::assertSame(
            ['action' => 'accept_all', 'categories' => ['necessary', 'analytics', 'marketing']],
            ConsentService::normalizeDecision('accept_all', ['marketing', 'analytics'], self::KNOWN, self::LOCKED)
        );
    }

    /** "accept_all" with only the locked categories is not a claim to record as-is. */
    public function testAcceptAllMissingCategoriesIsRecordedAsWhatWasSent(): void
    {
        self::assertSame(
            ['action' => 'custom', 'categories' => ['necessary']],
            ConsentService::normalizeDecision('accept_all', ['necessary'], self::KNOWN, self::LOCKED)
        );
        self::assertSame(
            ['action' => 'custom', 'categories' => ['necessary', 'analytics']],
            ConsentService::normalizeDecision('accept_all', ['analytics'], self::KNOWN, self::LOCKED)
        );
    }

    public function testRejectAllNeverRecordsOptionalCategories(): void
    {
        self::assertSame(
            ['action' => 'reject_all', 'categories' => ['necessary']],
            ConsentService::normalizeDecision('reject_all', ['analytics', 'marketing'], self::KNOWN, self::LOCKED)
        );
    }

    public function testCustomKeepsAnyCombinationAndAlwaysTheLockedOnes(): void
    {
        self::assertSame(
            ['action' => 'custom', 'categories' => ['necessary', 'marketing']],
            ConsentService::normalizeDecision('custom', ['marketing', 'ghost', 'marketing'], self::KNOWN, self::LOCKED)
        );
        self::assertSame(
            ['action' => 'custom', 'categories' => ['necessary', 'analytics', 'marketing']],
            ConsentService::normalizeDecision('custom', self::KNOWN, self::KNOWN, self::LOCKED)
        );
    }

    public function testWithNoOptionalCategoriesBothOutcomesAreConsistent(): void
    {
        foreach (['accept_all', 'reject_all'] as $action) {
            self::assertSame(
                ['action' => $action, 'categories' => ['necessary']],
                ConsentService::normalizeDecision($action, [], ['necessary'], ['necessary'])
            );
        }
    }

    // -------------------------------------------------------------------------
    // L12 — malformed anonymous input is a 400
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: string}>
     */
    public static function malformedPayloadProvider(): array
    {
        return [
            'action as array'          => [['accept_all'], [], null, 'invalid_action'],
            'action as number'         => [1, [], null, 'invalid_action'],
            'unknown action'           => ['accept_everything', [], null, 'invalid_action'],
            'missing action'           => [null, [], null, 'invalid_action'],
            'categories as string'     => ['custom', 'analytics', null, 'invalid_categories'],
            'nested categories'        => ['custom', [['analytics']], null, 'invalid_categories'],
            'numeric category'         => ['custom', [1, 2], null, 'invalid_categories'],
            'object category'          => ['custom', [['key' => 'x']], null, 'invalid_categories'],
            'too many categories'      => ['custom', array_fill(0, 101, 'a'), null, 'invalid_categories'],
            'oversized category'       => ['custom', [str_repeat('a', 101)], null, 'invalid_categories'],
            'source as array'          => ['custom', [], ['banner'], 'invalid_source'],
        ];
    }

    /**
     * @dataProvider malformedPayloadProvider
     */
    public function testMalformedConsentPayloadsAreRefused(mixed $action, mixed $categories, mixed $source, string $error): void
    {
        self::assertSame(['error' => $error], ConsentController::validatePayload($action, $categories, $source));
    }

    public function testWellFormedPayloadPassesThrough(): void
    {
        self::assertSame(
            ['action' => 'custom', 'categories' => ['analytics'], 'source' => 'gpc', 'policyVersion' => '2026-09-26.110005'],
            ConsentController::validatePayload('custom', ['analytics'], 'gpc', '2026-09-26.110005')
        );
        self::assertSame(
            ['action' => 'reject_all', 'categories' => [], 'source' => 'banner', 'policyVersion' => null],
            ConsentController::validatePayload('reject_all', null, 'somewhere-else'),
            'no categories, an unknown source and no policy version (older runtimes) are all acceptable'
        );
    }

    /**
     * Final audit: the record carries the policy version the visitor's page
     * showed, so its shape is checked like any other client input.
     */
    public function testMalformedPolicyVersionsAreRefused(): void
    {
        foreach ([['1'], 7, '', str_repeat('v', 51), 'has space', '<script>', "v1\n"] as $bad) {
            self::assertSame(
                ['error' => 'invalid_policy_version'],
                ConsentController::validatePayload('custom', [], 'banner', $bad),
                json_encode($bad)
            );
        }
    }

    public function testThePolicyVersionFieldAcceptsOnlyWhatTheServerWillAccept(): void
    {
        foreach (['1', '2026-09-26.110005', '2026-09-26.110005.2', 'v2:eu'] as $ok) {
            self::assertSame([], $this->errorsFor('policyVersion', $ok), $ok);
        }
        foreach (['', 'has space', 'v/2', str_repeat('v', 51)] as $bad) {
            self::assertNotSame([], $this->errorsFor('policyVersion', $bad), $bad);
        }

        // Every generated version is acceptable to the endpoint.
        self::assertMatchesRegularExpression(ConsentService::POLICY_VERSION_PATTERN, SettingsService::nextPolicyVersion('1'));
    }

    // -------------------------------------------------------------------------
    // Final audit — stored XSS through the privacy policy URL
    // -------------------------------------------------------------------------

    /**
     * `parse_url()` reads `javascript:1/alert(1)` as host "javascript", port
     * 1, and reports no scheme, so the value passed as a relative URL and was
     * rendered as a live javascript: link on every page.
     */
    public function testScriptUrlsAreRefusedHoweverTheyAreWritten(): void
    {
        foreach (['javascript:1/alert(1)', 'JAVASCRIPT:123/x', 'javascript:alert(1)', 'data:text/html,<script>', 'vbscript:x',
            'JaVaScRiPt:1/x', "java\tscript:x", ' javascript:x', 'https://e.test/"onmouseover=x'] as $url) {
            self::assertFalse(Settings::isSafeUrl($url), $url);
            self::assertNotSame([], $this->errorsFor('privacyPolicyUrl', $url), "refused on save: {$url}");

            $model = new Settings();
            $model->privacyPolicyUrl = $url;
            self::assertSame('', $model->getSafePrivacyPolicyUrl(), "never rendered: {$url}");
        }
    }

    public function testOrdinaryPolicyUrlsAreKept(): void
    {
        foreach (['/privacy', 'privacy', '../legal/privacy', '/p?ref=a:b', '/p#s:1', 'https://example.test/privacy', 'HTTP://EXAMPLE.TEST', 'mailto:dpo@example.test', '//cdn.example.test/p'] as $url) {
            self::assertTrue(Settings::isSafeUrl($url), $url);
            self::assertSame([], $this->errorsFor('privacyPolicyUrl', $url), $url);
        }

        self::assertSame([], $this->errorsFor('privacyPolicyUrl', ''), 'no policy link is allowed');
    }

    public function testMalformedDetectionPayloadsAreRefused(): void
    {
        foreach (['_ga', 42, ['_ga', ['nested']], ['_ga', 7], [null]] as $names) {
            self::assertNull(CookieDetectionController::validateNames($names), json_encode($names));
        }
    }

    public function testDetectionNamesAreFilteredAndCapped(): void
    {
        self::assertSame(['_ga', 'CraftSessionId'], CookieDetectionController::validateNames(['_ga', 'bad name', 'CraftSessionId']));
        self::assertCount(100, CookieDetectionController::validateNames(array_map(static fn($i) => "c{$i}", range(1, 150))));
        self::assertSame([], CookieDetectionController::validateNames(null));
    }

    /**
     * Legitimate RFC 6265 cookie names that the previous `[\w.\-*]` pattern
     * dropped, so they never reached the Cookies page.
     */
    public function testLegitimateCookieNamesAreAccepted(): void
    {
        $names = ['AMCV_1234ABCD%40AdobeOrg', '__Host-session', '__Secure-id', '_ga_G-ABC123', 'a|b', 'x~y', 'wp!ref',
            "o'brien", 'ref#1', 'amp&t', 'plus+', 'caret^', 'back`tick', 'dollar$', str_repeat('n', 255)];

        self::assertSame($names, CookieDetectionController::validateNames($names));
    }

    /**
     * Characters that cannot be in a cookie name, and `*`, which is the
     * wildcard in documented patterns: a reported `*` documented with one
     * click would match — and hide — every undocumented cookie.
     */
    public function testImpossibleOrDangerousCookieNamesAreDropped(): void
    {
        $bad = ['a b', 'a;b', 'a=b', 'a,b', '<svg onload=x>', 'a"b', 'a\\b', 'a/b', 'a(b)', 'a[b]', 'a{b}', 'a?b',
            'a@b', 'a:b', "a\tb", "a\nb", "a\x00b", "ab\n", '*', '_ga_*', 'é', '🍪', '', str_repeat('n', 256)];

        self::assertSame(['ok'], CookieDetectionController::validateNames(array_merge($bad, ['ok'])));
    }

    // -------------------------------------------------------------------------
    // L16 — one meaning of "includes this category" on both drivers
    // -------------------------------------------------------------------------

    public function testCategoryConditionIsBinaryOnMysql(): void
    {
        $condition = ConsentService::categoryCondition('ad_storage', 'mysql');

        self::assertInstanceOf(Expression::class, $condition);
        self::assertStringContainsString('BINARY', (string) $condition);
        self::assertSame([':ccfCategory' => '"ad_storage"'], $condition->params, 'quoted, exact, and no wildcard');
    }

    public function testCategoryConditionIsCaseSensitiveOnPostgres(): void
    {
        $condition = ConsentService::categoryCondition('analytics', 'pgsql');

        self::assertStringContainsString('STRPOS', (string) $condition);
        self::assertStringNotContainsString('ILIKE', (string) $condition);
        self::assertSame([':ccfCategory' => '"analytics"'], $condition->params);
    }

    public function testImpossibleCategoryMatchesNothing(): void
    {
        foreach (['', 'has space', '"quoted"', "x'); DROP TABLE x; --", str_repeat('a', 101)] as $value) {
            self::assertSame('1 = 0', (string) ConsentService::categoryCondition($value, 'mysql'), $value);
        }
    }

    // -------------------------------------------------------------------------
    // L19 — retention batching
    // -------------------------------------------------------------------------

    public function testRetentionDeletesInBoundedBatchesUntilDone(): void
    {
        $rows    = range(1, 2345);
        $batches = [];

        $deleted = ConsentService::deleteInBatches(
            function (int $limit) use (&$rows): array {
                return array_slice($rows, 0, $limit);
            },
            function (array $ids) use (&$rows, &$batches): int {
                $batches[] = count($ids);
                $rows      = array_values(array_diff($rows, $ids));

                return count($ids);
            },
            1000
        );

        self::assertSame(2345, $deleted);
        self::assertSame([1000, 1000, 345], $batches, 'never more than one batch per statement');
        self::assertSame([], $rows);
    }

    public function testRetentionStopsIfRowsVanishConcurrently(): void
    {
        $calls = 0;

        $deleted = ConsentService::deleteInBatches(
            static fn(int $limit): array => range(1, $limit),
            function () use (&$calls): int { $calls++; return 0; },
            10
        );

        self::assertSame(0, $deleted);
        self::assertSame(1, $calls, 'no infinite loop on ids someone else already removed');
    }

    public function testRetentionWithNothingToDeleteRunsOneQuery(): void
    {
        $deletes = 0;

        self::assertSame(0, ConsentService::deleteInBatches(
            static fn(): array => [],
            function () use (&$deletes): int { $deletes++; return 0; },
            1000
        ));
        self::assertSame(0, $deletes);
    }
}
