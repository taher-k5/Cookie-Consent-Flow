<?php

namespace sfsinfotech\craftcookieconsentflow\records;

use craft\db\ActiveRecord;

/**
 * Detected Cookie Record — a raw signal, one row per distinct cookie name
 * actually seen in a visitor's browser (reported by cookie-banner.js on
 * page load, regardless of consent state — see CookieDetectionController).
 * Names only, never values: this exists purely so an admin can see "here's
 * what's actually running on the site that nobody has documented yet,"
 * not to profile visitors.
 *
 * Distinct from CookieDefinitionRecord: this table is what the site is
 * actually doing; that one is what the admin has documented about it.
 * CookieDefinitionService::getUndocumented() is the difference between them.
 *
 * @property int    $id
 * @property int    $siteId
 * @property string $name
 * @property bool   $isDismissed Admin has reviewed and chosen not to document this one.
 * @property string $dateCreated First time this name was seen.
 * @property string $dateUpdated Most recent time this name was seen.
 * @property string $uid
 */
class DetectedCookieRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%cookieconsent_detected_cookie}}';
    }
}
