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

        $page = max(1, (int) $request->getParam('page', 1));

        [$records, $total] = $plugin->consent->getLogs($site?->id, $filters, $page, self::PAGE_SIZE);

        // With filters active, the summary and chart describe the same result
        // set as the table. Unfiltered counts use the cached aggregate.
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

        return $this->renderTemplate('cookie-consent-flow/logs/index', [
            'plugin'      => $plugin,
            'records'     => $records,
            'total'       => $total,
            'page'        => $page,
            'totalPages'  => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'perPage'     => self::PAGE_SIZE,
            'statsCards'  => $statsCards,
            'currentSite' => $site,
            'allSites'    => Craft::$app->getSites()->getAllSites(),
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

        return $this->renderTemplate('cookie-consent-flow/logs/view', [
            'plugin' => Plugin::getInstance(),
            'record' => $record,
        ]);
    }

    /**
     * Downloads the filtered consent records as CSV or JSON.
     *
     * Uses exactly the same filters as the page it was launched from (see
     * ConsentService::buildQuery()), streamed in batches so the response size
     * doesn't dictate memory use.
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
     * The category filter is re-applied here as an exact membership test: the
     * SQL side can only approximate it with a LIKE against the JSON column
     * (see ConsentService::buildQuery()), which narrows the scan but would
     * wrongly include a key that is a substring of another. The export must be
     * exactly what it claims to be, so the definitive check happens in PHP.
     *
     * @param  array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function _collectRows(?int $siteId, array $filters): array
    {
        $query = Plugin::getInstance()->consent
            ->buildQuery($siteId, $filters)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->asArray();

        $siteNames = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteNames[$site->id] = $site->name;
        }

        $category = $filters['category'] ?? null;
        $rows     = [];

        foreach ($query->batch(500) as $batch) {
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
     * `ipHash` and `userAgent` are deliberately excluded. The hash is salted
     * per record and so proves nothing and correlates nothing — exporting it
     * would move opaque data around for no benefit — and the user-agent string
     * is the most identifying field in the table. Neither is needed to
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
        fputcsv($handle, self::EXPORT_COLUMNS);

        foreach ($rows as $row) {
            $row['categories'] = implode(' ', $row['categories']);
            fputcsv($handle, array_map(
                static fn(string $column): string => (string) ($row[$column] ?? ''),
                self::EXPORT_COLUMNS
            ));
        }

        // CSV has nowhere to put an envelope, so the statement goes in the file
        // itself, in the first column, where it cannot be scrolled past
        // unnoticed the way a header could be. Spreadsheet tools show it as a
        // final row; a parser reading the `uid` column sees a value that is
        // obviously not a uid.
        if ($this->_exportTruncated) {
            fputcsv($handle, [sprintf(
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
    private function _resolveSite(mixed $param): ?\craft\models\Site
    {
        return ($param !== null && $param !== '' && $param !== 'all')
            ? ConsentHelper::resolveSiteFromParam($param)
            : null;
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

        $action = (string) $request->getParam('filter', '');
        if (in_array($action, ConsentService::ACTIONS, true)) {
            $filters['action'] = $action;
        }

        $source = (string) $request->getParam('source', '');
        if (in_array($source, ConsentService::SOURCES, true)) {
            $filters['source'] = $source;
        }

        foreach (['policyVersion', 'category', 'from', 'to'] as $key) {
            $value = trim((string) $request->getParam($key, ''));
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        $country = strtoupper(trim((string) $request->getParam('country', '')));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $filters['countryCode'] = $country;
        }

        return $filters;
    }
}
