<?php

namespace sfsinfotech\craftcookieconsentflow\variables;

use Craft;
use craft\web\View;
use sfsinfotech\craftcookieconsentflow\events\BeforeBannerRenderEvent;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\web\assets\banner\BannerAsset;
use Twig\Markup;
use yii\base\Event;

/**
 * Cookie Consent Twig Variable.
 *
 * Accessible in any Twig template via {{ craft.cookieConsent.* }}.
 */
class CookieConsentVariable
{
    // Banner rendering
    /**
     * Renders the full cookie consent banner + preferences modal.
     *
     * The banner is hidden by default; the frontend JS reveals it when no
     * consent has been stored in the visitor's browser.
     *
     * Usage: {{ craft.cookieConsent.renderBanner() }}
     */
    public function renderBanner(): Markup
    {
        $plugin   = Plugin::getInstance();
        $settings = $plugin->cookieSettings->getEffectiveSettings();

        if (!$settings->bannerEnabled) {
            return new Markup('', 'utf-8');
        }

        // Geo check
        if (!$plugin->geo->shouldShowBanner($settings)) {
            return new Markup('', 'utf-8');
        }

        // Fire before-render event (allow 3rd-party cancellation)
        $event = new BeforeBannerRenderEvent(['settings' => $settings]);
        $plugin->trigger(
            Plugin::EVENT_BEFORE_BANNER_RENDER,
            $event
        );

        if ($event->cancel) {
            return new Markup('', 'utf-8');
        }

        // Register frontend assets
        $view = Craft::$app->getView();
        $view->registerAssetBundle(BannerAsset::class);

        // Inline config for the JS module
        $saveUrl = \craft\helpers\UrlHelper::actionUrl('cookie-consent-flow/consent/save');
        $csrfTokenName  = Craft::$app->getConfig()->getGeneral()->csrfTokenName;
        $csrfTokenValue = Craft::$app->getRequest()->getCsrfToken();
        $allCategories  = $settings->getCategoryKeys();
        $lockedKeys     = $settings->getLockedCategoryKeys();

        $configJson = \craft\helpers\Json::encode([
            'saveUrl'          => $saveUrl,
            'reportCookiesUrl' => \craft\helpers\UrlHelper::actionUrl('cookie-consent-flow/cookie-detection/report'),
            'csrfTokenName'    => $csrfTokenName,
            'csrfToken'        => $csrfTokenValue,
            'allCategories'    => $allCategories,
            'lockedCategories' => $lockedKeys,
            'siteId'           => Craft::$app->getSites()->getCurrentSite()->id,
            'consentExpiryDays'=> $settings->consentExpiryDays,
            'policyVersion'    => $settings->policyVersion,
        ]);

        $view->registerJs("window.cckConfig = {$configJson};", View::POS_HEAD);

        // Render banner template in site mode so the site template root is used
        $currentMode = $view->getTemplateMode();

        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        $html = $view->renderTemplate(
                'cookie-consent-flow/banner/_banner',
            [
                'settings' => $settings,
            ]
        );

        $view->setTemplateMode($currentMode);

        return new Markup($html, 'utf-8');
    }

    /**
     * Renders a "Manage Cookie Preferences" button that reopens the modal.
     *
     * Usage: {{ craft.cookieConsent.renderPreferencesButton() }}
     */
    public function renderPreferencesButton(?string $label = null): Markup
    {
        $settings = Plugin::getInstance()->cookieSettings->getEffectiveSettings();
        Craft::$app->getView()->registerAssetBundle(BannerAsset::class);

        $btnLabel = htmlspecialchars($label ?? $settings->customizeButtonText, ENT_QUOTES, 'UTF-8');
        $html = '<button type="button" class="cck-btn cck-btn--reopen" data-cck-action="open-preferences" aria-label="' . $btnLabel . '">'
              . $btnLabel
              . '</button>';

        return new Markup($html, 'utf-8');
    }

    /**
     * Renders a "Reset Cookie Preferences" button that clears stored consent
     * and shows the banner again.
     *
     * Usage: {{ craft.cookieConsent.resetConsent() }}
     */
    public function resetConsent(?string $label = null): Markup
    {
        Craft::$app->getView()->registerAssetBundle(BannerAsset::class);

        $btnLabel = htmlspecialchars(
            $label ?? Craft::t('cookie-consent-flow', 'Reset Cookie Preferences'),
            ENT_QUOTES,
            'UTF-8'
        );
        $html = '<button type="button" class="cck-btn cck-btn--reset" data-cck-action="reset-consent" aria-label="' . $btnLabel . '">'
              . $btnLabel
              . '</button>';

        return new Markup($html, 'utf-8');
    }

    // Data helpers
    /**
     * Returns the plugin settings object.
     *
     * Usage: {{ craft.cookieConsent.settings.bannerEnabled }}
     */
    public function settings(): \sfsinfotech\craftcookieconsentflow\models\Settings
    {
        return Plugin::getInstance()->cookieSettings->getEffectiveSettings();
    }

    /**
     * Returns the configured consent categories as an array.
     *
     * Usage: {% for cat in craft.cookieConsent.categories %}
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(): array
    {
        return Plugin::getInstance()->cookieSettings->getEffectiveSettings()->categories;
    }

    /**
     * Returns true if the banner is enabled for the current request.
     *
     * Usage: {% if craft.cookieConsent.isBannerEnabled %}
     */
    public function isBannerEnabled(): bool
    {
        $plugin   = Plugin::getInstance();
        $settings = $plugin->cookieSettings->getEffectiveSettings();

        return $settings->bannerEnabled && $plugin->geo->shouldShowBanner($settings);
    }

    /**
     * Returns documented cookies (name/provider/purpose/duration) grouped by
     * category key, for display under each category in the preferences
     * modal — so visitors see WHAT they're consenting to, not just a
     * category-level description.
     *
     * Usage: {{ craft.cookieConsent.cookieDefinitionsByCategory[category.key] }}
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function cookieDefinitionsByCategory(): array
    {
        $plugin = Plugin::getInstance();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        return $plugin->cookieDefinitions->getGroupedByCategory(
            $plugin->cookieDefinitions->getEffectiveSettingsId($siteId)
        );
    }
}

