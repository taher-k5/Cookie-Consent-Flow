<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use sfsinfotech\craftcookieconsentflow\controllers\LogsController;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\helpers\Permissions;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;

/**
 * Finding, exporting and deleting one visitor's consent records.
 *
 * The visitor ID is the only link between a person and their records, so
 * every place one arrives from outside — a records search, a deletion, the
 * ID the browser made — goes through one normaliser, and an ID that is not
 * one must match nothing rather than everything.
 */
final class DataSubjectRequestTest extends TestCase
{
    private const ID = '2a5c0cff-91b3-4e2d-a7f0-3c1d9e8b4a21';

    /**
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function visitorIdProvider(): array
    {
        return [
            'as stored'               => [self::ID, self::ID],
            'upper case, as typed'    => [strtoupper(self::ID), self::ID],
            'pasted with whitespace'  => ["  " . self::ID . "\n", self::ID],
            'any UUID version'        => ['0a1b2c3d-0000-1000-0000-000000000000', '0a1b2c3d-0000-1000-0000-000000000000'],
            'empty'                   => ['', null],
            'without dashes'          => [str_replace('-', '', self::ID), null],
            'too long'                => [self::ID . '0', null],
            'SQL-ish'                 => ["' OR 1=1 --", null],
            'wildcard'                => ['%', null],
            'braces'                  => ['{' . self::ID . '}', null],
            'non-hex'                 => ['2a5c0cff-91b3-4e2d-a7f0-3c1d9e8b4azz', null],
            'array'                   => [[self::ID], null],
            'null'                    => [null, null],
            'number'                  => [7, null],
        ];
    }

    /** @dataProvider visitorIdProvider */
    public function testVisitorIdsAreNormalisedOrRefused(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, ConsentHelper::normalizeVisitorId($input));
    }

    /** Every ID the server has ever issued is one a search can find. */
    public function testServerIssuedIdsAreAlwaysValid(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $id = ConsentHelper::generateVisitorUuid();
            self::assertSame($id, ConsentHelper::normalizeVisitorId($id), $id);
        }
    }

    public function testAnAdminDeletesOnEverySiteIncludingDeletedOnes(): void
    {
        self::assertNull(LogsController::deletableSiteIds(true, [1]));
    }

    public function testAnyoneElseDeletesOnlyOnTheirOwnSites(): void
    {
        self::assertSame([1, 3], LogsController::deletableSiteIds(false, [1, '3']));
        self::assertSame([], LogsController::deletableSiteIds(false, []), 'no sites means nothing, never everything');
    }

    /** A user with no sites must delete nothing, not fall through to every site. */
    public function testDeletingWithNoSitesDeletesNothing(): void
    {
        self::assertSame(0, (new ConsentService())->deleteVisitorRecords(self::ID, []));
    }

    public function testDeletingAnInvalidIdDeletesNothing(): void
    {
        self::assertSame(0, (new ConsentService())->deleteVisitorRecords('%'));
    }

    /** Deleting is its own permission: neither viewing nor exporting implies it. */
    public function testDeletingIsItsOwnPermissionUnderViewing(): void
    {
        $tree = (new ReflectionClass(Permissions::class))->getMethod('definition')->invoke(null);
        $view = $tree[Permissions::VIEW_LOGS]['nested'] ?? [];

        self::assertArrayHasKey(Permissions::DELETE_LOGS, $view);
        self::assertArrayHasKey(Permissions::EXPORT_LOGS, $view);
        self::assertNotSame(Permissions::EXPORT_LOGS, Permissions::DELETE_LOGS);
    }

    /**
     * A retry may keep a used ID only when this very browser claimed it. No
     * claim, no browser key, or another browser's key matches nothing.
     */
    public function testAClaimMatchesOnlyItsOwnBrowser(): void
    {
        self::assertTrue(ConsentService::claimMatches('key-a', 'key-a'));

        self::assertFalse(ConsentService::claimMatches('key-a', 'key-b'));
        self::assertFalse(ConsentService::claimMatches(null, 'key-a'));
        self::assertFalse(ConsentService::claimMatches(false, 'key-a'), 'a cache miss');
        self::assertFalse(ConsentService::claimMatches('key-a', null));
        self::assertFalse(ConsentService::claimMatches('', ''));
        self::assertFalse(ConsentService::claimMatches(['key-a'], 'key-a'));
    }

    public function testShowingTheConsentIdIsOffByDefault(): void
    {
        self::assertFalse((new Settings())->showConsentId, 'nothing identifying is stored before a decision by default');
    }
}
