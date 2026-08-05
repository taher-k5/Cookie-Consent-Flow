<?php

namespace sfsinfotech\craftcookieconsentflow\widgets;

use Craft;
use craft\base\Widget;
use craft\helpers\UrlHelper;
use sfsinfotech\craftcookieconsentflow\Plugin;

/**
 * Consent Widget — CP Dashboard widget showing a consent activity summary.
 */
class ConsentWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('cookie-consent-flow', 'Consent Overview');
    }

    public static function icon(): ?string
    {
        return '@sfsinfotech/craftcookieconsentflow/icon-mask.svg';
    }

    public function getTitle(): ?string
    {
        return Craft::t('cookie-consent-flow', 'Consent Overview');
    }

    public function getBodyHtml(): ?string
    {
        // getStats(null) now means "All Sites" (see ConsentService) — the
        // widget wants the current CP site's own numbers, so pass it
        // explicitly rather than relying on a default.
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $stats  = Plugin::getInstance()->consent->getStats($siteId);

        return Craft::$app->getView()->renderTemplate(
            'cookie-consent-flow/widgets/consent-overview',
            [
                'stats'   => $stats,
                'logsUrl' => UrlHelper::cpUrl('cookie-consent-flow/logs'),
            ]
        );
    }
}
