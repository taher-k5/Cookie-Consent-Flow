<?php

namespace sfsinfotech\craftcookieconsentflow\widgets;

use Craft;
use craft\base\Widget;
use craft\helpers\UrlHelper;
use sfsinfotech\craftcookieconsentflow\helpers\Permissions;
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

    /**
     * Consent figures are only shown to users who may read consent records at
     * all — the widget is on Craft's shared dashboard, which any CP user can
     * add to.
     */
    public static function isSelectable(): bool
    {
        return parent::isSelectable() && Permissions::canAny(Permissions::VIEW_LOGS);
    }

    public function getBodyHtml(): ?string
    {
        if (!Permissions::canAny(Permissions::VIEW_LOGS)) {
            return null;
        }

        // getStats(null) now means "All Sites" (see ConsentService) — the
        // widget wants the current CP site's own numbers, so pass it
        // explicitly rather than relying on a default.
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        // Via the cached statistics layer: a dashboard widget renders on every
        // CP home-page load, which is the worst possible place to put an
        // uncached aggregate over a growing table.
        $stats = Plugin::getInstance()->statistics->getActionCounts($siteId);

        return Craft::$app->getView()->renderTemplate(
            'cookie-consent-flow/widgets/consent-overview',
            [
                'stats'   => $stats,
                'logsUrl' => UrlHelper::cpUrl('cookie-consent-flow/logs'),
            ]
        );
    }
}
