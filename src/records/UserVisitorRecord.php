<?php

namespace sfsinfotech\craftcookieconsentflow\records;

use craft\db\ActiveRecord;

/**
 * User Visitor Record — one row per Craft user and visitor ID seen together.
 *
 * A visitor ID belongs to one browser; this is what ties a person's browsers
 * to them, so their records can be found whichever browser — and whichever
 * visitor ID, after cookies were cleared — they were made with. Written only
 * from Craft's own session (at login, and at each decision while logged in),
 * never from anything the browser claims.
 *
 * @property int    $id
 * @property int    $userId      Craft user; the row goes with the user.
 * @property string $visitorUuid The visitor ID (consent ID).
 * @property string $lastLinked  When the user was last seen with it.
 * @property string $dateCreated When the user was first seen with it.
 * @property string $dateUpdated
 * @property string $uid
 */
class UserVisitorRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%cookieconsent_user_visitor}}';
    }
}
