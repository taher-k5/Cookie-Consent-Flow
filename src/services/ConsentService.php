<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use yii\db\ActiveQuery;
use yii\db\Expression;
use sfsinfotech\craftcookieconsentflow\events\AfterConsentSaveEvent;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\records\ConsentLogRecord;

/**
 * Consent Service — handles recording, retrieving, and managing consent records.
 */
class ConsentService extends Component
{
    /** A decision the visitor made in the banner or preference centre. */
    public const SOURCE_BANNER = 'banner';

    /** Derived from `navigator.globalPrivacyControl`, without showing the banner. */
    public const SOURCE_GPC = 'gpc';

    /** Derived from the legacy Do Not Track header, without showing the banner. */
    public const SOURCE_DNT = 'dnt';

    /** Set programmatically through the JavaScript API. */
    public const SOURCE_API = 'api';

    /** @var string[] Every accepted value for a record's `source`. */
    public const SOURCES = [self::SOURCE_BANNER, self::SOURCE_GPC, self::SOURCE_DNT, self::SOURCE_API];

    /** Every accepted value for a record's `action`. */
    public const ACTIONS = ['accept_all', 'reject_all', 'custom'];

    /**
     * Persists a visitor's consent choice and fires the AfterConsentSave event.
     *
     * @param  string   $action     'accept_all' | 'reject_all' | 'custom'
     * @param  string[] $categories Category keys the visitor accepted.
     * @param  string   $source     How the decision was reached — one of the SOURCE_* constants.
     * @return array{visitorUuid: string, action: string, categories: string[], siteId: int}
     */
    public function saveConsent(string $action, array $categories, string $source = self::SOURCE_BANNER): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $request  = Craft::$app->getRequest();
        $siteId   = Craft::$app->getSites()->getCurrentSite()->id;

        // Resolve visitor UUID: prefer this site's own namespaced cookie,
        // falling back once to the legacy unnamespaced cookie (pre-dates
        // multi-site visitor namespacing) so existing visitors aren't
        // treated as brand new after upgrading.
        //
        // Guarded on the request type rather than assumed: a console request
        // has no cookies at all, and calling getCookies() on one throws. That
        // matters because this method is the whole plugin's single entry point
        // for recording consent, so it has to remain callable from a queue job
        // or a command (seeding, a bulk import) and not only from the browser.
        $visitorUuid = null;

        if ($request instanceof \craft\web\Request) {
            $visitorUuid = $request->getCookies()->getValue(ConsentHelper::visitorCookieName($siteId))
                ?? $request->getCookies()->getValue(ConsentHelper::LEGACY_VISITOR_COOKIE);
        }

        $visitorUuid ??= ConsentHelper::generateVisitorUuid();

        // Notification only: listeners can react (push to a data layer, kick
        // off an integration) but cannot change or veto what is recorded. The
        // event carries no cancel flag and nothing below consults one — a
        // record of consent is evidence, and letting a listener suppress it
        // would make the log a claim about what listeners allowed rather than
        // about what the visitor did.
        $event = new AfterConsentSaveEvent([
            'action'      => $action,
            'categories'  => $categories,
            'visitorUuid' => $visitorUuid,
            'source'      => $source,
            'siteId'      => $siteId,
        ]);
        Plugin::getInstance()->trigger(
            Plugin::EVENT_AFTER_CONSENT_SAVE,
            $event
        );

        if ($settings->logEnabled) {
            $record              = new ConsentLogRecord();
            $record->visitorUuid = $visitorUuid;
            $record->ipHash      = ConsentHelper::hashIp(
                $request instanceof \craft\web\Request ? ($request->getRemoteIP() ?? '') : ''
            );
            $record->siteId      = $siteId;
            $record->categories    = Json::encode($categories);
            $record->action        = $action;
            $record->policyVersion = $settings->policyVersion;
            $record->source        = $source;
            $record->countryCode   = Plugin::getInstance()->geo->getCountryCode();
            $record->userAgent   = $request instanceof \craft\web\Request
                ? mb_substr((string) $request->getHeaders()->get('user-agent', ''), 0, 500)
                : '';
            if (!$record->save()) {
                Craft::error(
                    'Cookie consent log save failed: ' .
                    Json::encode($record->getErrors()),
                    __METHOD__
                );
            } else {
                Plugin::getInstance()->statistics->invalidate();
            }
        }

        return [
            'visitorUuid' => $visitorUuid,
            'action'      => $action,
            'categories'  => $categories,
            'siteId'      => $siteId,
        ];
    }

    /**
     * Returns the most recent consent record for a visitor UUID on a given
     * site, or null. Site-scoped so a visitor's consent on one site is
     * never reported as consent on another (defense-in-depth alongside the
     * per-site visitor cookie namespacing in ConsentController).
     *
     * @return array{action: string, categories: string[], dateCreated: string}|null
     */
    public function getConsent(string $visitorUuid, ?int $siteId = null): ?array
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        $record = ConsentLogRecord::find()
            ->where(['visitorUuid' => $visitorUuid, 'siteId' => $siteId])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        if (!$record) {
            return null;
        }

        return [
            'action'      => $record->action,
            'categories'  => Json::decode($record->categories),
            'dateCreated' => $record->dateCreated,
        ];
    }

    /**
     * Returns aggregated consent statistics for a site, or across every site
     * when `$siteId` is null — mirrors `getLogs()`'s convention so a "null
     * site" consistently means "All Sites" throughout the CP, rather than
     * silently defaulting to the current site.
     *
     * One grouped query rather than four COUNTs: the dashboard, the widget
     * and the log page all call this on every render, and the previous
     * four-query version cost four full index scans where one suffices.
     *
     * @param array<string, mixed> $filters Optional record filters; see buildQuery().
     * @return array{total: int, acceptAll: int, rejectAll: int, custom: int}
     */
    public function getStats(?int $siteId = null, array $filters = []): array
    {
        $rows = $this->buildQuery($siteId, $filters)
            ->select(['action', 'c' => 'COUNT(*)'])
            ->groupBy(['action'])
            ->asArray()
            ->all();

        $byAction = array_column($rows, 'c', 'action');

        $stats = [
            'acceptAll' => (int) ($byAction['accept_all'] ?? 0),
            'rejectAll' => (int) ($byAction['reject_all'] ?? 0),
            'custom'    => (int) ($byAction['custom'] ?? 0),
        ];

        return ['total' => array_sum($stats)] + $stats;
    }

    /**
     * Acceptance rate per category: how many records include each category
     * key, out of how many records there are.
     *
     * `categories` is a JSON array column, so this cannot be a GROUP BY — the
     * rows are scanned in batches and tallied in PHP. Batching (rather than
     * ->all()) is what keeps memory flat regardless of table size; the cost
     * is proportional to the number of records, so the result is cached by
     * the caller (see StatisticsService) rather than recomputed per render.
     *
     * @param  string[] $categoryKeys Keys to report on, in display order.
     * @return array<string, array{count: int, percent: float}>
     */
    public function getCategoryStats(?int $siteId, array $categoryKeys): array
    {
        $counts = array_fill_keys($categoryKeys, 0);
        $total  = 0;

        $query = $this->_baseQuery($siteId)->select(['categories'])->asArray();

        foreach ($query->batch(500) as $rows) {
            foreach ($rows as $row) {
                $total++;
                $accepted = Json::decodeIfJson($row['categories']);

                if (!is_array($accepted)) {
                    continue;
                }

                foreach ($accepted as $key) {
                    if (array_key_exists($key, $counts)) {
                        $counts[$key]++;
                    }
                }
            }
        }

        $result = [];
        foreach ($counts as $key => $count) {
            $result[$key] = [
                'count'   => $count,
                'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }

        return $result;
    }

    /**
     * Daily consent totals for the last `$days` days, oldest first — the
     * dashboard's trend line. Grouped in SQL by date so the result set is at
     * most one row per day regardless of how many records exist.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public function getDailyTrend(?int $siteId, int $days = 30): array
    {
        $since = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d 00:00:00');

        $rows = $this->_baseQuery($siteId)
            ->andWhere(['>=', 'dateCreated', $since])
            ->select(['d' => new Expression('DATE([[dateCreated]])'), 'c' => 'COUNT(*)'])
            ->groupBy([new Expression('DATE([[dateCreated]])')])
            ->orderBy(['d' => SORT_ASC])
            ->asArray()
            ->all();

        return array_map(
            static fn(array $row): array => ['date' => (string) $row['d'], 'count' => (int) $row['c']],
            $rows
        );
    }

    /**
     * Returns a paginated list of consent records for the CP log viewer.
     *
     * @param  array<string, mixed> $filters See buildQuery() for the accepted keys.
     * @return array{0: ConsentLogRecord[], 1: int}  [records, totalCount]
     */
    public function getLogs(?int $siteId, array|string $filters = [], int $page = 1, int $perPage = 50): array
    {
        // Backwards compatibility: this used to take a bare action-filter
        // string as its second argument. Existing callers (and any site code
        // calling into the service directly) keep working.
        if (is_string($filters)) {
            $filters = $filters !== '' ? ['action' => $filters] : [];
        }

        $query = $this->buildQuery($siteId, $filters);

        $total   = (int) (clone $query)->count();
        $records = $query
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->all();

        return [$records, $total];
    }

    /**
     * Builds a filtered consent-record query without executing it, so the log
     * viewer and the exporter apply exactly the same filters — an export that
     * silently covered a different set of records than the screen it was
     * launched from would be worse than no export at all.
     *
     * Accepted filters: `action`, `source`, `policyVersion`, `countryCode`,
     * `category` (records that include this category key), `from` / `to`
     * (dates). Unknown keys and empty values are ignored.
     *
     * @param array<string, mixed> $filters
     */
    public function buildQuery(?int $siteId, array $filters = []): ActiveQuery
    {
        $query = $this->_baseQuery($siteId);

        if (!empty($filters['action']) && in_array($filters['action'], self::ACTIONS, true)) {
            $query->andWhere(['action' => $filters['action']]);
        }

        if (!empty($filters['source']) && in_array($filters['source'], self::SOURCES, true)) {
            $query->andWhere(['source' => $filters['source']]);
        }

        if (!empty($filters['policyVersion'])) {
            $query->andWhere(['policyVersion' => (string) $filters['policyVersion']]);
        }

        if (!empty($filters['countryCode'])) {
            $query->andWhere(['countryCode' => strtoupper((string) $filters['countryCode'])]);
        }

        if (($from = $this->_normalizeDate($filters['from'] ?? null, '00:00:00')) !== null) {
            $query->andWhere(['>=', 'dateCreated', $from]);
        }

        if (($to = $this->_normalizeDate($filters['to'] ?? null, '23:59:59')) !== null) {
            $query->andWhere(['<=', 'dateCreated', $to]);
        }

        if (!empty($filters['category'])) {
            // `categories` is a JSON array of keys. A LIKE on the quoted key
            // is an approximation, not a JSON query — it is deliberately
            // paired with the exact in-PHP check in the exporter, so a key
            // that is a substring of another ('ads' vs 'ads_extra') narrows
            // the scan here but never decides the result.
            $query->andWhere(['like', 'categories', '"' . (string) $filters['category'] . '"']);
        }

        return $query;
    }

    /**
     * Deletes consent records older than the retention period.
     *
     * @param int|null $days   Override the configured retention. Null uses the
     *                         configured value; 0 (configured or passed) means
     *                         "keep indefinitely" and deletes nothing.
     * @param int|null $siteId Restrict to one site, or null for every site.
     * @param bool     $dryRun Count what would be deleted without deleting it.
     * @return int Number of records deleted (or that would be).
     */
    public function purgeOldLogs(?int $days = null, ?int $siteId = null, bool $dryRun = false): int
    {
        $days ??= Plugin::getInstance()->getSettings()->logRetentionDays;

        $cutoff = self::retentionCutoff($days);

        if ($cutoff === null) {
            return 0;
        }

        $condition = ['and', ['<', 'dateCreated', $cutoff]];

        if ($siteId !== null) {
            $condition[] = ['siteId' => $siteId];
        }

        if ($dryRun) {
            return (int) ConsentLogRecord::find()->where($condition)->count();
        }

        $deleted = (int) ConsentLogRecord::deleteAll($condition);

        if ($deleted > 0) {
            Plugin::getInstance()->statistics->invalidate();
        }

        return $deleted;
    }

    /**
     * The datetime before which records are past retention, or null when
     * nothing should be deleted.
     *
     * Separated out and made static so the arithmetic can be verified
     * directly. A retention cutoff is exactly the kind of calculation that
     * fails silently: an off-by-one or a sign error deletes the wrong records
     * and reports success. Zero means "keep indefinitely"; a negative value
     * would put the cutoff in the *future* and match every record, so it is
     * rejected rather than trusted.
     */
    public static function retentionCutoff(int $days, ?\DateTimeImmutable $now = null): ?string
    {
        if ($days <= 0) {
            return null;
        }

        return ($now ?? new \DateTimeImmutable())
            ->modify("-{$days} days")
            ->format('Y-m-d H:i:s');
    }

    public function getLogById(int $id): ?ConsentLogRecord
    {
        return ConsentLogRecord::findOne($id);
    }

    /**
     * Base query honouring the "null site means every site" convention shared
     * by every read method here.
     */
    private function _baseQuery(?int $siteId): ActiveQuery
    {
        $query = ConsentLogRecord::find();

        return $siteId !== null ? $query->where(['siteId' => $siteId]) : $query;
    }

    /**
     * Accepts a `Y-m-d` date (what the CP date field posts) or a full
     * datetime, and returns a datetime string. An unparseable value yields
     * null, which buildQuery() treats as no filter rather than as "match
     * nothing" — a malformed date in a URL should not silently produce an
     * empty, apparently-authoritative export.
     */
    private function _normalizeDate(mixed $value, string $timeFallback): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' ' . $timeFallback;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
