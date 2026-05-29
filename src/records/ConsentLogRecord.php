<?php

namespace sfsinfotech\craftcookieconsentkit\records;

use craft\db\ActiveRecord;

/**
 * Consent Log Record — one row per consent event (accept/reject/partial).
 *
 * @property int         $id
 * @property string      $visitorUuid  Anonymous UUID stored in first-party cookie.
 * @property string      $ipHash       SHA-256 hash of visitor IP (never store raw IP).
 * @property string      $siteId       Craft site ID.
 * @property string      $categories   JSON-encoded array of accepted category keys.
 * @property string      $action       'accept_all' | 'reject_all' | 'custom'
 * @property string|null $countryCode  ISO 3166-1 alpha-2, resolved via GeoService.
 * @property string      $userAgent    Raw User-Agent string (truncated to 500 chars).
 * @property string      $dateCreated
 * @property string      $dateUpdated
 * @property string      $uid
 */
class ConsentLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%cookieconsent_log}}';
    }
}
