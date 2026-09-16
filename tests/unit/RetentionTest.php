<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;

/**
 * Retention cutoff arithmetic.
 *
 * This decides which consent records get deleted. A mistake here destroys the
 * evidence the plugin exists to keep, reports success, and leaves nothing to
 * notice afterwards — so the boundary conditions are worth stating explicitly
 * rather than inferring from the implementation.
 */
final class RetentionTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-15 12:00:00');
    }

    public function testCutoffIsTheConfiguredNumberOfDaysAgo(): void
    {
        self::assertSame(
            '2026-06-17 12:00:00',
            ConsentService::retentionCutoff(90, $this->now)
        );
    }

    /** 0 means "keep indefinitely" — nothing is deleted. */
    public function testZeroDaysDeletesNothing(): void
    {
        self::assertNull(ConsentService::retentionCutoff(0, $this->now));
    }

    /**
     * A negative period would put the cutoff in the future and so match every
     * record in the table. It must be rejected, not computed.
     */
    public function testNegativeDaysDeletesNothing(): void
    {
        self::assertNull(ConsentService::retentionCutoff(-30, $this->now));
    }

    public function testOneDayRetentionIsExactlyOneDay(): void
    {
        self::assertSame(
            '2026-09-14 12:00:00',
            ConsentService::retentionCutoff(1, $this->now)
        );
    }

    /** The default retention shipped with the plugin. */
    public function testDefaultYearRetention(): void
    {
        self::assertSame(
            '2025-09-15 12:00:00',
            ConsentService::retentionCutoff(365, $this->now)
        );
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

    public function testCutoffCrossesMonthAndYearBoundariesCorrectly(): void
    {
        self::assertSame(
            '2025-12-31 12:00:00',
            ConsentService::retentionCutoff(1, new DateTimeImmutable('2026-01-01 12:00:00'))
        );
    }

    /** Leap day: 2024 was a leap year, so 365 days back from 2025-03-01 is 2024-03-01. */
    public function testCutoffHandlesLeapYears(): void
    {
        self::assertSame(
            '2024-03-01 00:00:00',
            ConsentService::retentionCutoff(365, new DateTimeImmutable('2025-03-01 00:00:00'))
        );
    }
}
