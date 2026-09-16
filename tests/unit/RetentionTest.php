<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;

/**
 * Retention cutoff arithmetic, and the filter dates that share its column.
 *
 * This decides which consent records get deleted. A mistake here destroys the
 * evidence the plugin exists to keep, reports success, and leaves nothing to
 * notice afterwards — so the boundary conditions are worth stating explicitly
 * rather than inferring from the implementation.
 *
 * Both halves compare against `dateCreated`, which Craft stores in **UTC**.
 * They used to be computed in whatever timezone the install had configured,
 * which on any non-UTC site put every boundary hours out in one direction or
 * the other. Each case below therefore names its timezone rather than
 * inheriting the machine's, since a test that agreed with the bug on a UTC
 * developer machine is exactly how this survived.
 */
final class RetentionTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = self::utc('2026-09-15 12:00:00');
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    // -----------------------------------------------------------------
    // Cutoff arithmetic
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: string, 2: string}> [days, now, expected]
     */
    public static function cutoffProvider(): array
    {
        return [
            'one day'            => [1, '2026-09-15 12:00:00', '2026-09-14 12:00:00'],
            'ninety days'        => [90, '2026-09-15 12:00:00', '2026-06-17 12:00:00'],
            'shipped default'    => [365, '2026-09-15 12:00:00', '2025-09-15 12:00:00'],
            // Crossing a month and a year boundary in one step.
            'year boundary'      => [1, '2026-01-01 12:00:00', '2025-12-31 12:00:00'],
            // 2024 was a leap year, so 365 days back from 2025-03-01 is 2024-03-01.
            'across a leap year' => [365, '2025-03-01 00:00:00', '2024-03-01 00:00:00'],
        ];
    }

    /**
     * @dataProvider cutoffProvider
     */
    public function testCutoffIsTheConfiguredNumberOfDaysAgo(int $days, string $now, string $expected): void
    {
        self::assertSame($expected, ConsentService::retentionCutoff($days, self::utc($now)));
    }

    /**
     * 0 means "keep indefinitely". A negative period would put the cutoff in
     * the *future* and so match every record in the table, so it is rejected
     * rather than computed.
     *
     * @return array<string, array{0: int}>
     */
    public static function noCutoffProvider(): array
    {
        return ['zero' => [0], 'negative' => [-30], 'far negative' => [-3650]];
    }

    /**
     * @dataProvider noCutoffProvider
     */
    public function testNonPositiveRetentionDeletesNothing(int $days): void
    {
        self::assertNull(ConsentService::retentionCutoff($days, $this->now));
    }

    /** The cutoff is strictly in the past, so today's records always survive. */
    public function testCutoffIsAlwaysBeforeNow(): void
    {
        foreach ([1, 7, 30, 180, 365, 3650] as $days) {
            self::assertLessThan(
                $this->now->format('Y-m-d H:i:s'),
                ConsentService::retentionCutoff($days, $this->now),
                "Cutoff for {$days} days should be in the past"
            );
        }
    }

    // -----------------------------------------------------------------
    // Timezone correctness
    // -----------------------------------------------------------------

    /**
     * The same instant expressed in three timezones is one instant, so it must
     * produce one cutoff. Before the fix each produced a different string and
     * deleted a different set of records.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function equivalentInstantProvider(): array
    {
        return [
            'UTC'             => ['2026-09-15 12:00:00', 'UTC'],
            'positive offset' => ['2026-09-15 21:00:00', 'Asia/Tokyo'],
            'negative offset' => ['2026-09-15 08:00:00', 'America/New_York'],
        ];
    }

    /**
     * @dataProvider equivalentInstantProvider
     */
    public function testCutoffIsUtcWhateverTheCallersTimezone(string $local, string $timezone): void
    {
        self::assertSame(
            '2026-06-17 12:00:00',
            ConsentService::retentionCutoff(90, new DateTimeImmutable($local, new DateTimeZone($timezone)))
        );
    }

    /**
     * A record written exactly at the cutoff is not older than the cutoff, so
     * the strict `<` comparison in purgeOldLogs() keeps it. Stated here
     * because a boundary that silently moves by one record is the kind of
     * thing only a test notices.
     */
    public function testRecordExactlyAtTheCutoffIsNotPastRetention(): void
    {
        $cutoff = ConsentService::retentionCutoff(30, $this->now);

        self::assertSame('2026-08-16 12:00:00', $cutoff);
        self::assertFalse('2026-08-16 12:00:00' < $cutoff, 'A record at the cutoff must survive');
        self::assertTrue('2026-08-16 11:59:59' < $cutoff, 'A record a second older must not');
        self::assertFalse('2026-08-16 12:00:01' < $cutoff, 'A record a second newer must not');
    }

    // -----------------------------------------------------------------
    // Filter dates
    // -----------------------------------------------------------------

    /**
     * A bare date means that day where the administrator is, and has to be
     * converted before it is compared with a UTC column. Tokyo is UTC+9, so
     * their midnight already happened at 15:00 the previous UTC day.
     */
    public function testBareFromDateIsReadInTheInstallTimezoneAndReturnedAsUtc(): void
    {
        self::assertSame(
            '2026-09-15 15:00:00',
            ConsentService::normalizeFilterDate('2026-09-16', '00:00:00', 'Asia/Tokyo')
        );
    }

    /** The end of a day converts the same way, keeping the range symmetric. */
    public function testBareToDateUsesTheEndOfTheLocalDay(): void
    {
        self::assertSame(
            '2026-09-17 03:59:59',
            ConsentService::normalizeFilterDate('2026-09-16', '23:59:59', 'America/New_York')
        );
    }

    /** With no timezone in play the value passes through unshifted. */
    public function testUtcFilterDateIsUnchanged(): void
    {
        self::assertSame(
            '2026-09-16 00:00:00',
            ConsentService::normalizeFilterDate('2026-09-16', '00:00:00', 'UTC')
        );
    }

    /** An explicit offset in the value itself wins over the install's. */
    public function testExplicitOffsetInTheValueIsHonoured(): void
    {
        self::assertSame(
            '2026-09-15 22:00:00',
            ConsentService::normalizeFilterDate('2026-09-16T00:00:00+02:00', '00:00:00', 'Asia/Tokyo')
        );
    }

    /**
     * Null means "no filter", not "match nothing" — a malformed date in a URL
     * must not produce an empty export that looks authoritative.
     */
    public function testUnusableFilterDatesApplyNoFilter(): void
    {
        self::assertNull(ConsentService::normalizeFilterDate('', '00:00:00', 'UTC'));
        self::assertNull(ConsentService::normalizeFilterDate('   ', '00:00:00', 'UTC'));
        self::assertNull(ConsentService::normalizeFilterDate('not-a-date', '00:00:00', 'UTC'));
        self::assertNull(ConsentService::normalizeFilterDate(null, '00:00:00', 'UTC'));
    }
}
