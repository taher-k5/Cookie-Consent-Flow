<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use Craft;
use craft\base\Component;
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
        $transaction = Craft::$app->getDb()->beginTransaction();

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
                    $transaction->rollBack();
                    return false;
                }
            }
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $transaction->commit();

        return true;
    }

    /** Deletes every cookie row for a settings row (reverts a site to inheriting global). */
    public function deleteAllForSettingsId(int $settingsId): void
    {
        CookieDefinitionRecord::deleteAll(['settingsId' => $settingsId]);
    }

    // Detection (see DetectedCookieRecord)

    /**
     * Records that these cookie names were actually seen in a visitor's
     * browser (reported by cookie-banner.js). Upserts per (siteId, name):
     * a name seen for the first time gets a new row; a name seen again just
     * bumps `dateUpdated` (last-seen) — this is a raw "what's actually
     * running" signal, not a per-visitor log, so no identifying visitor data
     * is stored here.
     *
     * @param string[] $names
     */
    public function recordDetected(array $names, int $siteId): void
    {
        foreach (array_unique($names) as $name) {
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name) > 255) {
                continue;
            }

            $record = DetectedCookieRecord::find()
                ->where(['siteId' => $siteId, 'name' => $name])
                ->one();

            if ($record === null) {
                $record             = new DetectedCookieRecord();
                $record->siteId     = $siteId;
                $record->name       = $name;
                $record->isDismissed = false;
            }

            // Touches dateUpdated even when nothing else changed, so
            // "last seen" stays accurate — that's the point of this call.
            $record->save();
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
        $documentedPatterns = CookieDefinitionRecord::find()->select('name')->column();

        $detectedNames = DetectedCookieRecord::find()
            ->select('name')
            ->where(['isDismissed' => false])
            ->distinct()
            ->column();

        return array_values(array_filter(
            $detectedNames,
            fn(string $name): bool => !$this->_matchesAnyPattern($name, $documentedPatterns)
        ));
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
     * exact matches are just a pattern with no `*` in it.
     *
     * @param string[] $patterns
     */
    private function _matchesAnyPattern(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
            if (preg_match($regex, $name)) {
                return true;
            }
        }

        return false;
    }
}
