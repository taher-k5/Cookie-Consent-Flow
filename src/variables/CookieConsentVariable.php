<?php

namespace sfsinfotech\craftcookieconsentflow\variables;

use Craft;
use craft\helpers\Html;
use craft\web\View;
use sfsinfotech\craftcookieconsentflow\events\BeforeBannerRenderEvent;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\web\assets\banner\BannerAsset;
use Twig\Markup;

/**
 * Cookie Consent Twig API — `{{ craft.cookieConsent.* }}`.
 *
 * ## Cache safety
 *
 * Everything here renders identically for every visitor and is safe inside
 * cached HTML. There is deliberately **no** server-side "has this visitor
 * consented?" helper: such a check would be evaluated once, at cache-write
 * time, and then serve one visitor's answer to everyone else. Gate optional
 * functionality client-side instead — with `data-cck-category` on the tag, or
 * by listening for `cookieConsent:changed`.
 */
class CookieConsentVariable
{
    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    /**
     * Renders the banner and preference centre.
     *
     * Only needed when the markup must sit somewhere specific. Calling this
     * takes ownership for the current request, so the normal response hook
     * will not append a duplicate before `</body>`.
     *
     * Usage: `{{ craft.cookieConsent.renderBanner() }}`
     */
    public function renderBanner(): Markup
    {
        $plugin   = Plugin::getInstance();
        $settings = $plugin->cookieSettings->getEffectiveSettings();

        if (!$settings->bannerEnabled) {
            return $this->_empty();
        }

        // The response hook runs after template rendering. Mark this request
        // before firing the event so a manual render (including a listener
        // deliberately cancelling it) is never followed by a second,
        // auto-injected banner with duplicate IDs and event handlers.
        $plugin->markBannerHandled();

        // Geo-targeting is deliberately NOT evaluated here. Deciding it while
        // rendering would bake the first visitor's country into cached HTML;
        // the markup is emitted for everyone and the runtime resolves the
        // country per visitor before showing anything.
        $event = new BeforeBannerRenderEvent(['settings' => $settings]);
        $plugin->trigger(Plugin::EVENT_BEFORE_BANNER_RENDER, $event);

        if ($event->cancel) {
            return $this->_empty();
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(BannerAsset::class);
        $view->registerJs(
            'window.cckConfig = ' . \craft\helpers\Json::encode($plugin->buildRuntimeConfig($settings)) . ';',
            View::POS_HEAD
        );

        return new Markup($this->_renderSiteTemplate('banner/_banner', ['settings' => $settings]), 'utf-8');
    }

    /**
     * The Google Consent Mode v2 snippet, for placement in `<head>` **before**
     * any Google tag (gtag.js, Tag Manager).
     *
     * Only needed when "Auto-inject into <head>" is off — otherwise the plugin
     * already places it as the first thing in `<head>`. Calling it when the
     * snippet has already been emitted for this request returns nothing, so
     * having both on is harmless rather than duplicating the default command.
     *
     * Contains no visitor-specific data and is safe in cached HTML: it reads
     * the visitor's stored decision on their own device, at runtime.
     *
     * Usage: `{{ craft.cookieConsent.consentModeScript() }}`
     */
    public function consentModeScript(): Markup
    {
        $plugin   = Plugin::getInstance();
        $settings = $plugin->cookieSettings->getEffectiveSettings();

        return new Markup($plugin->consentMode->renderScript($settings), 'utf-8');
    }

    /**
     * A button that reopens the preference centre — the "withdraw or change
     * consent at any time" control, typically placed in the footer.
     *
     * Usage: `{{ craft.cookieConsent.renderPreferencesButton() }}`
     */
    public function renderPreferencesButton(?string $label = null, array $attributes = []): Markup
    {
        $settings = Plugin::getInstance()->cookieSettings->getEffectiveSettings();
        Craft::$app->getView()->registerAssetBundle(BannerAsset::class);

        return $this->_button(
            $label ?? $settings->customizeButtonText,
            'open-preferences',
            'cck-btn--reopen',
            $attributes
        );
    }

    /**
     * A button that clears the stored decision and asks again.
     *
     * Usage: `{{ craft.cookieConsent.resetConsentButton() }}`
     */
    public function resetConsentButton(?string $label = null, array $attributes = []): Markup
    {
        Craft::$app->getView()->registerAssetBundle(BannerAsset::class);

        return $this->_button(
            $label ?? Craft::t('cookie-consent-flow', 'Reset Cookie Preferences'),
            'reset-consent',
            'cck-btn--reset',
            $attributes
        );
    }

    /**
     * @deprecated Use resetConsentButton(). The old name reads like an action
     *             but only ever rendered a button; kept so existing templates
     *             keep working.
     */
    public function resetConsent(?string $label = null): Markup
    {
        return $this->resetConsentButton($label);
    }

    // ---------------------------------------------------------------------
    // Cookie disclosure
    // ---------------------------------------------------------------------

    /**
     * Every documented cookie for the current site, in display order.
     *
     * Usage:
     * ```twig
     * {% for cookie in craft.cookieConsent.cookies() %}
     *   {{ cookie.name }} — {{ cookie.provider }} ({{ cookie.duration }})
     * {% endfor %}
     * ```
     *
     * @return array<int, array<string, mixed>>
     */
    public function cookies(?string $categoryKey = null): array
    {
        $plugin = Plugin::getInstance();
        $all    = $plugin->cookieDefinitions->getAll($this->_effectiveSettingsId());

        if ($categoryKey === null) {
            return $all;
        }

        return array_values(array_filter($all, static fn(array $c): bool => $c['categoryKey'] === $categoryKey));
    }

    /**
     * Documented cookies grouped by category key. A category with nothing
     * documented is simply absent.
     *
     * Usage: `{{ craft.cookieConsent.cookiesByCategory['analytics'] }}`
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function cookiesByCategory(): array
    {
        return Plugin::getInstance()->cookieDefinitions->getGroupedByCategory($this->_effectiveSettingsId());
    }

    /** @deprecated Use cookiesByCategory(). Same data, clearer name. */
    public function cookieDefinitionsByCategory(): array
    {
        return $this->cookiesByCategory();
    }

    /**
     * Renders the documented cookies as an accessible table, grouped by
     * category — the "what cookies do you use and why" table that belongs on
     * a cookie policy page.
     *
     * Override `templates/cookie-consent-flow/_cookie-table.twig` in your own
     * site templates to change the markup; `cookies()` is there for building
     * something entirely different.
     *
     * Usage: `{{ craft.cookieConsent.cookieTable() }}`
     */
    public function cookieTable(?string $categoryKey = null): Markup
    {
        $settings = Plugin::getInstance()->cookieSettings->getEffectiveSettings();

        $grouped = $categoryKey !== null
            ? array_filter($this->cookiesByCategory(), static fn(string $k): bool => $k === $categoryKey, ARRAY_FILTER_USE_KEY)
            : $this->cookiesByCategory();

        $labels = [];
        foreach ($settings->categories as $category) {
            $labels[$category['key']] = $category['label'] ?? $category['key'];
        }

        return new Markup(
            $this->_renderSiteTemplate('_cookie-table', ['grouped' => $grouped, 'labels' => $labels]),
            'utf-8'
        );
    }

    // ---------------------------------------------------------------------
    // Data
    // ---------------------------------------------------------------------

    /**
     * The effective settings for the current site.
     *
     * Usage: `{{ craft.cookieConsent.settings.bannerHeading }}`
     */
    public function settings(): Settings
    {
        return Plugin::getInstance()->cookieSettings->getEffectiveSettings();
    }

    /**
     * The configured consent categories for the current site.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(): array
    {
        return $this->settings()->categories;
    }

    /**
     * Whether the banner is enabled for the current site.
     *
     * Note this reports *configuration*, not whether a given visitor will see
     * the banner — that depends on their stored decision and, with
     * geo-targeting on, their country, neither of which is knowable at render
     * time on a cacheable page.
     */
    public function isBannerEnabled(): bool
    {
        return $this->settings()->bannerEnabled;
    }

    /**
     * The country the request resolved to, or null.
     *
     * **Do not branch cached template output on this.** It is exposed for
     * uncached contexts (a controller, a `{% cache %}`-excluded fragment) and
     * for debugging. On a cacheable page the first visitor's country would be
     * baked in for everyone.
     */
    public function countryCode(): ?string
    {
        return Plugin::getInstance()->geo->getCountryCode();
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function _effectiveSettingsId(): int
    {
        $plugin = Plugin::getInstance();

        return $plugin->cookieDefinitions->getEffectiveSettingsId(
            Craft::$app->getSites()->getCurrentSite()->id
        );
    }

    /**
     * Renders one of the plugin's site templates, restoring the previous
     * template mode even if rendering throws.
     *
     * @param array<string, mixed> $variables
     */
    private function _renderSiteTemplate(string $template, array $variables): string
    {
        $view        = Craft::$app->getView();
        $currentMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            return $view->renderTemplate('cookie-consent-flow/' . $template, $variables);
        } catch (\Throwable $e) {
            Craft::error('Cookie Consent Flow template render failed: ' . $e->getMessage(), __METHOD__);

            return '';
        } finally {
            $view->setTemplateMode($currentMode);
        }
    }

    /**
     * @param array<string, mixed> $attributes Extra HTML attributes, escaped by Html::renderTagAttributes().
     */
    private function _button(string $label, string $action, string $modifier, array $attributes): Markup
    {
        $attributes = array_merge([
            'type'            => 'button',
            'class'           => ['cck-btn', $modifier],
            'data-cck-action' => $action,
        ], $attributes);

        return new Markup(
            '<button' . Html::renderTagAttributes($attributes) . '>' . Html::encode($label) . '</button>',
            'utf-8'
        );
    }

    private function _empty(): Markup
    {
        return new Markup('', 'utf-8');
    }
}
