<?php

namespace sfsinfotech\craftcookieconsentflow\migrations;

use craft\db\Migration;
use craft\helpers\Json;

/**
 * Install migration — creates all database tables required by Cookie Consent Flow.
 */
class Install extends Migration
{
    public string $driver;

    /** Boolean settings columns (nullable on site rows: NULL = inherit from global). */
    private const BOOL_FIELDS = [
        'bannerEnabled', 'fullWidth', 'shadow', 'fixedPosition', 'geoEnabled', 'logEnabled',
    ];

    /** Short string settings columns (labels, enums, layout dimensions). */
    private const STRING_FIELDS = [
        'bannerLayout', 'cornerPosition',
        'bannerHeading',
        'privacyPolicyUrl', 'privacyPolicyLinkText',
        'acceptButtonText', 'rejectButtonText', 'customizeButtonText',
        'savePreferencesText', 'closeButtonText',
        'borderRadius', 'padding', 'maxWidth', 'maxHeight',
        'policyVersion',
    ];

    /** Colour settings columns. */
    private const COLOR_FIELDS = [
        'bannerBgColor', 'bannerBorderColor', 'overlayColor',
        'headingColor', 'descriptionColor', 'linkColor',
        'acceptBgColor', 'acceptTextColor', 'acceptBorderColor',
        'acceptHoverBgColor', 'acceptHoverTextColor',
        'rejectBgColor', 'rejectTextColor', 'rejectBorderColor',
        'rejectHoverBgColor', 'rejectHoverTextColor',
        'customizeBgColor', 'customizeTextColor', 'customizeBorderColor',
        'customizeHoverBgColor', 'customizeHoverTextColor',
        'saveBgColor', 'saveTextColor', 'closeIconColor',
    ];

    /** Long-text settings columns. */
    private const TEXT_FIELDS = ['bannerDescription'];

    /**
     * JSON-encoded array settings columns (whole-array in/out, never queried
     * per-element). `categories` is deliberately not here — it's relational
     * (see `cookieconsent_category` / `_createOrUpgradeCategoryTable()`),
     * not a JSON blob column.
     */
    private const JSON_FIELDS = ['geoTargetCountries'];

    /**
     * Integer settings columns. `logoAssetId` (no FK to the elements/assets
     * tables — a plain nullable id, like every other column here; if the
     * asset is later deleted, Settings::getLogoAsset()/getLogoUrl() just
     * return null for the stale id rather than needing cascade behaviour).
     */
    private const INT_FIELDS = ['logRetentionDays', 'consentExpiryDays', 'logoAssetId'];

    // Install

    public function safeUp(): bool
    {
        $this->driver = \Craft::$app->getConfig()->getDb()->driver;

        $this->_createConsentLogTable();
        $this->_createCategoryTableIfMissing();
        $this->_createOrUpgradeSettingsTable();
        $this->_addCategoryForeignKeyIfMissing();
        $this->_migrateLeftoverJsonCategoriesColumn();
        $this->_createCookieDefinitionTableIfMissing();
        $this->_createDetectedCookieTableIfMissing();

        return true;
    }

    // Uninstall

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%cookieconsent_detected_cookie}}');
        $this->dropTableIfExists('{{%cookieconsent_cookie}}');
        $this->dropTableIfExists('{{%cookieconsent_category}}');
        $this->dropTableIfExists('{{%cookieconsent_log}}');
        $this->dropTableIfExists('{{%cookieconsent_settings}}');

        return true;
    }

    // Private helpers

    private function _createConsentLogTable(): void
    {
        if ($this->db->tableExists('{{%cookieconsent_log}}')) {
            return;
        }

        $this->createTable('{{%cookieconsent_log}}', [
            'id'          => $this->primaryKey(),
            'visitorUuid' => $this->string(36)->notNull(),
            'ipHash'      => $this->string(64)->notNull(),
            'siteId'      => $this->integer()->notNull(),
            'categories'  => $this->text()->notNull(),
            'action'      => $this->string(20)->notNull(),
            'policyVersion' => $this->string(50)->notNull()->defaultValue(''),
            'countryCode' => $this->string(2)->null(),
            'userAgent'   => $this->string(500)->notNull()->defaultValue(''),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        $this->createIndex(null, '{{%cookieconsent_log}}', 'visitorUuid');
        $this->createIndex(null, '{{%cookieconsent_log}}', ['siteId', 'action']);
    }

    /**
     * Cookie category storage — one row per category, foreign-keyed to the
     * `cookieconsent_settings` row it belongs to (global or a specific
     * site's override row). A settings row with no category rows inherits
     * categories from global, the same meaning a NULL `categories` column
     * used to have before this table existed. Created before the settings
     * table is created (without its FK yet — `cookieconsent_settings` may
     * not exist yet at this point on a fresh install) before the settings
     * table is created/upgraded, so any data-migration path below can write
     * into it immediately. The FK itself is added afterward, once
     * `cookieconsent_settings` is guaranteed to exist — see
     * `_addCategoryForeignKeyIfMissing()`.
     */
    private function _createCategoryTableIfMissing(): void
    {
        if ($this->db->tableExists('{{%cookieconsent_category}}')) {
            return;
        }

        $this->createTable('{{%cookieconsent_category}}', [
            'id'          => $this->primaryKey(),
            'settingsId'  => $this->integer()->notNull(),
            'key'         => $this->string(100)->notNull(),
            'label'       => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'isDefault'   => $this->boolean()->notNull()->defaultValue(false),
            'isLocked'    => $this->boolean()->notNull()->defaultValue(false),
            'sortOrder'   => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        $this->createIndex(null, '{{%cookieconsent_category}}', ['settingsId', 'key'], true);
    }

    /**
     * Adds the `cookieconsent_category.settingsId → cookieconsent_settings.id`
     * foreign key, once `cookieconsent_settings` is guaranteed to exist
     * (must run after `_createOrUpgradeSettingsTable()`). Guarded so it's a
     * no-op if the FK was already added by a previous run.
     */
    private function _addCategoryForeignKeyIfMissing(): void
    {
        foreach ($this->db->getSchema()->getTableForeignKeys('{{%cookieconsent_category}}') as $fk) {
            if ($fk->columnNames === ['settingsId']) {
                return;
            }
        }

        $this->addForeignKey(
            null,
            '{{%cookieconsent_category}}',
            ['settingsId'],
            '{{%cookieconsent_settings}}',
            ['id'],
            'CASCADE',
            null
        );
    }

    /**
     * Plugin-owned settings storage — one row per Craft site plus a global
     * row (`siteId = 0, isGlobal = 1`), one real column per setting instead
     * of a JSON blob. On a site's row, a `NULL` column means "inherit from
     * global" — this is what preserves the existing per-field override
     * semantics (the Multi Site Override page's per-field "use global"
     * toggle) while still giving every setting its own queryable column.
     * The global row always has every column populated.
     *
     * Handles three states idempotently: fresh install (create empty table,
     * lazily seeded by SettingsService), an install still on the older
     * single-JSON-blob shape (upgrade in place, preserving data — including
     * writing its `categories` directly into `cookieconsent_category`,
     * since that's no longer a column this table has), or an install
     * already on this shape (no-op).
     */
    private function _createOrUpgradeSettingsTable(): void
    {
        if (!$this->db->tableExists('{{%cookieconsent_settings}}')) {
            $this->_createSettingsTableFresh();
            return;
        }

        if ($this->db->columnExists('{{%cookieconsent_settings}}', 'settingsData')) {
            $this->_upgradeSettingsTableFromJsonBlob();
        }
    }

    private function _createSettingsTableFresh(): void
    {
        $this->createTable('{{%cookieconsent_settings}}', array_merge(
            ['id' => $this->primaryKey()],
            $this->_settingsColumnDefinitions(),
            [
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid'         => $this->uid(),
            ]
        ));

        $this->createIndex(null, '{{%cookieconsent_settings}}', 'siteId', true);
    }

    /**
     * @return array<string, \yii\db\ColumnSchemaBuilder>
     */
    private function _settingsColumnDefinitions(): array
    {
        $columns = [
            'siteId'   => $this->integer()->notNull(),
            'isGlobal' => $this->boolean()->notNull()->defaultValue(false),
        ];

        foreach (self::BOOL_FIELDS as $field) {
            $columns[$field] = $this->boolean()->null();
        }
        foreach (self::STRING_FIELDS as $field) {
            $columns[$field] = $this->string(255)->null();
        }
        foreach (self::COLOR_FIELDS as $field) {
            $columns[$field] = $this->string(30)->null();
        }
        foreach (self::TEXT_FIELDS as $field) {
            $columns[$field] = $this->text()->null();
        }
        foreach (self::JSON_FIELDS as $field) {
            $columns[$field] = $this->mediumText()->null();
        }
        foreach (self::INT_FIELDS as $field) {
            $columns[$field] = $this->integer()->null();
        }

        return $columns;
    }

    /**
     * One-time upgrade from the single-JSON-blob shape: adds every new
     * column, migrates the existing row's data into them (the one row
     * becomes the global row; each entry in its `siteOverrides` map becomes
     * its own site row) — with `categories` migrated directly into
     * `cookieconsent_category` rows instead of a column, since it isn't one
     * — then drops `settingsData`.
     */
    private function _upgradeSettingsTableFromJsonBlob(): void
    {
        foreach ($this->_settingsColumnDefinitions() as $field => $definition) {
            if (!$this->db->columnExists('{{%cookieconsent_settings}}', $field)) {
                $this->addColumn('{{%cookieconsent_settings}}', $field, $definition);
            }
        }

        $existing = (new \craft\db\Query())
            ->from('{{%cookieconsent_settings}}')
            ->one($this->db);

        if ($existing !== null) {
            $data          = Json::decode($existing['settingsData']) ?: [];
            $siteOverrides = $data['siteOverrides'] ?? [];
            $categories    = $data['categories'] ?? null;
            unset($data['siteOverrides'], $data['categories']);

            $this->update(
                '{{%cookieconsent_settings}}',
                array_merge(['siteId' => 0, 'isGlobal' => true], $this->_columnValuesFromData($data)),
                ['id' => $existing['id']]
            );

            if (is_array($categories)) {
                $this->_insertCategoryRows((int) $existing['id'], $categories);
            }

            foreach ($siteOverrides as $siteId => $overrides) {
                unset($overrides['_updatedAt']);
                $siteCategories = $overrides['categories'] ?? null;
                unset($overrides['categories']);

                if (empty($overrides) && !is_array($siteCategories)) {
                    continue;
                }

                $this->insert('{{%cookieconsent_settings}}', array_merge(
                    ['siteId' => (int) $siteId, 'isGlobal' => false],
                    $this->_columnValuesFromData($overrides)
                ));

                if (is_array($siteCategories)) {
                    $siteRecordId = (int) $this->db->getLastInsertID();
                    $this->_insertCategoryRows($siteRecordId, $siteCategories);
                }
            }
        }

        if ($this->db->columnExists('{{%cookieconsent_settings}}', 'settingsData')) {
            $this->dropColumn('{{%cookieconsent_settings}}', 'settingsData');
        }

        if (!$this->_hasUniqueIndexOnSiteId()) {
            $this->createIndex(null, '{{%cookieconsent_settings}}', 'siteId', true);
        }
    }

    /**
     * Migrates a leftover `categories` JSON column on `cookieconsent_settings`
     * (from a dev install that ran a previous version of this migration,
     * before `cookieconsent_category` existed) into real rows, then drops
     * the column. No-op if the column doesn't exist.
     */
    private function _migrateLeftoverJsonCategoriesColumn(): void
    {
        if (!$this->db->columnExists('{{%cookieconsent_settings}}', 'categories')) {
            return;
        }

        $rows = (new \craft\db\Query())
            ->select(['id', 'categories'])
            ->from('{{%cookieconsent_settings}}')
            ->all($this->db);

        foreach ($rows as $row) {
            if ($row['categories'] === null || $row['categories'] === '') {
                continue;
            }

            $categories = Json::decode($row['categories']) ?: [];
            $this->_insertCategoryRows((int) $row['id'], $categories);
        }

        $this->dropColumn('{{%cookieconsent_settings}}', 'categories');
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     */
    private function _insertCategoryRows(int $settingsId, array $categories): void
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach (array_values($categories) as $sortOrder => $category) {
            if (empty($category['key'])) {
                continue;
            }

            $this->insert('{{%cookieconsent_category}}', [
                'settingsId'  => $settingsId,
                'key'         => (string) $category['key'],
                'label'       => (string) ($category['label'] ?? ''),
                'description' => (string) ($category['description'] ?? ''),
                'isDefault'   => !empty($category['default']),
                'isLocked'    => !empty($category['locked']),
                'sortOrder'   => $sortOrder,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid'         => \craft\helpers\StringHelper::UUID(),
            ]);
        }
    }

    /**
     * Per-cookie disclosure content — name/provider/purpose/duration, grouped
     * by category key — shown in the preferences modal alongside each
     * category's description. Per-site exactly like `cookieconsent_category`:
     * `settingsId` FKs to `cookieconsent_settings.id` (global row, or a site's
     * own override row); a site with no cookie rows inherits the global list
     * entirely. Runs after `_createOrUpgradeSettingsTable()`, so the FK can be
     * added at creation time (unlike the category table, this one never
     * needs to exist before `cookieconsent_settings` for a legacy-blob
     * migration, so there's no need to split creation from FK-adding).
     */
    private function _createCookieDefinitionTableIfMissing(): void
    {
        if ($this->db->tableExists('{{%cookieconsent_cookie}}')) {
            return;
        }

        $this->createTable('{{%cookieconsent_cookie}}', [
            'id'          => $this->primaryKey(),
            'settingsId'  => $this->integer()->notNull(),
            'categoryKey' => $this->string(100)->notNull(),
            'name'        => $this->string(255)->notNull(),
            'provider'    => $this->string(255)->null(),
            'purpose'     => $this->text()->null(),
            'duration'    => $this->string(100)->null(),
            'sortOrder'   => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        $this->createIndex(null, '{{%cookieconsent_cookie}}', ['settingsId', 'categoryKey']);

        $this->addForeignKey(
            null,
            '{{%cookieconsent_cookie}}',
            ['settingsId'],
            '{{%cookieconsent_settings}}',
            ['id'],
            'CASCADE',
            null
        );
    }

    /**
     * Raw "what's actually running" signal — one row per distinct cookie
     * name a visitor's browser actually reported (see
     * CookieDetectionController), so the Cookies page can flag anything
     * nobody has documented yet without a developer having to already know
     * it exists. Names only, never values, no visitor identifier.
     */
    private function _createDetectedCookieTableIfMissing(): void
    {
        if ($this->db->tableExists('{{%cookieconsent_detected_cookie}}')) {
            return;
        }

        $this->createTable('{{%cookieconsent_detected_cookie}}', [
            'id'          => $this->primaryKey(),
            'siteId'      => $this->integer()->notNull(),
            'name'        => $this->string(255)->notNull(),
            'isDismissed' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        $this->createIndex(null, '{{%cookieconsent_detected_cookie}}', ['siteId', 'name'], true);
    }

    /**
     * Maps a decoded settings array onto column values, JSON-encoding the
     * array-typed fields. Only keys present in `$data` are included, so
     * partial (site-override) data leaves the rest of the row's columns
     * untouched/NULL.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function _columnValuesFromData(array $data): array
    {
        $values = [];

        foreach ($data as $field => $value) {
            if (!in_array($field, self::_allSettingsFields(), true)) {
                continue;
            }

            $values[$field] = in_array($field, self::JSON_FIELDS, true) ? Json::encode($value) : $value;
        }

        return $values;
    }

    /**
     * @return string[]
     */
    private static function _allSettingsFields(): array
    {
        return array_merge(
            self::BOOL_FIELDS,
            self::STRING_FIELDS,
            self::COLOR_FIELDS,
            self::TEXT_FIELDS,
            self::JSON_FIELDS,
            self::INT_FIELDS
        );
    }

    private function _hasUniqueIndexOnSiteId(): bool
    {
        foreach ($this->db->getSchema()->getTableIndexes('{{%cookieconsent_settings}}') as $index) {
            if ($index->isUnique && $index->columnNames === ['siteId']) {
                return true;
            }
        }

        return false;
    }
}
