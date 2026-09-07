<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\records\CookieCategoryRecord;
use sfsinfotech\craftcookieconsentflow\records\SettingsRecord;

/**
 * Settings Service — single resolution point for "effective" (global +
 * site-override merged) settings, and the shared save/normalization logic
 * used by both the Global Settings and Multi Site Override CP pages.
 *
 * Settings are persisted in the plugin's own `cookieconsent_settings` table:
 * one row per Craft site plus a global row (`siteId = 0, isGlobal = 1`), one
 * real column per setting. On a site's row, a `NULL` column means "inherit
 * from global" — that's what preserves the existing per-field override
 * semantics while still giving every setting its own queryable column.
 */
class SettingsService extends Component
{
    /** Boolean settings fields. */
    private const BOOL_FIELDS = [
        'bannerEnabled', 'fullWidth', 'shadow', 'fixedPosition', 'geoEnabled', 'logEnabled',
    ];

    /** JSON-encoded array settings fields (whole-value in/out). */
    private const JSON_FIELDS = ['geoTargetCountries'];

    /**
     * Integer settings fields. `logoAssetId` is nullable (no logo set) —
     * unlike the other two, which always hold a real number on the global
     * row — so _fromColumnValue() must preserve null for it rather than
     * casting to 0 (which would be misread as a literal asset ID of 0).
     */
    private const INT_FIELDS = ['logRetentionDays', 'consentExpiryDays', 'logoAssetId'];

    /** @var array<int, Settings> Per-request cache, keyed by site ID. */
    private array $_effectiveCache = [];

    /** Per-request cache of the loaded global Settings model. */
    private ?Settings $_settingsCache = null;

    /**
     * Returns the effective settings for a site — global values with that
     * site's overrides applied on top. Resolves the current site when
     * `$siteId` is omitted. Cached for the lifetime of the request so
     * repeated calls (banner render, preferences button, auto-inject hook)
     * don't re-merge or hit the database again.
     */
    public function getEffectiveSettings(?int $siteId = null): Settings
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        if (!array_key_exists($siteId, $this->_effectiveCache)) {
            $this->_effectiveCache[$siteId] = $this->loadSettings()->resolveForSite($siteId);
        }

        return $this->_effectiveCache[$siteId];
    }

    /**
     * Loads the plugin-wide Settings model: global column values plus a
     * sparse `siteOverrides` map rebuilt from every site row's non-NULL
     * columns — reconstructing the exact in-memory shape the rest of the
     * plugin (resolveForSite(), isFieldOverridden(), the CP templates, etc.)
     * already expects. Seeds the global row with model defaults on first
     * access if it doesn't exist yet. Request-cached — call clearCache()
     * after writes.
     */
    public function loadSettings(): Settings
    {
        if ($this->_settingsCache !== null) {
            return $this->_settingsCache;
        }

        $globalRecord = $this->_getOrCreateGlobalRecord();
        $cookieDefinitions = Plugin::getInstance()->cookieDefinitions;

        $settings = new Settings();
        $this->_applyRowToSettings($settings, $globalRecord);
        $settings->categories = $this->_loadCategoriesForSettingsRow((int) $globalRecord->id);
        $settings->cookies    = $cookieDefinitions->getAll((int) $globalRecord->id);

        foreach (SettingsRecord::find()->andWhere(['!=', 'siteId', 0])->all() as $row) {
            /** @var SettingsRecord $row */
            $overrides = $this->_sparseOverridesFromRow($row);

            if ($this->_hasCategoryOverride((int) $row->id)) {
                $overrides['categories'] = $this->_loadCategoriesForSettingsRow((int) $row->id);
                $overrides['_updatedAt'] ??= strtotime((string) $row->dateUpdated) ?: time();
            }

            if ($cookieDefinitions->hasOverrideForSettingsId((int) $row->id)) {
                $overrides['cookies'] = $cookieDefinitions->getAll((int) $row->id);
                $overrides['_updatedAt'] ??= strtotime((string) $row->dateUpdated) ?: time();
            }

            if (!empty($overrides)) {
                $settings->siteOverrides[(int) $row->siteId] = $overrides;
            }
        }

        return $this->_settingsCache = $settings;
    }

    /**
     * Normalizes and persists global settings values. Only fields present in
     * `$raw` are written, so fields absent from the submitted form (e.g.
     * values from a different tab) are naturally left untouched on the row.
     *
     * @param array<string, mixed> $raw Posted `settings` body params.
     */
    public function saveGlobalSettings(array $raw): bool
    {
        $raw    = $this->normalizeFields($raw);
        $record = $this->_getOrCreateGlobalRecord();
        $this->_setRowFieldsFromArray($record, $raw);

        $saved = $record->save();

        if ($saved && array_key_exists('categories', $raw)) {
            $this->_saveCategoriesForSettingsRow((int) $record->id, $raw['categories']);
        }

        if ($saved) {
            $this->clearCache();
        }

        return $saved;
    }

    /**
     * Normalizes and persists a single site's overrides. For every
     * overridable field: if its "use global" flag is truthy, the column is
     * cleared to NULL (falls back to global); otherwise the submitted value
     * is normalized and stored. Fields absent from both `$raw` and
     * `$useGlobalFlags` are left untouched. If every overridable column ends
     * up NULL, the site's row is deleted entirely (fully inherited).
     *
     * @param array<string, mixed> $raw            Posted per-site field values.
     * @param array<string, mixed> $useGlobalFlags  Posted "use global" checkbox values, keyed by field.
     */
    public function saveSiteOverrides(int $siteId, array $raw, array $useGlobalFlags): bool
    {
        $raw    = $this->normalizeFields($raw);
        $record = $this->_findSiteRecord($siteId) ?? $this->_newSiteRecord($siteId);

        foreach ($this->_columnOverridableFields() as $field) {
            if (!empty($useGlobalFlags[$field])) {
                $record->$field = null;
                continue;
            }

            if (array_key_exists($field, $raw)) {
                $record->$field = $this->_toColumnValue($field, $raw[$field]);
            }
        }

        $hasOtherOverride = false;
        foreach ($this->_columnOverridableFields() as $field) {
            if ($record->$field !== null) {
                $hasOtherOverride = true;
                break;
            }
        }

        $categoriesUseGlobal  = !empty($useGlobalFlags['categories']);
        $categoriesOverridden = !$categoriesUseGlobal && array_key_exists('categories', $raw);
        $hadCategoryOverride  = $record->id !== null && $this->_hasCategoryOverride((int) $record->id);
        $willHaveCategories   = $categoriesOverridden || (!$categoriesUseGlobal && $hadCategoryOverride);

        // Cookies: same "use global" toggle + repeatable-list pattern as
        // categories, just persisted via CookieDefinitionService instead of
        // an internal CookieCategoryRecord helper (a different table, but
        // the same settingsId-row lifecycle).
        $cookieDefinitions  = Plugin::getInstance()->cookieDefinitions;
        $cookiesUseGlobal   = !empty($useGlobalFlags['cookies']);
        $cookiesOverridden  = !$cookiesUseGlobal && array_key_exists('cookies', $raw);
        $hadCookieOverride  = $record->id !== null && $cookieDefinitions->hasOverrideForSettingsId((int) $record->id);
        $willHaveCookies    = $cookiesOverridden || (!$cookiesUseGlobal && $hadCookieOverride);

        $saved = $this->_saveOrDeleteSiteRecord($record, $willHaveCategories || $willHaveCookies);

        if ($saved && ($hasOtherOverride || $willHaveCategories || $willHaveCookies)) {
            if ($categoriesUseGlobal) {
                $this->_deleteCategoriesForSettingsRow((int) $record->id);
            } elseif ($categoriesOverridden) {
                $this->_saveCategoriesForSettingsRow((int) $record->id, $raw['categories']);
            }

            if ($cookiesUseGlobal) {
                $cookieDefinitions->deleteAllForSettingsId((int) $record->id);
            } elseif ($cookiesOverridden) {
                $cookieDefinitions->saveAll((int) $record->id, $raw['cookies']);
            }
        }

        if ($saved) {
            $this->clearCache();
        }

        return $saved;
    }

    /**
     * Clears every override for a site, returning it fully to inherited
     * (global) values. Reuses saveSiteOverrides() with every field's "use
     * global" flag forced on, so the normalize/save/cache-clear path isn't
     * duplicated.
     */
    public function resetSiteOverrides(int $siteId): bool
    {
        return $this->saveSiteOverrides($siteId, [], array_fill_keys(Settings::OVERRIDABLE_FIELDS, true));
    }

    /**
     * Replaces a site's entire override set with a copy of another site's.
     * Fields the target previously overrode but the source doesn't are
     * cleared (full replace, not a merge). Global settings are untouched.
     * If the source is fully inherited (no row), the target's row is
     * deleted too.
     */
    public function copySiteOverrides(int $fromSiteId, int $toSiteId): bool
    {
        if ($fromSiteId === $toSiteId) {
            return false;
        }

        $source = $this->_findSiteRecord($fromSiteId);
        $target = $this->_findSiteRecord($toSiteId) ?? $this->_newSiteRecord($toSiteId);

        foreach ($this->_columnOverridableFields() as $field) {
            $target->$field = $source?->$field;
        }

        $sourceHasCategoryOverride = $source !== null && $this->_hasCategoryOverride((int) $source->id);
        $sourceCategories          = $sourceHasCategoryOverride
            ? $this->_loadCategoriesForSettingsRow((int) $source->id)
            : [];

        $cookieDefinitions      = Plugin::getInstance()->cookieDefinitions;
        $sourceHasCookieOverride = $source !== null && $cookieDefinitions->hasOverrideForSettingsId((int) $source->id);
        $sourceCookies           = $sourceHasCookieOverride
            ? $cookieDefinitions->getAll((int) $source->id)
            : [];

        $saved = $this->_saveOrDeleteSiteRecord($target, $sourceHasCategoryOverride || $sourceHasCookieOverride);

        if ($saved) {
            if ($sourceHasCategoryOverride) {
                $this->_saveCategoriesForSettingsRow((int) $target->id, $sourceCategories);
            } elseif ($target->id !== null) {
                $this->_deleteCategoriesForSettingsRow((int) $target->id);
            }

            if ($sourceHasCookieOverride) {
                $cookieDefinitions->saveAll((int) $target->id, $sourceCookies);
            } elseif ($target->id !== null) {
                $cookieDefinitions->deleteAllForSettingsId((int) $target->id);
            }

            $this->clearCache();
        }

        return $saved;
    }

    /** Clears the per-request effective-settings and loaded-model cache. */
    public function clearCache(): void
    {
        $this->_effectiveCache = [];
        $this->_settingsCache  = null;
    }

    // Site-row lookups (shared with CookieDefinitionService — a site's
    // settingsId row is the one anchor categories AND cookies both attach
    // overrides to, alongside the banner-field columns this service owns)

    /** Returns the global settings row's id, creating the row from model defaults if missing. */
    public function getGlobalSettingsId(): int
    {
        return (int) $this->_getOrCreateGlobalRecord()->id;
    }

    /** Returns a site's own settings row id, or null if it has no row yet (fully inherited). */
    public function findSiteSettingsId(int $siteId): ?int
    {
        $record = $this->_findSiteRecord($siteId);

        return $record !== null ? (int) $record->id : null;
    }

    /** Finds or creates (and persists) a site's own settings row, returning its id. */
    public function getOrCreateSiteSettingsId(int $siteId): int
    {
        $record = $this->_findSiteRecord($siteId);

        if ($record === null) {
            $record = $this->_newSiteRecord($siteId);
            $record->save();
        }

        return (int) $record->id;
    }

    /**
     * Deletes a site's settings row if it now has no overrides left of any
     * kind — banner fields, categories, or cookies. Call after
     * CookieDefinitionService clears a site's cookie overrides, so a row
     * that only existed to hold them doesn't linger empty.
     */
    public function pruneSiteSettingsRowIfEmpty(int $siteId): void
    {
        $record = $this->_findSiteRecord($siteId);
        if ($record === null) {
            return;
        }

        $hasCategoryOverride = $this->_hasCategoryOverride((int) $record->id);
        $hasCookieOverride    = Plugin::getInstance()->cookieDefinitions->hasOverrideForSettingsId((int) $record->id);

        $this->_saveOrDeleteSiteRecord($record, $hasCategoryOverride || $hasCookieOverride);
        $this->clearCache();
    }

    // Private helpers

    /** All settings fields the table has a column for (everything but `siteOverrides`). */
    private function _allFields(): array
    {
        return array_merge(
            $this->_columnOverridableFields(),
            ['logEnabled', 'logRetentionDays', 'consentExpiryDays', 'policyVersion']
        );
    }

    /**
     * Overridable fields that still map to a real `cookieconsent_settings`
     * column — everything except `categories` and `cookies`, which are each
     * persisted in their own table (CookieCategoryRecord, CookieDefinitionRecord).
     */
    private function _columnOverridableFields(): array
    {
        return array_values(array_diff(Settings::OVERRIDABLE_FIELDS, ['categories', 'cookies']));
    }

    /** Finds the global row (siteId = 0), creating it from model defaults if missing. */
    private function _getOrCreateGlobalRecord(): SettingsRecord
    {
        $record = SettingsRecord::find()->where(['siteId' => 0])->one();

        if ($record === null) {
            $defaults          = new Settings();
            $record            = new SettingsRecord();
            $record->siteId    = 0;
            $record->isGlobal  = true;
            $this->_setRowFieldsFromArray($record, $defaults->toArray());
            $record->save();
            $this->_saveCategoriesForSettingsRow((int) $record->id, $defaults->categories);
        }

        return $record;
    }

    private function _findSiteRecord(int $siteId): ?SettingsRecord
    {
        return SettingsRecord::find()->where(['siteId' => $siteId])->one();
    }

    private function _newSiteRecord(int $siteId): SettingsRecord
    {
        $record           = new SettingsRecord();
        $record->siteId   = $siteId;
        $record->isGlobal = false;

        return $record;
    }

    /**
     * Saves a site row, unless every overridable column on it is NULL AND it
     * has no category override either (fully inherited) — in that case an
     * existing row is deleted (its category rows cascade with it) and a new,
     * never-persisted one is simply discarded. Either way counts as success.
     */
    private function _saveOrDeleteSiteRecord(SettingsRecord $record, bool $hasCategoryOverride = false): bool
    {
        foreach ($this->_columnOverridableFields() as $field) {
            if ($record->$field !== null) {
                return $record->save();
            }
        }

        if ($hasCategoryOverride) {
            return $record->save();
        }

        return $record->getIsNewRecord() || (bool) $record->delete();
    }

    /** Sets every column present in `$data` onto `$record`, JSON-encoding array fields. */
    private function _setRowFieldsFromArray(SettingsRecord $record, array $data): void
    {
        foreach ($this->_allFields() as $field) {
            if (array_key_exists($field, $data)) {
                $record->$field = $this->_toColumnValue($field, $data[$field]);
            }
        }
    }

    /** Populates a Settings model's top-level (global) properties from a row. */
    private function _applyRowToSettings(Settings $settings, SettingsRecord $row): void
    {
        foreach ($this->_allFields() as $field) {
            $settings->$field = $this->_fromColumnValue($field, $row->$field);
        }
    }

    /**
     * Builds the sparse override array for one site row: only fields whose
     * column is non-NULL, plus `_updatedAt` (sourced from the row's own
     * `dateUpdated`) when there's at least one real override — matching the
     * bookkeeping entry the rest of the plugin already expects
     * (Settings::getSiteOverrideUpdatedAt(), etc.).
     *
     * @return array<string, mixed>
     */
    private function _sparseOverridesFromRow(SettingsRecord $row): array
    {
        $overrides = [];

        foreach ($this->_columnOverridableFields() as $field) {
            if ($row->$field !== null) {
                $overrides[$field] = $this->_fromColumnValue($field, $row->$field);
            }
        }

        if (!empty($overrides)) {
            $overrides['_updatedAt'] = strtotime((string) $row->dateUpdated) ?: time();
        }

        return $overrides;
    }

    /**
     * Loads the category list for a settings row (global or a specific
     * site's override), ordered for display, mapped back to the
     * `key/label/description/default/locked` shape the rest of the plugin
     * already expects from `Settings::$categories`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function _loadCategoriesForSettingsRow(int $settingsId): array
    {
        return array_values(array_map(
            static fn(CookieCategoryRecord $row): array => [
                'key'         => $row->key,
                'label'       => $row->label,
                'description' => (string) $row->description,
                'default'     => (bool) $row->isDefault,
                'locked'      => (bool) $row->isLocked,
            ],
            CookieCategoryRecord::find()
                ->where(['settingsId' => $settingsId])
                ->orderBy(['sortOrder' => SORT_ASC])
                ->all()
        ));
    }

    /**
     * Replaces every category row belonging to a settings row with the
     * given set — deletes what's there, then bulk-inserts the new list in
     * order. Used for both the global row and a site's override row.
     *
     * @param array<int, array<string, mixed>> $categories
     */
    private function _saveCategoriesForSettingsRow(int $settingsId, array $categories): void
    {
        CookieCategoryRecord::deleteAll(['settingsId' => $settingsId]);

        foreach (array_values($categories) as $sortOrder => $category) {
            if (empty($category['key'])) {
                continue;
            }

            $row               = new CookieCategoryRecord();
            $row->settingsId   = $settingsId;
            $row->key          = (string) $category['key'];
            $row->label        = (string) ($category['label'] ?? '');
            $row->description  = (string) ($category['description'] ?? '');
            $row->isDefault    = !empty($category['default']);
            $row->isLocked     = !empty($category['locked']);
            $row->sortOrder    = $sortOrder;
            $row->save();
        }
    }

    /** Deletes every category row for a settings row (reverts it to inheriting global). */
    private function _deleteCategoriesForSettingsRow(int $settingsId): void
    {
        CookieCategoryRecord::deleteAll(['settingsId' => $settingsId]);
    }

    /** Whether a settings row has any category override rows of its own. */
    private function _hasCategoryOverride(int $settingsId): bool
    {
        return CookieCategoryRecord::find()->where(['settingsId' => $settingsId])->exists();
    }

    private function _toColumnValue(string $field, mixed $value): mixed
    {
        return in_array($field, self::JSON_FIELDS, true) ? Json::encode($value) : $value;
    }

    private function _fromColumnValue(string $field, mixed $value): mixed
    {
        if (in_array($field, self::JSON_FIELDS, true)) {
            return $value !== null ? (Json::decode($value) ?: []) : [];
        }

        if (in_array($field, self::BOOL_FIELDS, true)) {
            return (bool) $value;
        }

        if (in_array($field, self::INT_FIELDS, true)) {
            return $value !== null ? (int) $value : null;
        }

        return (string) $value;
    }

    /**
     * Shared normalization for posted settings fields: boolean lightswitches,
     * the repeatable categories array, and the comma-separated geo country
     * list. Used by both the global-save and site-override-save paths so the
     * logic only lives in one place.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeFields(array $raw): array
    {
        foreach (self::BOOL_FIELDS as $field) {
            if (array_key_exists($field, $raw)) {
                $raw[$field] = !empty($raw[$field]);
            }
        }

        if (isset($raw['categories']) && is_array($raw['categories'])) {
            $normalised = [];
            foreach ($raw['categories'] as $cat) {
                if (!empty($cat['key'])) {
                    $normalised[] = [
                        'key'         => preg_replace('/[^a-z0-9_\-]/i', '', (string) ($cat['key'] ?? '')),
                        'label'       => (string) ($cat['label'] ?? ''),
                        'description' => (string) ($cat['description'] ?? ''),
                        'default'     => !empty($cat['default']),
                        'locked'      => !empty($cat['locked']),
                    ];
                }
            }
            $raw['categories'] = $normalised;
        }

        if (array_key_exists('logoAssetId', $raw)) {
            // Craft's element-select input posts a bare '' when nothing is
            // selected, or an array of one id (single:true, limit:1) when
            // something is — never a plain scalar id.
            $ids = (array) $raw['logoAssetId'];
            $raw['logoAssetId'] = !empty($ids[0]) ? (int) $ids[0] : null;
        }

        if (isset($raw['geoTargetCountries']) && is_string($raw['geoTargetCountries'])) {
            $raw['geoTargetCountries'] = array_values(array_filter(
                array_map('trim', explode(',', strtoupper($raw['geoTargetCountries'])))
            ));
        }

        return $raw;
    }
}
