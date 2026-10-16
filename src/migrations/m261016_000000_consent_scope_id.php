<?php

namespace sfsinfotech\craftcookieconsentflow\migrations;

use craft\db\Migration;

/**
 * Schema for 1.1.0: adds the `consentScope` and `showConsentId` settings
 * columns.
 *
 * Like every migration since the first release, this brings the database to
 * the schema `Install.php` defines rather than describing the change itself:
 * {@see Install::reconcile()} is idempotent and converges any earlier state,
 * so one code path serves fresh installs and upgrades alike.
 *
 * Both columns are added nullable, and a NULL on the global row reads as the
 * model default — per-site consent, consent ID not shown — so existing
 * installs, and every visitor's stored decision, are unchanged until an admin
 * chooses otherwise.
 */
class m261016_000000_consent_scope_id extends Migration
{
    public function safeUp(): bool
    {
        $install = new Install(['db' => $this->db]);
        $install->reconcile();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261016_000000_consent_scope_id cannot be reverted.\n";

        return false;
    }
}
