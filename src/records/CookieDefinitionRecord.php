<?php

namespace sfsinfotech\craftcookieconsentflow\records;

use craft\db\ActiveRecord;

/**
 * Cookie Definition Record — one row per documented cookie (disclosure
 * content: name/provider/purpose/duration), grouped by category key. This is
 * per-cookie transparency content (GDPR/ePrivacy Art. 13 require naming what
 * cookies are used and why, not just a category-level blurb) — it plays no
 * part in blocking, which is still the data-cck-category tagging convention
 * on scripts/iframes. Per-site, exactly like CookieCategoryRecord: `settingsId`
 * FKs to `cookieconsent_settings.id` — the global row, or a specific site's
 * override row. A site with no cookie rows of its own inherits the global
 * row's list entirely (same "presence of child rows = override" convention
 * already used for categories).
 *
 * @property int          $id
 * @property int           $settingsId  FK to cookieconsent_settings.id (global row, or a site's own).
 * @property string       $categoryKey Matches one of Settings::$categories[].key.
 * @property string       $name        Cookie name or pattern, e.g. "_ga" or "_ga_*".
 * @property string|null  $provider    Who sets it, e.g. "Google Analytics".
 * @property string|null  $purpose     What it's used for, shown to visitors.
 * @property string|null  $duration    e.g. "2 years", "Session".
 * @property int          $sortOrder
 * @property string       $dateCreated
 * @property string       $dateUpdated
 * @property string       $uid
 */
class CookieDefinitionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%cookieconsent_cookie}}';
    }
}
