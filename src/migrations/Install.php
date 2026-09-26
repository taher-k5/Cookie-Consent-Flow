<?php

namespace sfsinfotech\craftcookieconsentflow\migrations;

use craft\db\Migration;
use craft\helpers\Json;
use sfsinfotech\craftcookieconsentflow\models\Settings;

/**
 * Install migration — creates every table and column Cookie Consent Flow needs.
 *
 * **This is the single source of truth for the schema, and the single
 * canonical migration while the plugin is in its build phase.** Nothing has
 * been published, so schema changes are made here and nowhere else; there
 * are no incremental migrations. Every helper reconciles an existing table
 * rather than assuming an empty one, so running {@see reconcile()} against a
 * database partway to the current schema converges on it instead of failing.
 *
 * Once a release is published this convention ends: from the next schema
 * change, add a timestamped migration that calls reconcile() and bump
 * `Plugin::$schemaVersion`, because installations will then hold consent
 * evidence that an uninstall/reinstall would destroy. See CLAUDE.md.
 *
 * The two data-migration paths below (`settingsData` JSON blob → relational
 * columns, and a leftover `categories` column) serve installs of the
 * development builds published on the `main` branch (schema 1.6.x/1.7.x)
 * before 1.0.0; they are guarded by column-existence checks.
 */
class Install extends Migration
{
    public string $driver;

    /** Boolean settings columns (nullable on site rows: NULL = inherit from global). */
    private const BOOL_FIELDS = [
        'bannerEnabled', 'fullWidth', 'shadow', 'geoEnabled', 'logEnabled',
        'consentModeEnabled', 'consentModeAutoInject',
        'consentModeUrlPassthrough', 'consentModeAdsDataRedaction',
        'respectGpc', 'respectDnt',
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
        'consentModeType',
    ];

    /**
     * Colour settings columns.
     *
     * Width comes from {@see Settings::COLOR_MAX_LENGTH} rather than a literal,
     * because the model's validation is bounded by the same constant. These
     * were 30 characters while validation was unbounded, so a legitimate
     * longer `hsla(...)`/`rgba(...)` value passed the control panel and then
     * failed at INSERT on strict MySQL and on PostgreSQL.
     */
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
    private const INT_FIELDS = [
        'logRetentionDays', 'consentExpiryDays', 'logoAssetId', 'consentModeWaitForUpdate',
    ];

    // Install

    public function safeUp(): bool
    {
        $this->reconcile();

        return true;
    }

    /**
     * Brings the database to the current schema from any earlier state —
     * nothing at all (a fresh install), or any schema a released or
     * development build of this plugin left behind. Idempotent: on a current
     * database it changes nothing.
     */
    public function reconcile(): void
    {
        $this->driver = $this->db->getDriverName();

        $this->_createConsentLogTable();
        $this->_createCategoryTableIfMissing();
        $this->_createOrUpgradeSettingsTable();
        $this->_addCategoryForeignKeyIfMissing();
        $this->_migrateLeftoverJsonCategoriesColumn();
        $this->_createCookieDefinitionTableIfMissing();
        $this->_createDetectedCookieTableIfMissing();
        $this->_dropRetiredSettingsColumns();
        $this->_pruneOrphanedSiteRows();
    }

    // Uninstall

    /**
     * Uninstalling removes every table the plugin created — **including the
     * consent records**, which are the site's evidence of what its visitors
     * agreed to. That is Craft's convention for a plugin's own tables, and
     * the only way to leave no trace behind; it is also irreversible. The
     * README says so under "Uninstalling" and recommends exporting the
     * records first; the number of records dropped is written to the Craft
     * log so the loss is at least recorded.
     */
    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%cookieconsent_log}}')) {
            $count = (int) (new \craft\db\Query())->from('{{%cookieconsent_log}}')->count('*', $this->db);

            if ($count > 0) {
                \Craft::warning(
                    "Uninstalling Cookie Consent Flow deletes {$count} consent record(s). Export them first if they may be needed as evidence.",
                    __METHOD__
                );
            }
        }

        $this->dropTableIfExists('{{%cookieconsent_detected_cookie}}');
        $this->dropTableIfExists('{{%cookieconsent_cookie}}');
        $this->dropTableIfExists('{{%cookieconsent_category}}');
        $this->dropTableIfExists('{{%cookieconsent_log}}');
        $this->dropTableIfExists('{{%cookieconsent_settings}}');

        return true;
    }

    // Private helpers

    /**
     * Consent record storage. `source` records how a decision was reached
     * (banner, a browser privacy signal, or the JavaScript API); `policyVersion`
     * records what the visitor was consenting *to* at the time.
     *
     * Indexes: the records list and every export order by `dateCreated` and
     * usually filter by site first, so that pair carries the list query; the
     * standalone `dateCreated` index is what makes retention purging
     * (`WHERE dateCreated < cutoff`, across all sites) a range scan rather than
     * a full table scan once the table is large.
     */
    private function _createConsentLogTable(): void
    {
        if ($this->db->tableExists('{{%cookieconsent_log}}')) {
            $this->_upgradeConsentLogTable();
            return;
        }

        $this->createTable('{{%cookieconsent_log}}', [
            'id'            => $this->primaryKey(),
            'visitorUuid'   => $this->string(36)->notNull(),
            'ipHash'        => $this->string(64)->notNull(),
            'siteId'        => $this->integer()->notNull(),
            'categories'    => $this->text()->notNull(),
            'action'        => $this->string(20)->notNull(),
            'policyVersion' => $this->string(50)->notNull()->defaultValue(''),
            'source'        => $this->string(20)->notNull()->defaultValue('banner'),
            'countryCode'   => $this->string(2)->null(),
            'userAgent'     => $this->string(500)->notNull()->defaultValue(''),
            'dateCreated'   => $this->dateTime()->notNull(),
            'dateUpdated'   => $this->dateTime()->notNull(),
            'uid'           => $this->uid(),
        ]);

        $this->createIndex(null, '{{%cookieconsent_log}}', 'visitorUuid');
        $this->createIndex(null, '{{%cookieconsent_log}}', ['siteId', 'action']);
        $this->createIndex(null, '{{%cookieconsent_log}}', ['siteId', 'dateCreated']);
        $this->createIndex(null, '{{%cookieconsent_log}}', 'dateCreated');
    }

    /**
     * Reconciles an existing consent table with the current record shape.
     *
     * reconcile() converges existing databases as well as creating fresh
     * ones, so this must do more than return when a table already exists. In particular, 1.6.x
     * development installs have no `source` column; without this every
     * consent save after upgrading fails at INSERT time.
     */
    private function _upgradeConsentLogTable(): void
    {
        $table = '{{%cookieconsent_log}}';

        if (!$this->db->columnExists($table, 'policyVersion')) {
            $this->addColumn($table, 'policyVersion', $this->string(50)->notNull()->defaultValue(''));
        }

        if (!$this->db->columnExists($table, 'source')) {
            $this->addColumn($table, 'source', $this->string(20)->notNull()->defaultValue('banner'));
        }

        if (!$this->_hasIndex($table, ['siteId', 'dateCreated'])) {
            $this->createIndex(null, $table, ['siteId', 'dateCreated']);
        }

        if (!$this->_hasIndex($table, ['dateCreated'])) {
            $this->createIndex(null, $table, 'dateCreated');
        }
    }

    /**
     * Cookie category storage — one row per category, foreign-keyed to the
     * `cookieconsent_settings` row it belongs to (global or a specific
     * site's override row). A settings row with no category rows inherits
     * categories from global, the same meaning a NULL `categories` column
     * used to have before this table existed.
     *
     * `gcmSignals` is the category's Google Consent Mode v2 mapping, stored as
     * a JSON array (whole-value in/out, never queried per-element). An empty
     * or NULL value means the category grants no signal, which is the safe
     * reading of "nobody has said what this category corresponds to".
     *
     * Created before the settings table (without its FK yet —
     * `cookieconsent_settings` may not exist at this point on a fresh install)
     * so any data-migration path below can write into it immediately. The FK
     * is added afterwards by `_addCategoryForeignKeyIfMissing()`.
     */
    private function _createCategoryTableIfMissing(): void
    {
        if ($this->db->tableExists('{{%cookieconsent_category}}')) {
            if (!$this->db->columnExists('{{%cookieconsent_category}}', 'gcmSignals')) {
                $this->addColumn('{{%cookieconsent_category}}', 'gcmSignals', $this->text()->null());
            }
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
            'gcmSignals'  => $this->text()->null(),
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

        // A relational settings table can still be from an earlier schema
        // version. Add new nullable setting columns in place so existing
        // values and per-site inheritance remain untouched.
        foreach ($this->_settingsColumnDefinitions() as $field => $definition) {
            if (!$this->db->columnExists('{{%cookieconsent_settings}}', $field)) {
                $this->addColumn('{{%cookieconsent_settings}}', $field, $definition);
            }
        }

        $this->_widenColorColumns();
    }

    /**
     * Brings colour columns created at the old 30-character width up to
     * {@see Settings::COLOR_MAX_LENGTH}.
     *
     * A column that is merely present is not a column that is wide enough, and
     * the add-if-missing loop above cannot see the difference — so a database
     * created before the width changed would keep rejecting the longer
     * `hsla(...)` values the model now accepts. Widening is loss-free in both
     * supported drivers, and skipped where the column is already at least this
     * wide so re-running the migration alters nothing.
     */
    private function _widenColorColumns(): void
    {
        $columns = $this->db->getSchema()->getTableSchema('{{%cookieconsent_settings}}', true)?->columns ?? [];

        foreach (self::COLOR_FIELDS as $field) {
            $column = $columns[$field] ?? null;

            if ($column === null || (int) $column->size >= Settings::COLOR_MAX_LENGTH) {
                continue;
            }

            $this->alterColumn(
                '{{%cookieconsent_settings}}',
                $field,
                $this->string(Settings::COLOR_MAX_LENGTH)->null()
            );
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
            $columns[$field] = $this->string(Settings::COLOR_MAX_LENGTH)->null();
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
                // PostgreSQL cannot add a NOT NULL column to a populated table
                // without a value. Add siteId nullable, populate the legacy
                // global/site rows below, then make it required.
                $this->addColumn(
                    '{{%cookieconsent_settings}}',
                    $field,
                    $field === 'siteId' ? $this->integer()->null() : $definition
                );
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

        $this->alterColumn('{{%cookieconsent_settings}}', 'siteId', $this->integer()->notNull());

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
                'gcmSignals'  => Json::encode(array_values((array) ($category['gcmSignals'] ?? []))),
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
        $this->createIndex(null, '{{%cookieconsent_cookie}}', 'name');

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
     *
     * `dateCreated` is when a name was first seen and `lastSeen` when it was
     * most recently seen. `name` compares case-sensitively on both drivers,
     * because cookie names are case-sensitive: `_GA` and `_ga` are two
     * cookies. PostgreSQL does this by default; on MySQL the column needs a
     * binary collation, or the unique index treats them as one.
     */
    private function _createDetectedCookieTableIfMissing(): void
    {
        $table = '{{%cookieconsent_detected_cookie}}';

        if (!$this->db->tableExists($table)) {
            $this->createTable($table, [
                'id'          => $this->primaryKey(),
                'siteId'      => $this->integer()->notNull(),
                'name'        => $this->string(255)->notNull(),
                'isDismissed' => $this->boolean()->notNull()->defaultValue(false),
                'lastSeen'    => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid'         => $this->uid(),
            ]);

            $this->createIndex(null, $table, ['siteId', 'name'], true);
        } elseif (!$this->db->columnExists($table, 'lastSeen')) {
            $this->addColumn($table, 'lastSeen', $this->dateTime()->null());

            // Until now "last seen" was dateUpdated, which is the best value
            // there is for rows recorded before the column existed.
            // Without touching dateUpdated itself, which is what is being read.
            $this->update($table, ['lastSeen' => new \yii\db\Expression('[[dateUpdated]]')], '', [], false);
        }

        $this->_makeDetectedNameCaseSensitive();
    }

    /**
     * On MySQL, gives `cookieconsent_detected_cookie.name` the binary
     * collation of its own character set, unless it already has one.
     * No-op on PostgreSQL, whose text comparison is already case-sensitive.
     */
    private function _makeDetectedNameCaseSensitive(): void
    {
        if ($this->driver !== 'mysql') {
            return;
        }

        $table = $this->db->getSchema()->getRawTableName('{{%cookieconsent_detected_cookie}}');

        $column = (new \craft\db\Query())
            ->select(['CHARACTER_SET_NAME', 'COLLATION_NAME'])
            ->from('information_schema.COLUMNS')
            ->where([
                'TABLE_SCHEMA' => new \yii\db\Expression('DATABASE()'),
                'TABLE_NAME'   => $table,
                'COLUMN_NAME'  => 'name',
            ])
            ->one($this->db);

        if (!$column || str_ends_with((string) $column['COLLATION_NAME'], '_bin')) {
            return;
        }

        $charset = preg_replace('/[^a-z0-9_]/i', '', (string) $column['CHARACTER_SET_NAME']) ?: 'utf8mb4';

        $this->execute(sprintf(
            'ALTER TABLE %s MODIFY %s VARCHAR(255) CHARACTER SET %s COLLATE %s_bin NOT NULL',
            $this->db->quoteTableName($table),
            $this->db->quoteColumnName('name'),
            $charset,
            $charset
        ));
    }

    /**
     * Removes settings rows (with their categories and cookies) and detected
     * cookies belonging to sites that no longer exist. They can never be shown
     * or edited; deletions from now on are cleaned up as they happen (see
     * Plugin::_registerSiteCleanup()). Consent records are kept as evidence.
     * Nothing matches on a fresh install.
     */
    private function _pruneOrphanedSiteRows(): void
    {
        if (!$this->db->tableExists('{{%sites}}') || !$this->db->tableExists('{{%cookieconsent_settings}}')) {
            return;
        }

        // Soft-deleted sites count as deleted: their rows are unreachable too.
        $siteIds = (new \craft\db\Query())->select(['id'])->from('{{%sites}}')->where(['dateDeleted' => null])->column($this->db);

        if ($siteIds === []) {
            return;
        }

        $orphaned = (new \craft\db\Query())
            ->select(['id'])
            ->from('{{%cookieconsent_settings}}')
            ->where(['not', ['siteId' => 0]])
            ->andWhere(['not in', 'siteId', $siteIds])
            ->column($this->db);

        if ($orphaned !== []) {
            // Children first: the category foreign key may be missing on the
            // oldest databases, so the cascade is not relied upon.
            $this->delete('{{%cookieconsent_category}}', ['settingsId' => $orphaned]);
            $this->delete('{{%cookieconsent_cookie}}', ['settingsId' => $orphaned]);
            $this->delete('{{%cookieconsent_settings}}', ['id' => $orphaned]);
        }

        $this->delete('{{%cookieconsent_detected_cookie}}', ['not in', 'siteId', $siteIds]);
    }

    /**
     * Settings columns that no longer mean anything. `fixedPosition` was a
     * control-panel switch no layout ever read (see Settings::$fixedPosition);
     * its values carry no information worth keeping.
     */
    private function _dropRetiredSettingsColumns(): void
    {
        foreach (['fixedPosition'] as $column) {
            if ($this->db->tableExists('{{%cookieconsent_settings}}')
                && $this->db->columnExists('{{%cookieconsent_settings}}', $column)) {
                $this->dropColumn('{{%cookieconsent_settings}}', $column);
            }
        }
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

    /**
     * Returns whether a table already has an index with the exact columns.
     * Index names vary by database driver, so column identity is the durable
     * way to make schema reconciliation idempotent.
     *
     * @param string[] $columns
     */
    private function _hasIndex(string $table, array $columns): bool
    {
        foreach ($this->db->getSchema()->getTableIndexes($table) as $index) {
            if ($index->columnNames === $columns) {
                return true;
            }
        }

        return false;
    }
}
