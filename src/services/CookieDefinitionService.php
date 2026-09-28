<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use Craft;
use craft\helpers\Db;
use craft\base\Component;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\records\CookieDefinitionRecord;
use sfsinfotech\craftcookieconsentflow\records\DetectedCookieRecord;
use Throwable;

/**
 * Cookie Definition Service — documents individual cookies (name, provider,
 * purpose, duration) grouped by consent category. Pure disclosure content:
 * GDPR/ePrivacy (Art. 13) expect naming what cookies are used and why, not
 * just a category-level description. Plays no part in blocking — that's
 * still the data-cck-category tagging convention on scripts/iframes.
 *
 * Per-site exactly like categories: every method here takes/is keyed by a
 * `settingsId` (the global row's, or a specific site's override row's — see
 * SettingsService::getGlobalSettingsId()/findSiteSettingsId()/
 * getOrCreateSiteSettingsId()) rather than being global-only. Resolving
 * "does this site actually have its own list, or does it inherit global's"
 * is the caller's job (CookiesController for the CP, getEffectiveSettingsId()
 * below for the front end) — this service just reads/writes whichever
 * settingsId it's given.
 */
class CookieDefinitionService extends Component
{
    /**
     * Returns every cookie documented against a specific settings row
     * (global or a site's own), as a plain array, in display order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(int $settingsId): array
    {
        return array_values(array_map(
            static fn(CookieDefinitionRecord $row): array => [
                'id'          => $row->id,
                'categoryKey' => $row->categoryKey,
                'name'        => $row->name,
                'provider'    => (string) $row->provider,
                'purpose'     => (string) $row->purpose,
                'duration'    => (string) $row->duration,
            ],
            CookieDefinitionRecord::find()
                ->where(['settingsId' => $settingsId])
                ->orderBy(['sortOrder' => SORT_ASC])
                ->all()
        ));
    }

    /**
     * Returns a settings row's documented cookies grouped by category key,
     * for the preferences modal — visitors see WHAT they're consenting to,
     * not just a category name. A category with no documented cookies is
     * simply absent from the result.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getGroupedByCategory(int $settingsId): array
    {
        $grouped = [];
        foreach ($this->getAll($settingsId) as $cookie) {
            $grouped[$cookie['categoryKey']][] = $cookie;
        }

        return $grouped;
    }

    /**
     * Documented cookies whose category no longer exists.
     *
     * Categories are admin-configurable and identified by a free-form key, so
     * renaming or deleting one leaves its disclosures pointing at a key
     * nothing resolves. Nothing repoints them automatically — which category a
     * cookie now belongs in is a judgement only a human can make, and silently
     * refiling or deleting disclosure content would be worse than leaving it.
     * So they are surfaced instead: the visitor-facing modal skips them (a
     * cookie cannot be disclosed under a category that does not exist), and
     * the Cookies screen shows them for an admin to refile or remove.
     *
     * @param  string[] $categoryKeys The category keys that currently exist.
     * @return array<int, array<string, mixed>>
     */
    public function getOrphaned(int $settingsId, array $categoryKeys): array
    {
        return array_values(array_filter(
            $this->getAll($settingsId),
            static fn(array $cookie): bool => !in_array($cookie['categoryKey'], $categoryKeys, true)
        ));
    }

    /**
     * Whether a settings row has any cookie rows of its own — the same
     * "presence of child rows = override" convention already used for
     * categories. Only meaningful for a site's settingsId: the global row
     * always effectively "has" whatever's documented against it.
     */
    public function hasOverrideForSettingsId(int $settingsId): bool
    {
        return CookieDefinitionRecord::find()->where(['settingsId' => $settingsId])->exists();
    }

    /**
     * Resolves which settingsId's cookie list actually applies to a site:
     * that site's own if it has documented cookies of its own, otherwise
     * global's. Mirrors how Settings::resolveForSite() falls back to global
     * for any field a site hasn't overridden.
     */
    public function getEffectiveSettingsId(int $siteId): int
    {
        $cookieSettings = Plugin::getInstance()->cookieSettings;
        $siteSettingsId = $cookieSettings->findSiteSettingsId($siteId);

        if ($siteSettingsId !== null && $this->hasOverrideForSettingsId($siteSettingsId)) {
            return $siteSettingsId;
        }

        return $cookieSettings->getGlobalSettingsId();
    }

    /**
     * Replaces an entire settings row's cookie list (delete-all-then-
     * reinsert, mirroring SettingsService's category-save pattern) — the CP
     * form always submits the full list as one repeatable set of rows, never
     * a partial diff.
     *
     * @param array<int, array<string, mixed>> $raw
     */
    public function saveAll(int $settingsId, array $raw): bool
    {
        $this->_validationErrors = self::validateCookies($raw);

        if ($this->_validationErrors !== []) {
            return false;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->getTransaction();
        $ownsTransaction = $transaction === null || !$transaction->getIsActive();

        if ($ownsTransaction) {
            $transaction = $db->beginTransaction();
        }

        try {
            CookieDefinitionRecord::deleteAll(['settingsId' => $settingsId]);

            foreach (array_values($raw) as $sortOrder => $row) {
                if (empty($row['name'])) {
                    continue;
                }

                $record              = new CookieDefinitionRecord();
                $record->settingsId  = $settingsId;
                $record->categoryKey = (string) ($row['categoryKey'] ?? '');
                $record->name        = (string) $row['name'];
                $record->provider    = (string) ($row['provider'] ?? '');
                $record->purpose     = (string) ($row['purpose'] ?? '');
                $record->duration    = (string) ($row['duration'] ?? '');
                $record->sortOrder   = $sortOrder;

                if (!$record->save()) {
                    if ($ownsTransaction) {
                        $transaction->rollBack();
                    }
                    return false;
                }
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $transaction->getIsActive()) {
                $transaction->rollBack();
            }
            Craft::error('Cookie disclosure save failed: ' . $e->getMessage(), __METHOD__);

            return false;
        }

        if ($ownsTransaction) {
            $transaction->commit();
        }

        return true;
    }

    /** @var string[] Why the last saveAll() was refused; see getValidationErrors(). */
    private array $_validationErrors = [];

    /**
     * Why the last saveAll() was refused, one message per problem — empty
     * when it was not refused by validation.
     *
     * @return string[]
     */
    public function getValidationErrors(): array
    {
        return $this->_validationErrors;
    }

    /**
     * Problems with a posted cookie list, checked before anything is deleted
     * or written, against the same widths the columns have. A value that
     * used to reach the database and fail its INSERT is reported here with
     * the row and the field instead of "Couldn't save cookies."
     *
     * Rows without a name are skipped by saveAll(), so they are not errors.
     *
     * @param array<int, mixed> $rows
     * @return string[]
     */
    public static function validateCookies(array $rows): array
    {
        $limits = [
            'name'        => [255, 'Name'],
            'provider'    => [255, 'Provider'],
            'duration'    => [100, 'Duration'],
            'categoryKey' => [100, 'Category'],
            'purpose'     => [Settings::LONG_TEXT_MAX_LENGTH, 'Purpose'],
        ];

        $errors = [];

        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                $errors[] = Craft::t('cookie-consent-flow', 'Cookie {row}: unexpected value.', ['row' => $i + 1]);
                continue;
            }

            if (empty($row['name'])) {
                continue;
            }

            foreach ($limits as $field => [$max, $label]) {
                $value = $row[$field] ?? '';

                if (!is_scalar($value)) {
                    $errors[] = Craft::t('cookie-consent-flow', 'Cookie {row}: unexpected value for {field}.', [
                        'row'   => $i + 1,
                        'field' => Craft::t('cookie-consent-flow', $label),
                    ]);
                    continue;
                }

                if (mb_strlen((string) $value) > $max) {
                    $errors[] = Craft::t('cookie-consent-flow', 'Cookie {row}: {field} must be at most {max} characters.', [
                        'row'   => $i + 1,
                        'field' => Craft::t('cookie-consent-flow', $label),
                        'max'   => $max,
                    ]);
                }
            }
        }

        return $errors;
    }

    /** Deletes every cookie row for a settings row (reverts a site to inheriting global). */
    public function deleteAllForSettingsId(int $settingsId): void
    {
        CookieDefinitionRecord::deleteAll(['settingsId' => $settingsId]);
    }

    // Detection (see DetectedCookieRecord)

    /**
     * Records that these cookie names were actually seen in a visitor's
     * browser (reported by cookie-banner.js): a name seen for the first time
     * gets a row whose `dateCreated` is its first sighting, and every
     * sighting — first or not — sets `lastSeen`. This is a raw "what's
     * actually running" signal, not a per-visitor log, so no identifying
     * visitor data is stored here.
     *
     * One atomic upsert per name (`INSERT … ON DUPLICATE KEY UPDATE` on
     * MySQL, `ON CONFLICT … DO UPDATE` on PostgreSQL), rather than the
     * previous find-then-save:
     *
     * - "last seen" never moved. It relied on `save()` touching
     *   `dateUpdated`, which Craft only does for a record with changed
     *   attributes — and a re-sighted name has none.
     * - two browsers reporting the same new name at once both found nothing,
     *   both inserted, and the loser's unique-key violation aborted the rest
     *   of its batch.
     *
     * A dismissal survives re-sighting: the update touches only the dates.
     *
     * @param string[] $names
     */
    /**
     * Most distinct detected names kept per site. A real site uses tens or
     * low hundreds; the endpoint is anonymous, so without a ceiling anyone
     * could fill the table (and slow the Cookies page, which reads it) with
     * invented names. At the ceiling, names already known still have their
     * `lastSeen` updated; new ones are dropped and the drop is logged.
     */
    public const MAX_DETECTED_PER_SITE = 2000;

    /** Most undocumented names the Cookies page lists at once. */
    public const MAX_UNDOCUMENTED_LISTED = 500;

    public function recordDetected(array $names, int $siteId): void
    {
        $db   = Craft::$app->getDb();
        $now  = Db::prepareDateForDb(new \DateTime());
        $full = (int) DetectedCookieRecord::find()->where(['siteId' => $siteId])->count() >= self::MAX_DETECTED_PER_SITE;

        foreach (array_unique($names) as $name) {
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name) > 255) {
                continue;
            }

            if ($full) {
                $updated = DetectedCookieRecord::updateAll(['lastSeen' => $now], ['siteId' => $siteId, 'name' => $name]);

                if ($updated === 0) {
                    Craft::warning(
                        "Cookie Consent Flow: site #{$siteId} already has " . self::MAX_DETECTED_PER_SITE
                        . " detected cookie names; not recording '{$name}'. Dismiss or document names to make room.",
                        __METHOD__
                    );
                }

                continue;
            }

            try {
                $db->createCommand()->upsert(
                    DetectedCookieRecord::tableName(),
                    ['siteId' => $siteId, 'name' => $name, 'isDismissed' => false, 'lastSeen' => $now],
                    ['lastSeen' => $now]
                )->execute();
            } catch (\Throwable $e) {
                // One bad name must not cost the rest of the batch.
                Craft::warning("Cookie Consent Flow could not record detected cookie '{$name}': " . $e->getMessage(), __METHOD__);
            }
        }
    }

    /**
     * Returns detected cookie names (deduplicated across every site) that
     * are neither already documented — anywhere, global or any site's
     * override, exact match or matched against a documented "family"
     * pattern like `_ga_*` — nor dismissed by an admin. Deliberately not
     * scoped to one settingsId: detection/review is a site-agnostic
     * housekeeping concern across the whole install, not duplicated per
     * site view on the Cookies page.
     *
     * @return string[]
     */
    public function getUndocumented(): array
    {
        return array_column($this->getUndocumentedDetails(), 'name');
    }

    /**
     * As getUndocumented(), with when each name was first and last seen on
     * any site (UTC, `Y-m-d H:i:s`).
     *
     * @return array<int, array{name: string, firstSeen: ?string, lastSeen: ?string}>
     */
    public function getUndocumentedDetails(): array
    {
        $documentedPatterns = CookieDefinitionRecord::find()->select('name')->column();

        $query = DetectedCookieRecord::find()
            ->select([
                'name',
                'firstSeen' => 'MIN([[dateCreated]])',
                'lastSeen'  => 'MAX(COALESCE([[lastSeen]], [[dateUpdated]]))',
            ])
            ->where(['isDismissed' => false])
            ->groupBy(['name'])
            // A total order (names are unique per group), so consecutive
            // batches neither repeat nor skip a name.
            ->orderBy(['lastSeen' => SORT_DESC, 'name' => SORT_ASC])
            ->asArray();

        // Documented names are wildcard patterns, so they cannot be excluded
        // in SQL, and the limit used to be applied in SQL before they were
        // excluded here: once 500 detected names were documented, genuinely
        // undocumented ones fell off the list. Batches are read until the
        // list is full or the rows run out — one query in the usual case,
        // and bounded by MAX_DETECTED_PER_SITE per site at worst.
        $batches = (function () use ($query): \Generator {
            for ($offset = 0; ; $offset += self::MAX_UNDOCUMENTED_LISTED) {
                $batch = (clone $query)->limit(self::MAX_UNDOCUMENTED_LISTED)->offset($offset)->all();

                yield from $batch;

                if (count($batch) < self::MAX_UNDOCUMENTED_LISTED) {
                    return;
                }
            }
        })();

        return self::takeUndocumented($batches, $documentedPatterns, self::MAX_UNDOCUMENTED_LISTED);
    }

    /**
     * The first `$limit` rows whose name no documented pattern covers,
     * reading `$rows` only as far as needed.
     *
     * @param  iterable<array{name: mixed, firstSeen: mixed, lastSeen: mixed}> $rows
     * @param  string[]                                                        $documentedPatterns
     * @return array<int, array{name: string, firstSeen: ?string, lastSeen: ?string}>
     */
    public static function takeUndocumented(iterable $rows, array $documentedPatterns, int $limit): array
    {
        $details = [];

        if ($limit <= 0) {
            return $details;
        }

        foreach ($rows as $row) {
            if (self::matchesAnyPattern((string) $row['name'], $documentedPatterns)) {
                continue;
            }

            $details[] = [
                'name'      => (string) $row['name'],
                'firstSeen' => $row['firstSeen'] !== null ? (string) $row['firstSeen'] : null,
                'lastSeen'  => $row['lastSeen'] !== null ? (string) $row['lastSeen'] : null,
            ];

            // Stop before the next row is asked for, so a full list never
            // costs another batch query.
            if (count($details) >= $limit) {
                break;
            }
        }

        return $details;
    }

    /**
     * Marks every detected row for a cookie name (across all sites) as
     * dismissed, so it stops appearing in getUndocumented() without
     * requiring the admin to actually document it (e.g. a first-party
     * technical cookie that doesn't warrant visitor-facing disclosure).
     */
    public function dismissDetected(string $name): bool
    {
        return (bool) DetectedCookieRecord::updateAll(
            ['isDismissed' => true],
            ['name' => $name]
        );
    }

    /**
     * Whether a detected cookie name is covered by any documented cookie
     * name/pattern. Documented names are treated as wildcard patterns where
     * `*` stands for "anything" (e.g. `_ga_*` covers `_ga_G-XXXXXXX`) —
     * exact matches are just a pattern with no `*` in it. Matching is
     * case-sensitive, like cookie names themselves: `_ga` does not cover a
     * detected `_GA`, which is a different cookie.
     *
     * @param string[] $patterns
     */
    public static function matchesAnyPattern(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/D';
            if (preg_match($regex, $name)) {
                return true;
            }
        }

        return false;
    }
}
