<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\controllers\LogsController;
use yii\web\BadRequestHttpException;

/**
 * The site restriction on Consent Records (invariant 12).
 *
 * The restriction used to be decided from the raw `site` parameter while the
 * scope was decided from the resolved one, and the two disagreed about
 * `site[]=…`: the scope read it as "All Sites", the restriction did not, so a
 * user limited to one site listed and exported every site's records.
 */
final class LogsScopeTest extends TestCase
{
    public function testAdminsAndSingleSiteInstallsAreUnrestricted(): void
    {
        self::assertSame([], LogsController::scopeFilters(null, 3, true, [1, 2, 3]));
        self::assertSame([], LogsController::scopeFilters(2, 3, true, [1, 2, 3]));
        self::assertSame([], LogsController::scopeFilters(null, 1, false, [1]));
    }

    public function testAllSitesForARestrictedUserMeansTheirSites(): void
    {
        self::assertSame(['siteIds' => [1]], LogsController::scopeFilters(null, 3, false, [1]));
    }

    public function testAUserWithNoSitesMatchesNothing(): void
    {
        // An empty list is a restriction to nothing (buildQuery() turns it
        // into `siteId IN (0)`), never "no restriction".
        self::assertSame(['siteIds' => []], LogsController::scopeFilters(null, 3, false, []));
    }

    public function testANamedSiteIsNarrowedToItselfAndNeverBeyondTheUsersSites(): void
    {
        self::assertSame(['siteIds' => [1]], LogsController::scopeFilters(1, 3, false, [1]));
        // Even if the requireSite() check before it were bypassed, a site the
        // user may not see still matches nothing.
        self::assertSame(['siteIds' => []], LogsController::scopeFilters(2, 3, false, [1]));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedSiteProvider(): array
    {
        return [
            'site[]=x'         => [['x']],
            'several site ids' => [['1', '2']],
            'keyed array'      => [['id' => '2']],
            'empty array'      => [[]],
        ];
    }

    /**
     * @dataProvider malformedSiteProvider
     */
    public function testANonScalarSiteIsRefusedRatherThanReadAsAllSites(mixed $param): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->_resolveSite($param);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function allSitesProvider(): array
    {
        return ['absent' => [null], 'empty' => [''], 'sentinel' => ['all']];
    }

    /**
     * @dataProvider allSitesProvider
     */
    public function testTheAllSitesSpellingsStillMeanAllSites(mixed $param): void
    {
        self::assertNull($this->_resolveSite($param));
    }

    public function testTheDisplayedFiltersNeverCarryTheSiteRestriction(): void
    {
        // The restriction must be applied to every query, not ride along in
        // the user's filters: the page shows those back (and offers "Clear"
        // when they are non-empty), and a restriction mistaken for a filter
        // was dropped on the cached, unfiltered statistics path.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/controllers/LogsController.php');

        preg_match('/function _resolveFilters\(.*?\n    \}\n/s', $source, $match);
        self::assertNotEmpty($match);
        self::assertStringNotContainsString("['siteIds']", $match[0]);
        self::assertStringNotContainsString('accessibleSiteIds', $match[0]);

        // Every consent query in the controller goes through the scoped set.
        self::assertStringContainsString('$query   = $filters + $this->_scopeFilters($site);', $source);
        self::assertStringContainsString('getLogs($site?->id, $query,', $source);
        self::assertStringContainsString('getStats($site?->id, $query)', $source);
        self::assertStringContainsString('array_keys($categoryLabels), $query)', $source);
        self::assertStringContainsString('$this->_resolveFilters($request) + $this->_scopeFilters($site);', $source);
    }

    private function _resolveSite(mixed $param): mixed
    {
        $controller = (new \ReflectionClass(LogsController::class))->newInstanceWithoutConstructor();
        $method     = new \ReflectionMethod(LogsController::class, '_resolveSite');

        return $method->invoke($controller, $param);
    }
}
