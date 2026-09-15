<?php

namespace sfsinfotech\craftcookieconsentflow\records;

use craft\db\ActiveRecord;

/**
 * Settings Record — one row per Craft site plus a global row
 * (`siteId = 0, isGlobal = 1`), one real column per setting. On a site's
 * row, a `NULL` column means "inherit from global" (see SettingsService).
 * Categories are not a column here — see {@see CookieCategoryRecord},
 * foreign-keyed to this record's `id` (a row with none inherits global).
 *
 * @property int         $id
 * @property int         $siteId 0 for the global row, a real Craft site ID otherwise.
 * @property bool        $isGlobal
 * @property bool|null   $bannerEnabled
 * @property string|null $bannerLayout
 * @property string|null $cornerPosition
 * @property int|null    $logoAssetId
 * @property string|null $bannerHeading
 * @property string|null $bannerDescription
 * @property string|null $privacyPolicyUrl
 * @property string|null $privacyPolicyLinkText
 * @property string|null $acceptButtonText
 * @property string|null $rejectButtonText
 * @property string|null $customizeButtonText
 * @property string|null $savePreferencesText
 * @property string|null $closeButtonText
 * @property string|null $bannerBgColor
 * @property string|null $bannerBorderColor
 * @property string|null $overlayColor
 * @property string|null $headingColor
 * @property string|null $descriptionColor
 * @property string|null $linkColor
 * @property string|null $acceptBgColor
 * @property string|null $acceptTextColor
 * @property string|null $acceptBorderColor
 * @property string|null $acceptHoverBgColor
 * @property string|null $acceptHoverTextColor
 * @property string|null $rejectBgColor
 * @property string|null $rejectTextColor
 * @property string|null $rejectBorderColor
 * @property string|null $rejectHoverBgColor
 * @property string|null $rejectHoverTextColor
 * @property string|null $customizeBgColor
 * @property string|null $customizeTextColor
 * @property string|null $customizeBorderColor
 * @property string|null $customizeHoverBgColor
 * @property string|null $customizeHoverTextColor
 * @property string|null $saveBgColor
 * @property string|null $saveTextColor
 * @property string|null $closeIconColor
 * @property string|null $borderRadius
 * @property string|null $padding
 * @property string|null $maxWidth
 * @property string|null $maxHeight
 * @property bool|null   $fullWidth
 * @property bool|null   $shadow
 * @property bool|null   $fixedPosition
 * @property bool|null   $geoEnabled
 * @property string|null $geoTargetCountries JSON-encoded array, whole-value override.
 * @property bool|null   $logEnabled Only meaningful on the global row.
 * @property int|null    $logRetentionDays Only meaningful on the global row.
 * @property int|null    $consentExpiryDays Only meaningful on the global row.
 * @property string|null $policyVersion Only meaningful on the global row.
 * @property string      $dateCreated
 * @property string      $dateUpdated
 * @property string      $uid
 */
class SettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%cookieconsent_settings}}';
    }
}
