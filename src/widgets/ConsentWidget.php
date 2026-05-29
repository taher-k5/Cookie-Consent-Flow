<?php

namespace sfsinfotech\craftcookieconsentkit\widgets;

use Craft;
use craft\base\Widget;

/**
 * Consent Widget — CP Dashboard widget showing a consent activity summary.
 *
 * TODO: Implement data fetching and widget body rendering.
 */
class ConsentWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('cookie-consent-kit', 'Consent Overview');
    }

    public static function icon(): ?string
    {
        return '@sfsinfotech/craftcookieconsentkit/icon-mask.svg';
    }

    public function getTitle(): ?string
    {
        return Craft::t('cookie-consent-kit', 'Consent Overview');
    }

    public function getBodyHtml(): ?string
    {
        // TODO: Query ConsentLogRecord for summary stats and render a template.
        return Craft::$app->getView()->renderTemplate(
            'cookie-consent-kit/widgets/consent-overview',
            ['stats' => []]
        );
    }
}
