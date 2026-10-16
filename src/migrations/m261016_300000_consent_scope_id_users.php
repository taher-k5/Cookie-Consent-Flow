<?php

namespace sfsinfotech\craftcookieconsentflow\migrations;

use craft\db\Migration;

/**
 * Schema for 1.0.5: adds the `consentScope`, `showConsentId`, `linkUsers` and
 * `userDeleteAction` settings columns, the consent record's `userId`, and the
 * `cookieconsent_user_visitor` table.
 *
 * Like every migration since the first release, this brings the database to
 * the schema `Install.php` defines rather than describing the change itself:
 * {@see Install::reconcile()} is idempotent and converges any earlier state,
 * so one code path serves fresh installs and upgrades alike.
 *
 * Every new column is nullable, and a NULL on the global row reads as the
 * model default — per-site consent, consent ID not shown, records not linked
 * to users — so existing installs, and every visitor's stored decision, are
 * unchanged until an admin chooses otherwise.
 */
class m261016_300000_consent_scope_id_users extends Migration
{
    public function safeUp(): bool
    {
        $install = new Install(['db' => $this->db]);
        $install->reconcile();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261016_300000_consent_scope_id_users cannot be reverted.\n";

        return false;
    }
}
