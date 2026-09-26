<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\helpers\Permissions;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Logs Controller — the Consent Records CP page, its filters, and export.
 */
class LogsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public const PAGE_SIZE = 50;

    /** @deprecated Use ConsentService::ACTIONS. Kept so existing references don't break. */
    public const VALID_ACTIONS = ConsentService::ACTIONS;

    /**
     * Upper bound on an export, as a guard rather than a feature: without it
     * an unfiltered export of a multi-million-row table would run until the
     * request timed out, leaving a truncated file that looks complete. The
     * response says explicitly when it was hit.
     */
    public const EXPORT_LIMIT = 100000;

    /**
     * Whether the export currently being built hit EXPORT_LIMIT. An export is
     * consent evidence, so one that silently stops short while still looking
     * complete is worse than one that refuses — every format below states it.
     */
    private bool $_exportTruncated = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Consent records contain visitor identifiers, IP hashes, user agents
        // and country data — restrict beyond "logged into the control panel".
        // Export is checked separately in actionExport(): downloading the
        // whole set is a materially different act from reading a page of it.
        Permissions::requireAny(Permissions::VIEW_LOGS);

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $plugin  = Plugin::getInstance();

        $site    = $this->_resolveSite($request->getParam('site'));
        $filters = $this->_resolveFilters($request);

        // Bounded so an absurd page number cannot overflow the OFFSET into a
        // float that the query builder silently drops (showing page 1).
        $page = min(max(1, (int) $request->getParam('page', 1)), 1000000);

        [$records, $total] = $plugin->consent->getLogs($site?->id, $filters, $page, self::PAGE_SIZE);

        // With filters active, the chart describes the same result set as the
        // table. Unfiltered counts use the cached aggregate.
        $stats = $filters === []
            ? $plugin->statistics->getActionCounts($site?->id)
            : $plugin->consent->getStats($site?->id, $filters);
        $totalRecords = max(1, $stats['total']);

        $statsCards = [
            ['label' => Craft::t('cookie-consent-flow', 'Accept All'),    'count' => $stats['acceptAll'], 'color' => 'green'],
            ['label' => Craft::t('cookie-consent-flow', 'Reject All'),    'count' => $stats['rejectAll'], 'color' => 'red'],
            ['label' => Craft::t('cookie-consent-flow', 'Custom'),        'count' => $stats['custom'],    'color' => 'blue'],
            ['label' => Craft::t('cookie-consent-flow', 'Total Records'), 'count' => $stats['total'],     'color' => 'gray', 'isTotal' => true],
        ];

        foreach ($statsCards as $i => $card) {
            $statsCards[$i]['percent'] = !empty($card['isTotal'])
                ? ($stats['total'] > 0 ? 100 : 0)
                : round(($card['count'] / $totalRecords) * 100, 1);
        }

        // Per-category acceptance and the daily trend, over exactly the set of
        // records on screen (the same filters as the table and the chart).
        $categoryLabels = [];
        foreach ($plugin->cookieSettings->getEffectiveSettings($site?->id)->categories as $category) {
            $categoryLabels[$category['key']] = $category['label'] ?? $category['key'];
        }

        $categoryStats = [];
        foreach ($plugin->consent->getCategoryStats($site?->id, array_keys($categoryLabels), $filters) as $key => $data) {
            $categoryStats[] = $data + ['key' => $key, 'label' => $categoryLabels[$key]];
        }

        return $this->renderTemplate('cookie-consent-flow/logs/index', [
            'categoryStats' => $categoryStats,
            'trend'         => $plugin->consent->getDailyTrend($site?->id, 30, $filters),
            'plugin'      => $plugin,
            'records'     => $records,
            'total'       => $total,
            'page'        => $page,
            'totalPages'  => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'perPage'     => self::PAGE_SIZE,
            'statsCards'  => $statsCards,
            'currentSite' => $site,
            'allSites'    => Permissions::accessibleSites(),
            'filters'     => $filters,
            // Retained for templates/links written against the old variable name.
            'filter'      => $filters['action'] ?? '',
            'canExport'   => Permissions::canAny(Permissions::EXPORT_LOGS),
            'actions'     => ConsentService::ACTIONS,
            'sources'     => ConsentService::SOURCES,
            'categories'  => $plugin->cookieSettings->getEffectiveSettings($site?->id)->categories,
        ]);
    }

    public function actionView(int $id): Response
    {
        $this->requireCpRequest();

        $record = Plugin::getInstance()->consent->getLogById($id);

        if (!$record) {
            throw new NotFoundHttpException('Consent record not found.');
        }

        // The record's site must be one this user may see. A record of a
        // deleted site has no site permission to check, so it is visible to
        // admins only.
        if (Craft::$app->getSites()->getSiteById((int) $record->siteId, true) === null) {
            if (!(Craft::$app->getUser()->getIdentity()?->admin ?? false)) {
                throw new \yii\web\ForbiddenHttpException('User is not permitted to access this record.');
            }
        } else {
            Permissions::requireSite((int) $record->siteId);
        }

        // "Back" returns to the list the admin came from — same site, same
        // filters, same page — rather than to an unfiltered first page.
        $request    = Craft::$app->getRequest();
        $backParams = [];

        foreach (['site', 'filter', 'source', 'category', 'policyVersion', 'country', 'from', 'to', 'page'] as $key) {
            $value = $request->getQueryParam($key);

            if (is_string($value) && $value !== '') {
                $backParams[$key] = $value;
            }
        }

        $settings   = Plugin::getInstance()->cookieSettings->getEffectiveSettings((int) $record->siteId);
        $labels     = [];
        foreach ($settings->categories as $category) {
            $labels[$category['key']] = $category['label'] ?? $category['key'];
        }

        return $this->renderTemplate('cookie-consent-flow/logs/view', [
            'plugin'         => Plugin::getInstance(),
            'record'         => $record,
            'backUrl'        => \craft\helpers\UrlHelper::cpUrl('cookie-consent-flow/logs', $backParams),
            'categoryLabels' => $labels,
        ]);
    }

    /**
     * Downloads the filtered consent records as CSV or JSON.
     *
     * Uses exactly the same filters as the page it was launched from (see
     * ConsentService::buildQuery()). Rows are read in unbuffered batches and
     * capped at EXPORT_LIMIT in SQL, so memory is bounded by the export limit
     * rather than by the size of the table.
     */
    public function actionExport(): Response
    {
        $this->requireCpRequest();
        Permissions::requireAny(Permissions::EXPORT_LOGS);

        $request = Craft::$app->getRequest();
        $format  = $request->getParam('format') === 'json' ? 'json' : 'csv';

        $site    = $this->_resolveSite($request->getParam('site'));
        $filters = $this->_resolveFilters($request);

        $rows = $this->_collectRows($site?->id, $filters);

        $filename = sprintf(
            'consent-records-%s-%s.%s',
            $site?->handle ?? 'all-sites',
            (new \DateTimeImmutable())->format('Y-m-d'),
            $format
        );

        return $format === 'json'
            ? $this->_jsonDownload($rows, $filename)
            : $this->_csvDownload($rows, $filename);
    }

    /**
     * Materialises the export rows.
     *
     * The SQL category condition is already exact (the quoted key, compared
     * case-sensitively on both drivers — ConsentService::categoryCondition());
     * membership is checked again on the decoded array as a defence against
     * a malformed stored value, since an export must be exactly what it
     * claims to be.
     *
     * @param  array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function _collectRows(?int $siteId, array $filters): array
    {
        // Capped in SQL, one past the limit so truncation can still be
        // reported, and read through Craft's unbuffered batch helper: Yii's
        // own batch() on MySQL buffers the whole result set client-side
        // before handing over the first batch, so a large table ran the
        // request out of memory before a single row was exported.
        $query = Plugin::getInstance()->consent
            ->buildQuery($siteId, $filters)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(self::EXPORT_LIMIT + 1)
            ->asArray();

        $siteNames = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteNames[$site->id] = $site->name;
        }

        $category = $filters['category'] ?? null;
        $rows     = [];

        foreach (\craft\helpers\Db::batch($query, 500) as $batch) {
            foreach ($batch as $record) {
                $categories = Json::decodeIfJson($record['categories']);
                $categories = is_array($categories) ? $categories : [];

                if ($category !== null && !in_array($category, $categories, true)) {
                    continue;
                }

                $rows[] = [
                    'uid'           => $record['uid'],
                    'dateCreated'   => $record['dateCreated'],
                    'siteId'        => (int) $record['siteId'],
                    'site'          => $siteNames[$record['siteId']] ?? '',
                    'visitorUuid'   => $record['visitorUuid'],
                    'action'        => $record['action'],
                    'source'        => $record['source'] ?? 'banner',
                    'categories'    => $categories,
                    'policyVersion' => $record['policyVersion'] ?? '',
                    'countryCode'   => $record['countryCode'] ?? '',
                ];

                if (count($rows) >= self::EXPORT_LIMIT) {
                    $this->_exportTruncated = true;

                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * The exported columns.
     *
     * `ipHash` and `userAgent` are deliberately excluded. The hash is a keyed
     * pseudonym of the visitor's network, useful only for matching inside
     * this install, and the user-agent string is the most identifying field
     * in the table. Neither is needed to
     * evidence that a given visitor consented to a given set of categories at
     * a given time, which is what an export is for.
     *
     * @var string[]
     */
    private const EXPORT_COLUMNS = [
        'uid', 'dateCreated', 'siteId', 'site', 'visitorUuid',
        'action', 'source', 'categories', 'policyVersion', 'countryCode',
    ];

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function _csvDownload(array $rows, string $filename): Response
    {
        $handle = fopen('php://temp', 'r+');
        self::_putCsvRow($handle, self::EXPORT_COLUMNS);

        foreach ($rows as $row) {
            $row['categories'] = implode(' ', $row['categories']);
            self::_putCsvRow($handle, array_map(
                static fn(string $column): string => self::csvCell($row[$column] ?? ''),
                self::EXPORT_COLUMNS
            ));
        }

        // CSV has nowhere to put an envelope, so the statement goes in the file
        // itself, in the first column, where it cannot be scrolled past
        // unnoticed the way a header could be. Spreadsheet tools show it as a
        // final row; a parser reading the `uid` column sees a value that is
        // obviously not a uid.
        if ($this->_exportTruncated) {
            self::_putCsvRow($handle, [sprintf(
                'TRUNCATED: limited to %d records. Narrow the filters and export again to get the rest.',
                self::EXPORT_LIMIT
            )]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        // A UTF-8 BOM, so Excel opens non-ASCII site names correctly instead
        // of mojibake — the single most common complaint about CSV exports.
        return $this->_download("\xEF\xBB\xBF" . $csv, $filename, 'text/csv');
    }

    /**
     * Writes one CSV row.
     *
     * `$escape` is passed explicitly because PHP is changing its default:
     * omitting it is deprecated as of PHP 8.4/8.5 and would fill the log of
     * any install on a current PHP with a deprecation per exported row. An
     * empty string is the standards-compliant setting — it turns off PHP's
     * non-standard backslash handling, so quoting is plain RFC 4180 doubling
     * and a value containing a backslash round-trips unchanged.
     *
     * @param resource            $handle
     * @param array<int, string>  $fields
     */
    private static function _putCsvRow($handle, array $fields): void
    {
        fputcsv($handle, $fields, ',', '"', '');
    }

    /**
     * Renders one CSV cell so a spreadsheet cannot read it as a formula.
     *
     * Excel, LibreOffice and Sheets evaluate any cell whose text begins with
     * `=`, `+`, `-` or `@` (and treat a leading tab or carriage return as
     * leading whitespace before one). Several exported columns carry text an
     * administrator controls — a site name, a policy version — so a value like
     * `=HYPERLINK(...)` would execute on whoever opened the export rather than
     * being read as the name it is.
     *
     * The guard is a single leading apostrophe, the convention spreadsheets
     * already understand as "this cell is text". Deliberately narrow:
     *
     * - Numbers are left exactly as they are. Prefixing `-1` would turn a
     *   number into a string, and the export's numeric columns (`siteId`) are
     *   generated here, not supplied by anyone.
     * - Quoting is left entirely to `fputcsv()`. This returns a plain string
     *   and never writes a delimiter, quote or newline of its own, so the file
     *   stays valid CSV.
     */
    public static function csvCell(mixed $value): string
    {
        $value = (string) $value;

        if ($value === '' || is_numeric($value)) {
            return $value;
        }

        return preg_match('/^[\t\r]*[=+\-@]/', $value) ? "'" . $value : $value;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function _jsonDownload(array $rows, string $filename): Response
    {
        // `truncated` is always present, not only when true: a consumer that
        // has to look for the absence of a key to know whether a file is
        // complete will eventually forget to.
        return $this->_download(
            Json::encode([
                'exportedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'truncated'  => $this->_exportTruncated,
                'limit'      => self::EXPORT_LIMIT,
                'count'      => count($rows),
                'records'    => $rows,
            ]),
            $filename,
            'application/json'
        );
    }

    private function _download(string $body, string $filename, string $contentType): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = $body;
        $response->getHeaders()
            ->set('Content-Type', $contentType . '; charset=utf-8')
            ->set('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->set('Cache-Control', 'private, no-store');

        // Also machine-readable for anything scripting the endpoint, which
        // cannot see a trailing CSV row without parsing the whole file.
        if ($this->_exportTruncated) {
            $response->getHeaders()->set('X-Cookie-Consent-Export-Truncated', (string) self::EXPORT_LIMIT);
        }

        return $response;
    }

    /**
     * Site filter: absent, empty, or the explicit `all` sentinel all mean
     * "All Sites" (null) — the page's default. A concrete value is resolved
     * to a real site, falling back to the primary site for a stale link.
     */
    /**
     * The site the records view is scoped to, or null for "all sites" — which
     * means all the sites this user may see (see _resolveFilters()). A named
     * site the user may not see is a 403, not a silent fallback.
     */
    private function _resolveSite(mixed $param): ?\craft\models\Site
    {
        if (!is_string($param) && !is_int($param)) {
            return null;
        }

        if ($param === '' || $param === 'all') {
            return null;
        }

        $site = ConsentHelper::resolveSiteFromParam($param);
        Permissions::requireSite((int) $site->id);

        return $site;
    }

    /**
     * Reads the filter params, discarding anything that isn't a value this
     * page offers.
     *
     * Note the action filter's param is named `filter`, not `action`: Craft
     * treats any request carrying a non-empty `action` param as an
     * action-route request and would try to resolve the value as a route.
     *
     * @return array<string, mixed>
     */
    private function _resolveFilters(\craft\web\Request $request): array
    {
        $filters = [];

        $str    = static fn(mixed $v): string => is_string($v) ? $v : '';
        $action = $str($request->getParam('filter', ''));
        if (in_array($action, ConsentService::ACTIONS, true)) {
            $filters['action'] = $action;
        }

        $source = $str($request->getParam('source', ''));
        if (in_array($source, ConsentService::SOURCES, true)) {
            $filters['source'] = $source;
        }

        foreach (['policyVersion', 'category', 'from', 'to'] as $key) {
            $value = trim($str($request->getParam($key, '')));
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        $country = $request->getParam('country', '');
        $country = is_string($country) ? strtoupper(trim($country)) : '';
        if (preg_match('/^[A-Z]{2}$/D', $country)) {
            $filters['countryCode'] = $country;
        }

        // "All sites" for a non-admin on a multisite install means the sites
        // they may see — which also leaves out records of deleted sites,
        // whose access no site permission can grant.
        $site = $request->getParam('site');
        if (($site === null || $site === '' || $site === 'all')
            && count(Craft::$app->getSites()->getAllSites()) > 1
            && !(Craft::$app->getUser()->getIdentity()?->admin ?? false)) {
            $filters['siteIds'] = Permissions::accessibleSiteIds();
        }

        return $filters;
    }
}
