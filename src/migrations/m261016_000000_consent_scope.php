<?php

namespace sfsinfotech\craftcookieconsentflow\migrations;

use craft\db\Migration;

/**
 * Schema 1.9.0: adds the `consentScope` settings column.
 *
 * Like every migration after the first release, this brings the database to
 * the schema `Install.php` defines rather than describing the change itself:
 * {@see Install::reconcile()} is idempotent and converges any earlier state,
 * so one code path serves fresh installs and upgrades alike.
 *
 * The column is added nullable, and a NULL on the global row reads as the
 * model default (`site`), so existing installs keep per-site consent — and
 * every visitor's stored decision — until an admin chooses otherwise.
 */
class m261016_000000_consent_scope extends Migration
{
    public function safeUp(): bool
    {
        $install = new Install(['db' => $this->db]);
        $install->reconcile();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261016_000000_consent_scope cannot be reverted.\n";

        return false;
    }
}
