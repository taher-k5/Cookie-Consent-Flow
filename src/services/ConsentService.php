<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use yii\db\ActiveQuery;
use yii\db\Expression;
use sfsinfotech\craftcookieconsentflow\events\AfterConsentSaveEvent;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\helpers\PluginConfig;
use sfsinfotech\craftcookieconsentflow\helpers\Throttle;
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
     * Records a visitor's consent decision, then announces it.
     *
     * ## Order
     *
     * 1. The decision is normalised against the site's configuration (see
     *    {@see normalizeDecision()}).
     * 2. With logging disabled, nothing is written and nothing is announced:
     *    no record exists, so no visitor identifier is minted either.
     * 3. The record is saved. A save that fails throws — it used to be logged
     *    and then reported to the visitor as a success, so a lost record
     *    looked exactly like a kept one and was never retried.
     * 4. The statistics cache is invalidated.
     * 5. {@see Plugin::EVENT_AFTER_CONSENT_SAVE} fires, carrying the record.
     *
     * The event used to fire first, before anything was written. A listener
     * that threw therefore stopped the record being written at all, and the
     * event was announced for saves that then failed and for installs with
     * logging off — so "after consent save" described something that had not
     * happened.
     *
     * Steps 4 and 5 run after the evidence is committed and cannot undo it,
     * so a failure in either is logged rather than propagated: the visitor's
     * request succeeded, and answering it with a 500 would make the runtime
     * queue and re-send a decision that is already recorded, writing a
     * duplicate.
     *
     * @param  string   $action     'accept_all' | 'reject_all' | 'custom'
     * @param  string[] $categories Category keys the visitor accepted.
     * @param  string   $source     How the decision was reached — one of the SOURCE_* constants.
     * @param  ?string  $policyVersion The policy version the visitor's page showed; null for the current one.
     * @return array{visitorUuid: ?string, action: string, categories: string[], siteId: int, recorded: bool, recordId: ?int}
     * @throws \InvalidArgumentException for an unknown action.
     * @throws \RuntimeException when logging is on and the record cannot be saved.
     */
    /**
     * The shape of a policy version a client may report: what
     * SettingsService::nextPolicyVersion() and the settings field produce.
     */
    public const POLICY_VERSION_PATTERN = '/^[A-Za-z0-9._:\-]{1,50}$/D';

    public function saveConsent(string $action, array $categories, string $source = self::SOURCE_BANNER, ?string $policyVersion = null): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown consent action '{$action}'.");
        }

        if (!in_array($source, self::SOURCES, true)) {
            $source = self::SOURCE_BANNER;
        }

        $plugin   = Plugin::getInstance();
        $request  = Craft::$app->getRequest();
        $siteId   = Craft::$app->getSites()->getCurrentSite()->id;
        $settings = $plugin->cookieSettings->getEffectiveSettings($siteId);

        ['action' => $action, 'categories' => $categories] = self::normalizeDecision(
            $action,
            $categories,
            $settings->getCategoryKeys(),
            $settings->getLockedCategoryKeys()
        );

        if (!$settings->logEnabled) {
            return [
                'visitorUuid' => null,
                'action'      => $action,
                'categories'  => $categories,
                'siteId'      => $siteId,
                'recorded'    => false,
                'recordId'    => null,
            ];
        }

        // Resolve the visitor UUID from this site's own cookie only. The
        // un-namespaced cookie of pre-multisite development builds is not
        // read: on a shared origin it would link one site's records to a
        // visitor identity issued by another.
        //
        // Guarded on the request type rather than assumed: a console request
        // has no cookies at all, and calling getCookies() on one throws. That
        // matters because this method is the whole plugin's single entry point
        // for recording consent, so it has to remain callable from a queue job
        // or a command (seeding, a bulk import) and not only from the browser.
        $visitorUuid = null;

        if ($request instanceof \craft\web\Request) {
            $visitorUuid = $request->getCookies()->getValue(ConsentHelper::visitorCookieName($siteId));
        }

        if (!is_string($visitorUuid) || !preg_match('/^[0-9a-f-]{36}$/iD', $visitorUuid)) {
            $visitorUuid = ConsentHelper::generateVisitorUuid();
        }

        $record                = new ConsentLogRecord();
        $record->visitorUuid   = $visitorUuid;
        $record->ipHash        = ConsentHelper::hashIp(
            $request instanceof \craft\web\Request ? Throttle::resolveIp($request) : ''
        );
        $record->siteId        = $siteId;
        $record->categories    = Json::encode($categories);
        $record->action        = $action;
        // The version the visitor was shown, not whatever is current by the
        // time the request arrives. With full-page caching those differ right
        // after "Invalidate Existing Consent": a page cached earlier still
        // shows — and the visitor agrees to — the previous policy, and
        // stamping the new version on that record would claim consent to a
        // policy they never saw. The client's claim only ever describes its
        // own record, and its shape is validated.
        $record->policyVersion = $policyVersion !== null && preg_match(self::POLICY_VERSION_PATTERN, $policyVersion)
            ? $policyVersion
            : $settings->policyVersion;
        $record->source        = $source;
        $record->countryCode   = $plugin->geo->getCountryCode();
        $record->userAgent     = $request instanceof \craft\web\Request
            ? mb_substr((string) $request->getHeaders()->get('user-agent', ''), 0, 500)
            : '';

        if (!$record->save()) {
            Craft::error('Cookie consent log save failed: ' . Json::encode($record->getErrors()), __METHOD__);

            throw new \RuntimeException('The consent record could not be saved.');
        }

        try {
            $plugin->statistics->invalidate();
        } catch (\Throwable $e) {
            // The cached aggregates expire on their own (StatisticsService
            // TTL); a stale dashboard for a few minutes is the whole cost.
            Craft::warning('Cookie consent statistics could not be invalidated: ' . $e->getMessage(), __METHOD__);
        }

        // Notification only: listeners can react (push to a data layer, kick
        // off an integration) but cannot change or veto what is recorded —
        // the record already exists by the time they hear about it.
        $event = new AfterConsentSaveEvent([
            'action'      => $action,
            'categories'  => $categories,
            'visitorUuid' => $visitorUuid,
            'source'      => $source,
            'siteId'      => $siteId,
            'recordId'    => (int) $record->id,
        ]);

        try {
            $plugin->trigger(Plugin::EVENT_AFTER_CONSENT_SAVE, $event);
        } catch (\Throwable $e) {
            Craft::error(
                'A ' . Plugin::EVENT_AFTER_CONSENT_SAVE . ' listener failed (the consent record #' . $record->id
                . ' was saved): ' . $e->getMessage(),
                __METHOD__
            );
        }

        return [
            'visitorUuid' => $visitorUuid,
            'action'      => $action,
            'categories'  => $categories,
            'siteId'      => $siteId,
            'recorded'    => true,
            'recordId'    => (int) $record->id,
        ];
    }

    /**
     * Makes a posted decision internally consistent with the site's
     * configuration, so a record can never claim more consent than the
     * visitor gave or contradict itself.
     *
     * - Only categories the site has are kept; locked ones are always added.
     * - `accept_all` means every optional category. A payload that says
     *   `accept_all` but lacks some — a page cached before the admin added a
     *   category, or a hand-crafted request — is recorded as `custom` with
     *   exactly what was sent. Recording "all" would claim consent to a
     *   category the visitor was never shown.
     * - `reject_all` means no optional category. A payload that says
     *   `reject_all` but includes some is recorded as a rejection with the
     *   locked categories only: the visitor's stated intent was to refuse,
     *   and of the two readings that is the one that records less consent.
     * - `custom` accepts any combination, including all or none — it records
     *   that the visitor used the preference centre.
     *
     * @param string[] $categories
     * @param string[] $known
     * @param string[] $locked
     * @return array{action: string, categories: string[]}
     */
    public static function normalizeDecision(string $action, array $categories, array $known, array $locked): array
    {
        $categories = array_values(array_intersect(
            array_values(array_unique(array_map('strval', $categories))),
            $known
        ));

        $optional = array_values(array_diff($known, $locked));
        $chosen   = array_values(array_intersect($categories, $optional));

        if ($action === 'reject_all') {
            $chosen = [];
        } elseif ($action === 'accept_all' && count($chosen) !== count($optional)) {
            $action = 'custom';
        }

        // Known-category order, so the same decision always serialises the same way.
        $accepted = array_values(array_filter(
            $known,
            static fn(string $key): bool => in_array($key, $locked, true) || in_array($key, $chosen, true)
        ));

        return ['action' => $action, 'categories' => $accepted];
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
     * `categories` is a JSON array column, so this cannot be a GROUP BY; it
     * is one COUNT per category using {@see categoryCondition()} — the exact,
     * driver-consistent test the records filter uses — so nothing is loaded
     * into PHP whatever the table size.
     *
     * Takes the same `$filters` as {@see buildQuery()}, and for the same
     * reason the outcome counts do: a screen that reports outcome totals for a
     * filtered set beside a category breakdown of the whole table is showing
     * two answers to two different questions under one heading. Omitting the
     * argument still means "every record", so unfiltered callers are unchanged.
     *
     * @param  string[]             $categoryKeys Keys to report on, in display order.
     * @param  array<string, mixed> $filters      Optional record filters; see buildQuery().
     * @return array<string, array{count: int, percent: float}>
     */
    public function getCategoryStats(?int $siteId, array $categoryKeys, array $filters = []): array
    {
        // One COUNT per category, in SQL, using the same exact condition the
        // records filter uses. The previous version decoded every record's
        // JSON in PHP on each cache miss — and every consent save invalidates
        // the cache — so a large table made the dashboard slow and, through a
        // buffered result set, could exhaust memory.
        $driver = Craft::$app->getDb()->getDriverName();
        $total  = (int) $this->buildQuery($siteId, $filters)->count();
        $result = [];

        foreach ($categoryKeys as $key) {
            $count = $total === 0 ? 0 : (int) $this->buildQuery($siteId, $filters)
                ->andWhere(self::categoryCondition((string) $key, $driver))
                ->count();

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
     * @param array<string, mixed> $filters Optional record filters; see buildQuery().
     * @return array<int, array{date: string, count: int}>
     */
    public function getDailyTrend(?int $siteId, int $days = 30, array $filters = []): array
    {
        // UTC, because `dateCreated` is stored in UTC. Built from the server's
        // local clock, the window silently started or ended hours off on every
        // install whose configured timezone is not UTC.
        $since = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))
            ->format('Y-m-d 00:00:00');

        // The same filters as the rest of the screen: a trend of every record
        // beside totals for a filtered set would describe two different
        // datasets under one heading.
        $rows = $this->buildQuery($siteId, $filters)
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
     * (dates), `siteIds` (restrict to these sites). Unknown keys and empty
     * values are ignored.
     *
     * @param array<string, mixed> $filters
     */
    public function buildQuery(?int $siteId, array $filters = []): ActiveQuery
    {
        $query = $this->_baseQuery($siteId);

        // A restriction to the sites the viewer may see, applied when "all
        // sites" means "all of *their* sites" (see Permissions::accessibleSites()).
        if (isset($filters['siteIds']) && is_array($filters['siteIds'])) {
            $query->andWhere(['siteId' => $filters['siteIds'] === [] ? [0] : array_map('intval', $filters['siteIds'])]);
        }

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
            $query->andWhere(self::categoryCondition((string) $filters['category'], Craft::$app->getDb()->getDriverName()));
        }

        return $query;
    }

    /**
     * The "record includes this category" condition, identical in meaning on
     * both supported databases.
     *
     * `categories` is a JSON array of keys, so this finds the key *with its
     * quotes* (`"analytics"`), which cannot match a longer key that merely
     * contains it. What differed between drivers was case: the `LIKE` used
     * before is
     * case-insensitive under MySQL's default collations and case-sensitive on
     * PostgreSQL, so filtering for `analytics` also counted `Analytics` on
     * MySQL only, and the dashboard, the records list and the (exact,
     * PHP-side) export disagreed there. The search is now binary on MySQL,
     * making it case-sensitive — exact — everywhere.
     *
     * A value that cannot be a category key matches nothing rather than
     * being dropped: a filtered view that silently showed every record would
     * look like an answer.
     *
     * @return array<int|string, mixed>|Expression
     */
    public static function categoryCondition(string $category, string $driver): array|Expression
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $category)) {
            return new Expression('1 = 0');
        }

        // A plain substring search rather than LIKE: `_` is a LIKE wildcard
        // that category keys may contain, and escaping it portably depends on
        // SQL modes (NO_BACKSLASH_ESCAPES) the plugin does not control.
        $needle = '"' . $category . '"';

        if ($driver === 'mysql') {
            return new Expression('LOCATE(:ccfCategory, CAST([[categories]] AS BINARY)) > 0', [':ccfCategory' => $needle]);
        }

        return new Expression('STRPOS([[categories]], :ccfCategory) > 0', [':ccfCategory' => $needle]);
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

        $deleted = self::deleteInBatches(
            static fn(int $limit): array => ConsentLogRecord::find()
                ->select(['id'])
                ->where($condition)
                ->orderBy(['id' => SORT_ASC])
                ->limit($limit)
                ->column(),
            static fn(array $ids): int => (int) ConsentLogRecord::deleteAll(['id' => $ids]),
            PluginConfig::retentionBatchSize()
        );

        if ($deleted > 0) {
            Plugin::getInstance()->statistics->invalidate();
        }

        return $deleted;
    }

    /**
     * Deletes in bounded batches until nothing matches.
     *
     * A single `DELETE … WHERE dateCreated < ?` over a large table runs as one
     * long statement that holds its locks (and, on MySQL, a growing undo log)
     * until it finishes, blocking the consent saves arriving meanwhile. Each
     * batch here is its own short statement and commits on its own, so
     * concurrent writes wait for one batch at most, and an interrupted purge
     * keeps what it already removed and resumes where it left off.
     *
     * @param callable(int): array<int, int|string> $selectIds Returns up to `$limit` ids still to delete.
     * @param callable(array<int, int|string>): int  $delete   Deletes those ids, returning how many went.
     */
    public static function deleteInBatches(callable $selectIds, callable $delete, int $batchSize): int
    {
        $batchSize = max(1, $batchSize);
        $total     = 0;

        while (true) {
            $ids = $selectIds($batchSize);

            if ($ids === []) {
                break;
            }

            $removed = $delete($ids);
            $total  += $removed;

            // Fewer ids than a full batch means that was the last of them; a
            // batch that removed nothing (rows deleted concurrently) must not
            // loop on the same ids for ever.
            if (count($ids) < $batchSize || $removed === 0) {
                break;
            }
        }

        return $total;
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
     *
     * The result is **UTC**, because that is what `dateCreated` is compared
     * against. It used to be formatted in whatever timezone the install had
     * configured, which on any non-UTC site put the cutoff hours out — keeping
     * records that were due for deletion, or deleting records still inside
     * their retention period. A supplied `$now` is converted rather than
     * assumed to already be UTC, and the day arithmetic happens after that
     * conversion so a timezone's DST transitions cannot shift the boundary.
     */
    public static function retentionCutoff(int $days, ?\DateTimeImmutable $now = null): ?string
    {
        if ($days <= 0) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        return ($now ?? new \DateTimeImmutable('now', $utc))
            ->setTimezone($utc)
            ->modify("-{$days} days")
            ->format('Y-m-d H:i:s');
    }

    /**
     * Converts a filter date into the UTC datetime string `dateCreated` is
     * compared against.
     *
     * Accepts a `Y-m-d` date (what the CP date field posts) or a full
     * datetime. A bare date is read in `$timezone` — the admin typing
     * "16 September" means that day where they are, not that day in UTC — and
     * then converted. A value carrying its own offset keeps it. Anything
     * unparseable yields null, which buildQuery() treats as no filter rather
     * than as "match nothing": a malformed date in a URL must not silently
     * produce an empty, apparently-authoritative export.
     *
     * Static and explicitly parameterised so the conversion can be verified
     * against several timezones without a configured application.
     */
    public static function normalizeFilterDate(mixed $value, string $timeFallback, ?string $timezone = null): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            $value .= ' ' . $timeFallback;
        }

        try {
            $local = new \DateTimeZone($timezone ?? 'UTC');
            $date  = new \DateTimeImmutable($value, $local);
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
     * Resolves a posted filter date against the install's configured
     * timezone — which is the one the admin entering it is reading dates in —
     * and hands back UTC. See {@see normalizeFilterDate()}.
     */
    private function _normalizeDate(mixed $value, string $timeFallback): ?string
    {
        return self::normalizeFilterDate($value, $timeFallback, Craft::$app->getTimeZone());
    }
}
