<?php

namespace sfsinfotech\craftcookieconsentkit\variables;

use sfsinfotech\craftcookieconsentkit\Plugin;

/**
 * Cookie Consent Twig Variable.
 *
 * Exposes plugin functionality to Twig templates via {{ craft.cookieConsent.* }}.
 *
 * TODO: Add methods as features are built out.
 */
class CookieConsentVariable
{
    /**
     * Returns the plugin settings as an array for use in templates.
     *
     * Usage: {{ craft.cookieConsent.settings.bannerEnabled }}
     */
    public function settings(): \sfsinfotech\craftcookieconsentkit\models\Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    /**
     * Returns the list of configured consent categories.
     *
     * Usage: {% for category in craft.cookieConsent.categories %}
     *
     * @return string[]
     */
    public function categories(): array
    {
        return Plugin::getInstance()->getSettings()->categories;
    }

    /**
     * Returns true if the banner is enabled for the current request context.
     *
     * Usage: {% if craft.cookieConsent.isBannerEnabled %}
     *
     * TODO: factor in geo-targeting and per-site overrides.
     */
    public function isBannerEnabled(): bool
    {
        return Plugin::getInstance()->getSettings()->bannerEnabled;
    }
}
