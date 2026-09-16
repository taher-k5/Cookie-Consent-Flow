<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\controllers\LogsController;

/**
 * CSV cell rendering for the consent-record export.
 *
 * Spreadsheets evaluate any cell whose text begins with `=`, `+`, `-` or `@`.
 * Several exported columns carry text an administrator controls — a site name,
 * a policy version — so without a guard a value like `=HYPERLINK(...)` runs on
 * whoever opens the export rather than being read as the name it is.
 */
final class ExportTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function formulaTriggerProvider(): array
    {
        return [
            'equals'          => ['=1+1'],
            'plus'            => ['+1+1'],
            'minus'           => ['-1+1'],
            'at'              => ['@SUM(A1)'],
            'hyperlink'       => ['=HYPERLINK("http://evil.test","click")'],
            'leading tab'     => ["\t=1+1"],
            'carriage return' => ["\r=1+1"],
        ];
    }

    /**
     * @dataProvider formulaTriggerProvider
     */
    public function testFormulaTriggersAreNeutralised(string $value): void
    {
        $cell = LogsController::csvCell($value);

        self::assertStringStartsWith("'", $cell, 'Cell should be marked as literal text');
        self::assertSame("'" . $value, $cell, 'The original value must be preserved after the marker');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function harmlessValueProvider(): array
    {
        return [
            'site name'            => ['Demo UK'],
            'site name with dash'  => ['Acme — UK'],
            'policy version'       => ['2026-09-16.1234'],
            'uuid'                 => ['1e0d4e0a-3c61-4d1f-9f1e-7c1a2b3c4d5e'],
            'iso datetime'         => ['2026-09-16 11:22:33'],
            'country code'         => ['GB'],
            'category list'        => ['necessary analytics'],
            'quotes and delimiter' => ['Acme, "the" Agency'],
        ];
    }

    /**
     * @dataProvider harmlessValueProvider
     */
    public function testOrdinaryValuesPassThroughUntouched(string $value): void
    {
        self::assertSame($value, LogsController::csvCell($value));
    }

    /**
     * Numbers stay numbers. Prefixing a negative number would turn a numeric
     * column into text for no benefit — the export's numeric columns are
     * generated here, not supplied by anyone — and a value that is wholly a
     * number cannot express anything for a spreadsheet to execute, whatever
     * sign it carries.
     */
    public function testNumericValuesAreNotPrefixed(): void
    {
        self::assertSame('1', LogsController::csvCell(1));
        self::assertSame('-1', LogsController::csvCell(-1));
        self::assertSame('+1', LogsController::csvCell('+1'));
        self::assertSame('-12.5', LogsController::csvCell('-12.5'));
        self::assertSame('0', LogsController::csvCell(0));
    }

    public function testEmptyCellStaysEmpty(): void
    {
        self::assertSame('', LogsController::csvCell(''));
        self::assertSame('', LogsController::csvCell(null));
    }

    /**
     * The guard must not itself corrupt the file: it never introduces a
     * delimiter, quote or newline, leaving all quoting to fputcsv().
     */
    public function testGuardIntroducesNoCsvSyntax(): void
    {
        $cell = LogsController::csvCell('=cmd|"/c calc"!A1');

        self::assertSame(1, substr_count($cell, "'"), 'Only the single leading marker');
        self::assertStringNotContainsString("\n", $cell);
    }

    /**
     * End to end through fputcsv(): the written row still parses back to the
     * values that went in, with the marker and nothing else added.
     */
    public function testWrittenRowRemainsValidCsv(): void
    {
        $values = ['=HYPERLINK("x")', 'Acme, "the" Agency', 'GB'];

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_map(
            static fn(mixed $value): string => LogsController::csvCell($value),
            $values
        ), ',', '"', '');
        rewind($handle);
        $parsed = fgetcsv($handle, 0, ',', '"', '');
        fclose($handle);

        self::assertSame(["'=HYPERLINK(\"x\")", 'Acme, "the" Agency', 'GB'], $parsed);
    }
}
