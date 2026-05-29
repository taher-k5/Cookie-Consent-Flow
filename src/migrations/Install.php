<?php

namespace sfsinfotech\craftcookieconsentkit\migrations;

use craft\db\Migration;

/**
 * Install migration — creates all database tables required by Cookie Consent Kit.
 */
class Install extends Migration
{
    public string $driver;

    // -------------------------------------------------------------------------
    // Install
    // -------------------------------------------------------------------------

    public function safeUp(): bool
    {
        $this->driver = \Craft::$app->getConfig()->getDb()->driver;

        $this->_createSettingsTable();
        $this->_createConsentLogTable();

        return true;
    }

    // -------------------------------------------------------------------------
    // Uninstall
    // -------------------------------------------------------------------------

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%cookieconsent_log}}');
        $this->dropTableIfExists('{{%cookieconsent_settings}}');

        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function _createSettingsTable(): void
    {
        if ($this->db->tableExists('{{%cookieconsent_settings}}')) {
            return;
        }

        $this->createTable('{{%cookieconsent_settings}}', [
            'id'                 => $this->primaryKey(),
            'bannerEnabled'      => $this->boolean()->notNull()->defaultValue(true),
            'bannerPosition'     => $this->string(20)->notNull()->defaultValue('bottom'),
            'bannerHeading'      => $this->string(255)->notNull()->defaultValue(''),
            'bannerBody'         => $this->text()->notNull(),
            'categories'         => $this->text()->notNull(),
            'geoEnabled'         => $this->boolean()->notNull()->defaultValue(false),
            'geoTargetCountries' => $this->text()->notNull(),
            'logEnabled'         => $this->boolean()->notNull()->defaultValue(true),
            'logRetentionDays'   => $this->integer()->notNull()->defaultValue(365),
            'siteOverrides'      => $this->mediumText()->notNull(),
            'dateCreated'        => $this->dateTime()->notNull(),
            'dateUpdated'        => $this->dateTime()->notNull(),
            'uid'                => $this->uid(),
        ]);

        // Seed with default settings row.
        $this->insert('{{%cookieconsent_settings}}', [
            'bannerEnabled'      => true,
            'bannerPosition'     => 'bottom',
            'bannerHeading'      => '',
            'bannerBody'         => '',
            'categories'         => '["necessary","analytics","marketing","preferences"]',
            'geoEnabled'         => false,
            'geoTargetCountries' => '[]',
            'logEnabled'         => true,
            'logRetentionDays'   => 365,
            'siteOverrides'      => '[]',
        ]);
    }

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
            'countryCode' => $this->string(2)->null(),
            'userAgent'   => $this->string(500)->notNull()->defaultValue(''),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        $this->createIndex(null, '{{%cookieconsent_log}}', ['visitorUuid']);
        $this->createIndex(null, '{{%cookieconsent_log}}', ['siteId', 'dateCreated']);

        $this->addForeignKey(
            null,
            '{{%cookieconsent_log}}',
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE',
            null
        );
    }
}
