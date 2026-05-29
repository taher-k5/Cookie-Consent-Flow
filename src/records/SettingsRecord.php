<?php

namespace sfsinfotech\craftcookieconsentkit\records;

use craft\db\ActiveRecord;

/**
 * Settings Record — single-row table storing plugin settings per environment.
 *
 * Stored in the database instead of project config so that different
 * environments and sites can carry their own consent configuration.
 *
 * @property int    $id
 * @property bool   $bannerEnabled
 * @property string $bannerPosition
 * @property string $bannerHeading
 * @property string $bannerBody
 * @property string $categories         JSON-encoded array.
 * @property bool   $geoEnabled
 * @property string $geoTargetCountries JSON-encoded array.
 * @property bool   $logEnabled
 * @property int    $logRetentionDays
 * @property string $siteOverrides      JSON-encoded array.
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%cookieconsent_settings}}';
    }
}
