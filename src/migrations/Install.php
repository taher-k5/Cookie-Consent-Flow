<?php

namespace sfsinfotech\craftcookieconsentflow\migrations;

use craft\db\Migration;

/**
 * Install migration — creates all database tables required by Cookie Consent Flow.
 */
class Install extends Migration
{
    public string $driver;

    // Install

    public function safeUp(): bool
    {
        $this->driver = \Craft::$app->getConfig()->getDb()->driver;

        $this->_createConsentLogTable();

        return true;
    }

    // Uninstall

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%cookieconsent_log}}');

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
            'countryCode' => $this->string(2)->null(),
            'userAgent'   => $this->string(500)->notNull()->defaultValue(''),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        $this->createIndex(null, '{{%cookieconsent_log}}', 'visitorUuid');
        $this->createIndex(null, '{{%cookieconsent_log}}', ['siteId', 'action']);
    }
}

