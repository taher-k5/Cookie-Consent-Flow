<?php

namespace sfsinfotech\craftcookieconsentflow\records;

use craft\db\ActiveRecord;

/**
 * Cookie Category Record — one row per cookie consent category, belonging
 * to a `cookieconsent_settings` row (global or a specific site's override).
 * A settings row with no category rows inherits categories from global,
 * exactly like a NULL column did before this table existed.
 *
 * @property int         $id
 * @property int         $settingsId  FK to cookieconsent_settings.id (CASCADE on delete).
 * @property string      $key         Machine name, e.g. 'analytics'.
 * @property string      $label       Display name.
 * @property string|null $description Shown in the preferences modal.
 * @property bool        $isDefault   Pre-checked in the preferences panel.
 * @property bool        $isLocked    Always enabled, toggle disabled.
 * @property int         $sortOrder   Display order within its settings row.
 * @property string      $dateCreated
 * @property string      $dateUpdated
 * @property string      $uid
 */
class CookieCategoryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%cookieconsent_category}}';
    }
}
