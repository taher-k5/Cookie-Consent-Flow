<?php

namespace sfsinfotech\craftcookieconsentflow\events;

use sfsinfotech\craftcookieconsentflow\models\Settings;
use yii\base\Event;

/**
 * BeforeBannerRenderEvent — fired before the consent banner HTML is generated.
 *
 * Set $event->cancel = true to suppress the banner entirely.
 */
class BeforeBannerRenderEvent extends Event
{
    /** The current plugin settings at the time of render. */
    public Settings $settings;

    /**
     * Set to true to cancel banner rendering: auto-injection adds nothing to
     * the page, and the renderBanner() Twig method returns an empty string.
     */
    public bool $cancel = false;
}
