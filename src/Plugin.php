<?php

namespace sfsinfotech\craftcookieconsentflow;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use sfsinfotech\craftcookieconsentflow\events\BeforeBannerRenderEvent;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\helpers\Permissions;
use sfsinfotech\craftcookieconsentflow\services\ConsentModeService;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;
use sfsinfotech\craftcookieconsentflow\services\StatisticsService;
use sfsinfotech\craftcookieconsentflow\services\CookieDefinitionService;
use sfsinfotech\craftcookieconsentflow\services\GeoService;
use sfsinfotech\craftcookieconsentflow\services\SettingsService;
use sfsinfotech\craftcookieconsentflow\variables\CookieConsentVariable;
use sfsinfotech\craftcookieconsentflow\widgets\ConsentWidget;
use sfsinfotech\craftcookieconsentflow\web\assets\cp\CpAsset;
use yii\base\Event;

/**
 * Cookie Consent Flow plugin for Craft CMS 5.
 *
 * @property-read ConsentService          $consent
 * @property-read ConsentModeService       $consentMode
 * @property-read GeoService               $geo
 * @property-read SettingsService          $cookieSettings
 * @property-read CookieDefinitionService  $cookieDefinitions
 * @property-read StatisticsService        $statistics
 * @property-read Settings                 $settings
 * @method  Settings getSettings()
 * @method  static Plugin getInstance()
 */
class Plugin extends BasePlugin
{
    public static Plugin $plugin;

    /** Whether a Twig call already rendered (or deliberately suppressed) the banner this request. */
    private bool $_bannerHandled = false;

    public string $schemaVersion = '1.7.0';
    public bool $hasCpSettings   = true;
    public bool $hasCpSection     = true;

    // Event name constants

    /** Fired before the banner HTML is rendered. Set $event->cancel = true to suppress. */
    public const EVENT_BEFORE_BANNER_RENDER = 'beforeBannerRender';

    /** Fired after a visitor's consent has been saved. */
    public const EVENT_AFTER_CONSENT_SAVE   = 'afterConsentSave';

    // Bootstrap
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Craft::setAlias('@sfsinfotech/craftcookieconsentflow', __DIR__);

        $this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
            ? 'sfsinfotech\\craftcookieconsentflow\\console\\controllers'
            : 'sfsinfotech\\craftcookieconsentflow\\controllers';

        $this->_registerServices();
        $this->_registerTemplateRoots();
        $this->_registerCpRoutes();
        $this->_registerVariable();
        $this->_registerWidget();
        $this->_registerPermissions();

        $request = Craft::$app->getRequest();

        if ($request->getIsCpRequest()) {
            $this->_registerCpAsset();
        } elseif (!$request->getIsConsoleRequest()) {
            $this->_registerFrontendBanner();
        }
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    /**
     * Settings are persisted in the plugin's own `cookieconsent_settings`
     * table, not Craft's built-in plugin-settings blob (`craft_plugins.settings`).
     * SettingsService owns loading/caching; this just delegates to it.
     *
     * Craft calls setSettings() (below) during object construction, before
     * init() has registered the `cookieSettings` component — guard against
     * that by falling back to a plain default model in that narrow window.
     */
    public function getSettings(): ?\craft\base\Model
    {
        if (!$this->has('cookieSettings')) {
            return parent::getSettings();
        }

        return $this->cookieSettings->loadSettings();
    }

    /**
     * Craft passes the legacy `craft_plugins.settings` blob here on every
     * plugin load. Settings now live entirely in the plugin's own table
     * (see getSettings()), so that blob is intentionally ignored — applying
     * it here would clobber the freshly-loaded model with stale data.
     */
    public function setSettings(array $settings): void
    {
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('cookie-consent-flow/settings')
        );
    }

    // CP Nav
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $item['label'] = Craft::t('cookie-consent-flow', 'Cookie Consent');
        $item['icon']  = '@sfsinfotech/craftcookieconsentflow/icon-mask.svg';

        $item['subnav'] = [
            'dashboard' => [
                'label' => Craft::t('cookie-consent-flow', 'Dashboard'),
                'url'   => 'cookie-consent-flow',
            ],
            'banner' => [
                'label' => Craft::t('cookie-consent-flow', 'Banner'),
                'url'   => 'cookie-consent-flow/banner',
            ],
            'cookies' => [
                'label' => Craft::t('cookie-consent-flow', 'Cookies'),
                'url'   => 'cookie-consent-flow/cookies',
            ],
            'logs' => [
                'label' => Craft::t('cookie-consent-flow', 'Consent Records'),
                'url'   => 'cookie-consent-flow/logs',
            ],
        ];

        // Only surface the Multi Site Override page on genuinely multi-site
        // installs, to keep the nav uncluttered for the common single-site case.
        // Added before 'settings' below (rather than appended after it) so it
        // sits just to the left of Settings in the tab order.
        if (count(Craft::$app->getSites()->getAllSites()) > 1) {
            $item['subnav']['multi-site-override'] = [
                'label' => Craft::t('cookie-consent-flow', 'Multisite'),
                'url'   => 'cookie-consent-flow/settings/multi-site-override',
            ];
        }

        $item['subnav']['settings'] = [
            'label' => Craft::t('cookie-consent-flow', 'Settings'),
            'url'   => 'cookie-consent-flow/settings',
        ];

        return $item;
    }

    // Private helpers
    private function _registerServices(): void
    {
        $this->setComponents([
            'consent'           => ConsentService::class,
            'consentMode'       => ConsentModeService::class,
            'geo'               => GeoService::class,
            'cookieSettings'    => SettingsService::class,
            'cookieDefinitions' => CookieDefinitionService::class,
            'statistics'        => StatisticsService::class,
        ]);
    }

    private function _registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event): void {
                $event->roots['cookie-consent-flow'] = __DIR__ . '/templates';
            }
        );

        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event): void {
                $event->roots['cookie-consent-flow'] = __DIR__ . '/templates';
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules['cookie-consent-flow']                       = 'cookie-consent-flow/dashboard/index';
                $event->rules['cookie-consent-flow/banner']                = 'cookie-consent-flow/settings/banner';
                $event->rules['POST cookie-consent-flow/banner/save']      = 'cookie-consent-flow/settings/save-banner';
                $event->rules['cookie-consent-flow/logs']                  = 'cookie-consent-flow/logs/index';
                $event->rules['cookie-consent-flow/logs/view/<id:\d+>']    = 'cookie-consent-flow/logs/view';
                $event->rules['cookie-consent-flow/cookies']               = 'cookie-consent-flow/cookies/index';
                $event->rules['POST cookie-consent-flow/cookies/save']     = 'cookie-consent-flow/cookies/save';
                $event->rules['POST cookie-consent-flow/cookies/dismiss-detected'] = 'cookie-consent-flow/cookies/dismiss-detected';
                $event->rules['cookie-consent-flow/settings']              = 'cookie-consent-flow/settings/index';
                $event->rules['POST cookie-consent-flow/settings/save']    = 'cookie-consent-flow/settings/save';
                $event->rules['cookie-consent-flow/settings/multi-site-override']            = 'cookie-consent-flow/settings/multi-site-override';
                $event->rules['POST cookie-consent-flow/settings/save-multi-site-override']  = 'cookie-consent-flow/settings/save-multi-site-override';
                $event->rules['POST cookie-consent-flow/settings/reset-multi-site-override'] = 'cookie-consent-flow/settings/reset-multi-site-override';
                $event->rules['POST cookie-consent-flow/settings/copy-multi-site-override']  = 'cookie-consent-flow/settings/copy-multi-site-override';
                $event->rules['POST cookie-consent-flow/settings/invalidate-consent']        = 'cookie-consent-flow/settings/invalidate-consent';
                $event->rules['cookie-consent-flow/logs/export']                             = 'cookie-consent-flow/logs/export';

            }
    );
    }

    private function _registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('cookieConsent', CookieConsentVariable::class);
            }
        );
    }

    private function _registerWidget(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function (RegisterComponentTypesEvent $event): void {
                $event->types[] = ConsentWidget::class;
            }
        );
    }

    /**
     * Registers custom permissions so settings/logs access can be granted
     * to specific non-admin users rather than everyone with control panel
     * access. Admin accounts always pass permission checks regardless.
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading'     => Craft::t('cookie-consent-flow', 'Cookie Consent Flow'),
                    'permissions' => Permissions::definition(),
                ];
            }
        );
    }

    private function _registerCpAsset(): void
    {
        $view = Craft::$app->getView();

        $view->registerAssetBundle(CpAsset::class);

        // The control-panel JavaScript re-labels badges, asks for confirmation
        // before destructive actions and reports outcomes — all user-facing
        // text that has to be translatable like the rest of the plugin.
        // Registered for every CP page rather than in one template: the
        // messages are raised from several pages (Multisite, Cookies,
        // Settings), and a dictionary that only existed on one of them would
        // leave the others falling back to English keys.
        //
        // Deferred to render time rather than translated here: this method
        // runs while the plugin is initialising, which can be before Craft has
        // settled the target language for the request, and a string translated
        // then would be translated into the wrong one.
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function () use ($view): void {
                $view->registerJs(
                    'window.cckStrings = ' . \craft\helpers\Json::encode($this->_cpStrings()) . ';',
                    View::POS_HEAD
                );
            }
        );
    }

    /**
     * Translatable strings the control-panel JavaScript needs at runtime.
     * Keyed by their English source text, which is what `cckT()` falls back to
     * — so a missing entry degrades to English rather than to a blank button.
     *
     * @return array<string, string>
     */
    private function _cpStrings(): array
    {
        $t = static fn(string $message): string => Craft::t('cookie-consent-flow', $message);

        return [
            'Inherited'              => $t('Inherited'),
            'Overridden'             => $t('Overridden'),
            'Site Override'          => $t('Site Override'),
            'Expand All Sections'    => $t('Expand All Sections'),
            'Collapse All Sections'  => $t('Collapse All Sections'),
            'Done.'                  => $t('Done.'),
            'An error occurred.'     => $t('An error occurred.'),
            'Remove every override for this site and return it to Global Settings?'
                => $t('Remove every override for this site and return it to Global Settings?'),
            'Replace this site’s overrides with a copy of the selected site’s? This cannot be undone.'
                => $t('Replace this site’s overrides with a copy of the selected site’s? This cannot be undone.'),
        ];
    }

    /**
     * Injects the banner, its assets and (optionally) the Google Consent Mode
     * snippet into every front-end HTML response, whether or not the site's
     * template uses a Twig tag to do it.
     *
     * ## Why this is written to be cache-safe
     *
     * Everything injected here can end up in a full-page cache (Blitz, a
     * reverse proxy, a CDN) and be served verbatim to every later visitor. So
     * nothing injected here may depend on *who* is asking:
     *
     * - **No CSRF token is embedded.** A baked-in token goes stale the moment
     *   the page is cached, and would then fail for everyone. The runtime
     *   fetches a token itself, at the moment it needs one.
     * - **No geo decision is baked in.** With geo-targeting on, the country is
     *   resolved per visitor by the runtime against an uncacheable endpoint
     *   (`consent/geo`), instead of the first visitor's country deciding what
     *   every later visitor sees.
     * - **No consent state is rendered.** The banner markup is always the
     *   same and always starts hidden; the visitor's own browser storage is
     *   what reveals or suppresses it.
     *
     * The result is that one visitor accepting analytics can never cause
     * another visitor to be served a page that reflects that choice.
     */
    private function _registerFrontendBanner(): void
    {
        Craft::$app->getResponse()->on(
            \yii\web\Response::EVENT_AFTER_PREPARE,
            function (): void {
                /** @var \yii\web\Response $response */
                $response = Craft::$app->getResponse();

                if (!$response->getIsOk()) {
                    return;
                }

                $content = $response->content;
                if (!is_string($content) || stripos($content, '</body>') === false) {
                    return;
                }

                try {
                    $settings = $this->cookieSettings->getEffectiveSettings();
                } catch (\Throwable $e) {
                    // A settings-table problem must not take the whole site
                    // down — the page the visitor asked for still renders,
                    // just without a banner, and the cause is logged.
                    Craft::error('Cookie Consent Flow could not load settings: ' . $e->getMessage(), __METHOD__);

                    return;
                }

                if ($settings->consentModeEnabled && $settings->consentModeAutoInject) {
                    $content = $this->_injectConsentMode($content, $settings);
                }

                if ($settings->bannerEnabled && !$this->_bannerHandled) {
                    $content = $this->_injectBanner($content, $settings);
                }

                $response->content = $content;
            }
        );
    }

    /**
     * Places the Consent Mode snippet as early in `<head>` as possible — it
     * must run before any Google tag, or the tag acts on an unset default.
     * Falls back to prepending to the document when there is no `<head>`.
     */
    private function _injectConsentMode(string $content, Settings $settings): string
    {
        $snippet = $this->consentMode->renderScript($settings);

        if ($snippet === '') {
            return $content;
        }

        if (preg_match('/<head\b[^>]*>/i', $content, $match, PREG_OFFSET_CAPTURE)) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr_replace($content, "\n" . $snippet, $at, 0);
        }

        return $snippet . "\n" . $content;
    }

    /**
     * Renders the banner and appends it, its stylesheet, its runtime config
     * and its script immediately before `</body>`.
     */
    private function _injectBanner(string $content, Settings $settings): string
    {
        // Fired here as well as in renderBanner(), because auto-injection is
        // the default path — an event documented as able to suppress the
        // banner that only fired on the path almost nobody uses would be
        // documentation of something that does not happen.
        $event = new BeforeBannerRenderEvent(['settings' => $settings]);
        $this->trigger(self::EVENT_BEFORE_BANNER_RENDER, $event);

        if ($event->cancel) {
            return $content;
        }

        $view        = Craft::$app->getView();
        $currentMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            $bannerHtml = $view->renderTemplate(
                'cookie-consent-flow/banner/_banner',
                ['settings' => $settings]
            );
        } catch (\Throwable $e) {
            Craft::error('Cookie Consent Flow banner render failed: ' . $e->getMessage(), __METHOD__);

            return $content;
        } finally {
            $view->setTemplateMode($currentMode);
        }

        $assetSrcPath = Craft::getAlias('@sfsinfotech/craftcookieconsentflow') . '/web/assets/banner';
        [, $baseUrl]  = Craft::$app->getAssetManager()->publish($assetSrcPath);

        $inject  = "\n" . '<link rel="stylesheet" href="' . $baseUrl . '/cookie-banner.css">';
        $inject .= "\n" . $bannerHtml;
        $inject .= "\n" . '<script>window.cckConfig = '
            . \craft\helpers\Json::encode($this->buildRuntimeConfig($settings)) . ';</script>';
        $inject .= "\n" . '<script src="' . $baseUrl . '/cookie-banner.js" defer></script>';
        $inject .= "\n" . '</body>';

        $pos = strrpos($content, '</body>');

        return $pos !== false ? substr_replace($content, $inject, $pos, strlen('</body>')) : $content;
    }

    /**
     * The configuration object handed to the front-end runtime.
     *
     * Shared by the auto-injection above and the `renderBanner()` Twig call,
     * so the two paths can never drift apart. **Every value here is
     * site-wide, never visitor-specific** — that is the property that makes
     * the surrounding HTML safe to cache and serve to anyone. Anything that
     * varies per visitor (a CSRF token, a resolved country, a stored
     * decision) is fetched or read by the runtime at execution time instead.
     *
     * @return array<string, mixed>
     */
    public function buildRuntimeConfig(Settings $settings): array
    {
        return [
            'saveUrl'           => \craft\helpers\UrlHelper::actionUrl('cookie-consent-flow/consent/save'),
            'reportCookiesUrl'  => \craft\helpers\UrlHelper::actionUrl('cookie-consent-flow/cookie-detection/report'),
            'geoUrl'            => \craft\helpers\UrlHelper::actionUrl('cookie-consent-flow/consent/geo'),
            // Craft's own anonymous session endpoint. The runtime reads a
            // fresh CSRF token from here rather than one baked into possibly
            // cached HTML.
            'csrfUrl'           => \craft\helpers\UrlHelper::actionUrl('users/session-info'),
            'csrfTokenName'     => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'allCategories'     => $settings->getCategoryKeys(),
            'lockedCategories'  => $settings->getLockedCategoryKeys(),
            'defaultCategories' => $settings->getDefaultCategoryKeys(),
            'siteId'            => Craft::$app->getSites()->getCurrentSite()->id,
            'consentExpiryDays' => $settings->consentExpiryDays,
            'policyVersion'     => $settings->policyVersion,
            'geoEnabled'        => $settings->geoEnabled && !empty($settings->geoTargetCountries),
            'respectGpc'        => $settings->respectGpc,
            'respectDnt'        => $settings->respectDnt,
            'consentMode'       => [
                'enabled' => $settings->consentModeEnabled,
                'type'    => $settings->consentModeType,
                'signals' => $settings->getCategoryGcmSignals(),
            ],
        ];
    }

    /** Marks manual Twig rendering as authoritative for the current request. */
    public function markBannerHandled(): void
    {
        $this->_bannerHandled = true;
    }
}
