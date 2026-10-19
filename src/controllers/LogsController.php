<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\helpers\Permissions;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;
use sfsinfotech\craftcookieconsentflow\services\UserLinkService;
use yii\web\BadRequestHttpException;
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

    /** @var string[] The columns of the export being built; see EXPORT_COLUMNS. */
    private array $_exportColumns = self::EXPORT_COLUMNS;

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
        $query   = $filters + $this->_scopeFilters($site);

        // Bounded so an absurd page number cannot overflow the OFFSET into a
        // float that the query builder silently drops (showing page 1).
        $page = min(max(1, (int) $request->getParam('page', 1)), 1000000);

        [$records, $total] = $plugin->consent->getLogs($site?->id, $query, $page, self::PAGE_SIZE);

        // With filters active, the chart describes the same result set as the
        // table. Unfiltered counts use the cached aggregate — but only where
        // that aggregate is exactly the user's scope: one named site, or
        // every site for a user who may see them all. "All Sites" for a
        // restricted user is a subset no cached total describes.
        $stats = $filters === [] && ($site !== null || $query === [])
            ? $plugin->statistics->getActionCounts($site?->id)
            : $plugin->consent->getStats($site?->id, $query);
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

        // Per-category acceptance, over exactly the set of records on screen
        // (the same filters as the table and the chart).
        $categoryLabels = [];
        foreach ($plugin->cookieSettings->getEffectiveSettings($site?->id)->categories as $category) {
            $categoryLabels[$category['key']] = $category['label'] ?? $category['key'];
        }

        $categoryStats = [];
        foreach ($plugin->consent->getCategoryStats($site?->id, array_keys($categoryLabels), $query) as $key => $data) {
            $categoryStats[] = $data + ['key' => $key, 'label' => $categoryLabels[$key]];
        }

        [$users, $matchTypes, $filterUser] = $this->_userContext($records, $filters);

        return $this->renderTemplate('cookie-consent-flow/logs/index', [
            'categoryStats' => $categoryStats,
            'canViewUsers'  => self::canViewUsers(),
            'users'         => $users,
            'matchTypes'    => $matchTypes,
            'filterUser'    => $filterUser,
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
            'canDelete'   => Permissions::canAny(Permissions::DELETE_LOGS),
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

        foreach (['site', 'filter', 'source', 'category', 'policyVersion', 'country', 'visitor', 'user', 'from', 'to', 'page'] as $key) {
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
            'canDelete'      => Permissions::canAny(Permissions::DELETE_LOGS),
            'canViewUsers'   => self::canViewUsers(),
            'recordAttached' => UserLinkService::wasAttached($record->dateCreated, $record->dateUpdated),
            'recordUser'     => self::canViewUsers() && $record->hasAttribute('userId') && $record->userId
                ? UserLinkService::findUser((string) $record->userId)
                : null,
            'linkedUsers'    => self::canViewUsers()
                ? array_values(array_filter(array_map(
                    static fn(int $id): ?\craft\elements\User => UserLinkService::findUser((string) $id),
                    Plugin::getInstance()->userLinks->usersForVisitorIds([(string) $record->visitorUuid])[(string) $record->visitorUuid] ?? []
                )))
                : [],
        ]);
    }

    /**
     * Whether the current user may see which Craft user a record belongs to,
     * and search by user: only with linking on, and only for someone who may
     * see Craft users anyway. Viewing consent records alone never opens the
     * user directory.
     */
    public static function canViewUsers(): bool
    {
        if (!Plugin::getInstance()->userLinks->isEnabled()) {
            return false;
        }

        $user = Craft::$app->getUser();

        return ($user->getIdentity()?->admin ?? false) || $user->checkPermission('editUsers');
    }

    /** The site restriction for the current user, for callers outside a request to this controller. */
    public static function scopeFiltersForCurrentUser(): array
    {
        $isAdmin = Craft::$app->getUser()->getIdentity()?->admin ?? false;

        return self::scopeFilters(null, count(Craft::$app->getSites()->getAllSites()), $isAdmin, Permissions::accessibleSiteIds());
    }

    /**
     * The users the records on screen belong to, and — when the list is one
     * user's records — how each record belongs to them.
     *
     * @param array<int, mixed>    $records
     * @param array<string, mixed> $filters
     * @return array{0: array<int, \craft\elements\User>, 1: array<int, string>, 2: ?\craft\elements\User}
     */
    private function _userContext(array $records, array $filters): array
    {
        if (!self::canViewUsers()) {
            return [[], [], null];
        }

        $users = [];
        foreach ($records as $record) {
            $id = (int) ($record['userId'] ?? 0);
            if ($id > 0 && !isset($users[$id])) {
                $users[$id] = UserLinkService::findUser((string) $id);
            }
        }

        $filterUserId = (int) ($filters['userId'] ?? 0);
        $filterUser   = $filterUserId > 0 ? UserLinkService::findUser((string) $filterUserId) : null;
        $matchTypes   = [];

        if ($filterUser !== null) {
            $visitorIds = array_values(array_unique(array_map(static fn($r): string => (string) $r['visitorUuid'], $records)));
            $byVisitor  = Plugin::getInstance()->userLinks->usersForVisitorIds($visitorIds);

            foreach ($records as $record) {
                $matchTypes[(int) $record['id']] = UserLinkService::matchType(
                    isset($record['userId']) ? (int) $record['userId'] : null,
                    (string) $record['visitorUuid'],
                    $filterUserId,
                    $byVisitor,
                    UserLinkService::wasAttached($record['dateCreated'] ?? null, $record['dateUpdated'] ?? null)
                );
            }
        }

        return [array_filter($users), $matchTypes, $filterUser];
    }

    /**
     * Deletes one user's consent records: those made while they were logged
     * in, and — when asked — the anonymous records of browsers linked to them
     * only. Anonymous records of a browser other users were also seen on are
     * never deleted this way. Limited to the sites the user may access, or to
     * the posted site, and logged.
     */
    public function actionDeleteUser(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        Permissions::requireAny(Permissions::DELETE_LOGS);

        if (!self::canViewUsers()) {
            throw new \yii\web\ForbiddenHttpException('User is not permitted to perform this action.');
        }

        $request = Craft::$app->getRequest();
        $userId  = (int) $request->getRequiredBodyParam('userId');

        if ($userId <= 0 || UserLinkService::findUser((string) $userId) === null) {
            throw new BadRequestHttpException('Invalid user.');
        }

        $browsersToo = (bool) $request->getBodyParam('includeBrowsers');
        $isAdmin     = Craft::$app->getUser()->getIdentity()?->admin ?? false;
        $site        = $this->_resolveSite($request->getBodyParam('site'));
        $siteIds     = $site !== null ? [(int) $site->id] : self::deletableSiteIds($isAdmin, Permissions::accessibleSiteIds());

        $deleted = Plugin::getInstance()->consent->deleteUserRecords($userId, $browsersToo, $siteIds);

        Craft::warning(
            "Cookie consent records deleted for user #{$userId}: {$deleted} record(s)"
            . ($browsersToo ? ' including their browsers\' anonymous records' : '')
            . ($siteIds === null ? ' on every site' : ' on site(s) #' . implode(', #', $siteIds))
            . ', by user #' . (Craft::$app->getUser()->getId() ?? 0),
            __METHOD__
        );

        Craft::$app->getSession()->setSuccess(Craft::t('cookie-consent-flow', 'Deleted {count, plural, =1{# consent record} other{# consent records}} for this user.', [
            'count' => $deleted,
        ]));

        return $this->redirectToPostedUrl(null, 'cookie-consent-flow/logs');
    }

    /**
     * Deletes every consent record of one visitor ID — the erasure side of a
     * data subject request.
     *
     * Limited to the sites this user may access, exactly like viewing: the
     * same ID can carry records on several sites (one site group sharing
     * consent), and a user restricted to one site must not remove another
     * site's evidence. Records of a deleted site belong to no site permission,
     * so only an admin removes those. A posted `site` narrows it to that one
     * site, matching the list the deletion was started from.
     *
     * The result never mentions records on sites the user cannot access —
     * not even how many there are.
     *
     * Each deletion is written to the Craft log with the acting user, because
     * the records themselves no longer exist to show it happened.
     */
    public function actionDeleteVisitor(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        Permissions::requireAny(Permissions::DELETE_LOGS);

        $request   = Craft::$app->getRequest();
        $visitorId = ConsentHelper::normalizeVisitorId($request->getRequiredBodyParam('visitorId'));

        if ($visitorId === null) {
            throw new BadRequestHttpException('Invalid visitor ID.');
        }

        $isAdmin = Craft::$app->getUser()->getIdentity()?->admin ?? false;
        $site    = $this->_resolveSite($request->getBodyParam('site'));
        $siteIds = $site !== null
            ? [(int) $site->id]
            : self::deletableSiteIds($isAdmin, Permissions::accessibleSiteIds());

        $deleted = Plugin::getInstance()->consent->deleteVisitorRecords($visitorId, $siteIds);

        Craft::warning(
            "Cookie consent records deleted for visitor {$visitorId}: {$deleted} record(s)"
            . ($siteIds === null ? ' on every site' : ' on site(s) #' . implode(', #', $siteIds))
            . ', by user #' . (Craft::$app->getUser()->getId() ?? 0),
            __METHOD__
        );

        $message = Craft::t('cookie-consent-flow', 'Deleted {count, plural, =1{# consent record} other{# consent records}} for this visitor.', [
            'count' => $deleted,
        ]);

        Craft::$app->getSession()->setSuccess($message);

        return $this->redirectToPostedUrl(null, 'cookie-consent-flow/logs');
    }

    /**
     * The sites a deletion may reach: every site, including deleted ones,
     * for an admin; otherwise the sites the user may access.
     *
     * @param  int[] $accessibleSiteIds
     * @return int[]|null Null for every site.
     */
    public static function deletableSiteIds(bool $isAdmin, array $accessibleSiteIds): ?array
    {
        return $isAdmin ? null : array_values(array_map('intval', $accessibleSiteIds));
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
        $filters = $this->_resolveFilters($request) + $this->_scopeFilters($site);

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
            ->orderBy(ConsentService::LIST_ORDER)
            ->limit(self::EXPORT_LIMIT + 1)
            ->asArray();

        // Which user a record belongs to is shown only to those who may see
        // users. A user's own export also says how each record is theirs.
        $showUsers    = self::canViewUsers();
        $filterUserId = $showUsers ? (int) ($filters['userId'] ?? 0) : 0;
        $userLinks    = Plugin::getInstance()->userLinks;
        $byVisitor    = $filterUserId > 0 ? $userLinks->usersForVisitorIds($userLinks->visitorIdsForUser($filterUserId)) : [];

        if ($showUsers) {
            $this->_exportColumns = array_merge(self::EXPORT_COLUMNS, $filterUserId > 0 ? ['userId', 'match'] : ['userId']);
        }

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

                if ($showUsers) {
                    $rows[count($rows) - 1]['userId'] = isset($record['userId']) ? (int) $record['userId'] : '';
                }

                if ($filterUserId > 0) {
                    $rows[count($rows) - 1]['match'] = UserLinkService::matchType(
                        isset($record['userId']) ? (int) $record['userId'] : null,
                        (string) $record['visitorUuid'],
                        $filterUserId,
                        $byVisitor,
                        UserLinkService::wasAttached($record['dateCreated'] ?? null, $record['dateUpdated'] ?? null)
                    );
                }

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
        self::_putCsvRow($handle, $this->_exportColumns);

        foreach ($rows as $row) {
            $row['categories'] = implode(' ', $row['categories']);
            self::_putCsvRow($handle, array_map(
                static fn(string $column): string => self::csvCell($row[$column] ?? ''),
                $this->_exportColumns
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
     * The site the records view is scoped to, or null for "all sites" — which
     * means all the sites this user may see (see _scopeFilters()). A named
     * site the user may not see is a 403, not a silent fallback.
     *
     * Absent, empty, or the explicit `all` sentinel mean "All Sites". Any
     * other non-scalar value (`site[]=…`) is refused rather than read as
     * "All Sites": it used to be, while the site restriction was decided from
     * the raw parameter, so the two disagreed and the restriction was skipped.
     */
    private function _resolveSite(mixed $param): ?\craft\models\Site
    {
        if ($param === null || $param === '' || $param === 'all') {
            return null;
        }

        if (!is_string($param) && !is_int($param)) {
            throw new BadRequestHttpException('Invalid site.');
        }

        $site = ConsentHelper::resolveSiteFromParam($param);
        Permissions::requireSite((int) $site->id);

        return $site;
    }

    /**
     * The site restriction every records query carries, derived from the
     * *resolved* scope — never from the raw request — so no request shape can
     * widen it. A concrete site has already passed requireSite(); "All Sites"
     * for anyone who cannot see every site means the sites they may see,
     * which also leaves out records of deleted sites, whose access no site
     * permission can grant.
     *
     * @return array{siteIds?: int[]}
     */
    private function _scopeFilters(?\craft\models\Site $site): array
    {
        $isAdmin = Craft::$app->getUser()->getIdentity()?->admin ?? false;

        return self::scopeFilters($site?->id, count(Craft::$app->getSites()->getAllSites()), $isAdmin, Permissions::accessibleSiteIds());
    }

    /**
     * Pure form of _scopeFilters(), so the rule can be verified without a
     * user session.
     *
     * @param  int[] $accessibleSiteIds
     * @return array{siteIds?: int[]}
     */
    public static function scopeFilters(?int $siteId, int $siteCount, bool $isAdmin, array $accessibleSiteIds): array
    {
        if ($isAdmin || $siteCount <= 1) {
            return [];
        }

        // A concrete site scopes the query itself (and has passed
        // requireSite()); the restriction still names it, so that a site the
        // user cannot see matches nothing even if that check were bypassed.
        if ($siteId !== null) {
            return ['siteIds' => in_array($siteId, $accessibleSiteIds, true) ? [$siteId] : []];
        }

        return ['siteIds' => array_values(array_map('intval', $accessibleSiteIds))];
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

        // Kept even when it is not a visitor ID, so the search shows no
        // records instead of quietly showing all of them.
        $visitor = trim($str($request->getParam('visitor', '')));
        if ($visitor !== '') {
            $filters['visitorUuid'] = ConsentHelper::normalizeVisitorId($visitor) ?? $visitor;
        }

        // A user, by ID, username or email — only for those who may see
        // users. Unknown matches nothing (0), rather than everything.
        $user = trim($str($request->getParam('user', '')));
        if ($user !== '' && self::canViewUsers()) {
            $found = UserLinkService::findUser($user);

            $filters['user']   = $user;
            $filters['userId'] = $found?->id ? (int) $found->id : 0;
        }

        // The site restriction is deliberately not added here: these are
        // the filters the visitor chose, shown back on the page. The
        // restriction comes from _scopeFilters() and is applied to every
        // query separately, so it can never be mistaken for a user filter.
        return $filters;
    }
}
