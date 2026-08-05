<?php

namespace sfsinfotech\craftcookieconsentflow;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;
use sfsinfotech\craftcookieconsentflow\services\GeoService;
use sfsinfotech\craftcookieconsentflow\services\SettingsService;
use sfsinfotech\craftcookieconsentflow\variables\CookieConsentVariable;
use sfsinfotech\craftcookieconsentflow\widgets\ConsentWidget;
use sfsinfotech\craftcookieconsentflow\web\assets\cp\CpAsset;
use sfsinfotech\craftcookieconsentflow\web\assets\banner\BannerAsset;
use yii\base\Event;

/**
 * Cookie Consent Flow plugin for Craft CMS 5.
 *
 * @property-read ConsentService  $consent
 * @property-read GeoService      $geo
 * @property-read SettingsService $cookieSettings
 * @property-read Settings        $settings
 * @method  Settings getSettings()
 * @method  static Plugin getInstance()
 */
class Plugin extends BasePlugin
{
    public static Plugin $plugin;

    public string $schemaVersion = '1.1.0';
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
        $this->_registerCpNavItem();
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
            'logs' => [
                'label' => Craft::t('cookie-consent-flow', 'Consent Logs'),
                'url'   => 'cookie-consent-flow/logs',
            ],
            'settings' => [
                'label' => Craft::t('cookie-consent-flow', 'Settings'),
                'url'   => 'cookie-consent-flow/settings',
            ],
        ];

        // Only surface the Multi Site Override page on genuinely multi-site
        // installs, to keep the nav uncluttered for the common single-site case.
        if (count(Craft::$app->getSites()->getAllSites()) > 1) {
            $item['subnav']['multi-site-override'] = [
                'label' => Craft::t('cookie-consent-flow', 'Multi Site Override'),
                'url'   => 'cookie-consent-flow/settings/multi-site-override',
            ];
        }

        return $item;
    }

    // Private helpers
    private function _registerServices(): void
    {
        $this->setComponents([
            'consent'        => ConsentService::class,
            'geo'            => GeoService::class,
            'cookieSettings' => SettingsService::class,
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
                $event->rules['cookie-consent-flow/settings']              = 'cookie-consent-flow/settings/index';
                $event->rules['POST cookie-consent-flow/settings/save']    = 'cookie-consent-flow/settings/save';
                $event->rules['cookie-consent-flow/settings/multi-site-override']            = 'cookie-consent-flow/settings/multi-site-override';
                $event->rules['POST cookie-consent-flow/settings/save-multi-site-override']  = 'cookie-consent-flow/settings/save-multi-site-override';
                $event->rules['POST cookie-consent-flow/settings/reset-multi-site-override'] = 'cookie-consent-flow/settings/reset-multi-site-override';
                $event->rules['POST cookie-consent-flow/settings/copy-multi-site-override']  = 'cookie-consent-flow/settings/copy-multi-site-override';

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

    private function _registerCpNavItem(): void
    {
        // Nav item is provided via getCpNavItem() above.
        // Kept as a hook point for future dynamic registration if needed.
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
                    'heading' => Craft::t('cookie-consent-flow', 'Cookie Consent Flow'),
                    'permissions' => [
                        'cookieConsentFlow:manageSettings' => [
                            'label' => Craft::t('cookie-consent-flow', 'Manage banner & plugin settings'),
                        ],
                        'cookieConsentFlow:viewLogs' => [
                            'label' => Craft::t('cookie-consent-flow', 'View consent logs'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerCpAsset(): void
    {
        Craft::$app->getView()->registerAssetBundle(CpAsset::class);
    }

    /**
     * Auto-inject the banner + its CSS/JS into every HTML frontend response,
     * regardless of whether the template uses the {% body %} Twig tag.
     */
    private function _registerFrontendBanner(): void
    {
        Craft::$app->getResponse()->on(
            \yii\web\Response::EVENT_AFTER_PREPARE,
            function (): void {
                /** @var \yii\web\Response $response */
                $response = Craft::$app->getResponse();

                // Only act on successful HTML responses that have a </body>
                if (!$response->getIsOk()) {
                    return;
                }

                $content = $response->content;
                if (!is_string($content) || stripos($content, '</body>') === false) {
                    return;
                }

                $settings = $this->cookieSettings->getEffectiveSettings();

                if (!$settings->bannerEnabled) {
                    return;
                }

                if (!$this->geo->shouldShowBanner($settings)) {
                    return;
                }

                // Render banner HTML (template already includes inline CSS vars)
                $view        = Craft::$app->getView();
                $currentMode = $view->getTemplateMode();
                try {
                    $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
                    $bannerHtml = $view->renderTemplate(
                        'cookie-consent-flow/banner/_banner',
                        ['settings' => $settings]
                    );
                } catch (\Throwable $e) {
                    Craft::error('CookieConsentKit banner render failed: ' . $e->getMessage(), __METHOD__);
                    return;
                } finally {
                    $view->setTemplateMode($currentMode);
                }

                // Publish banner asset directory and get the public base URL
                $assetSrcPath = Craft::getAlias('@sfsinfotech/craftcookieconsentflow') . '/web/assets/banner';
                [, $baseUrl]  = Craft::$app->getAssetManager()->publish($assetSrcPath);

                // Build the JS config object. `siteId` namespaces client-side
                // consent storage (localStorage/cookies) so a decision made
                // on one Craft site is never silently treated as consent for
                // another on a shared-origin multi-site install.
                $configJson = \craft\helpers\Json::encode([
                    'saveUrl'          => \craft\helpers\UrlHelper::actionUrl('cookie-consent-flow/consent/save'),
                    'csrfTokenName'    => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
                    'csrfToken'        => Craft::$app->getRequest()->getCsrfToken(),
                    'allCategories'    => $settings->getCategoryKeys(),
                    'lockedCategories' => $settings->getLockedCategoryKeys(),
                    'siteId'           => Craft::$app->getSites()->getCurrentSite()->id,
                ]);

                // Build the snippet to inject before </body>
                $inject  = "\n" . '<link rel="stylesheet" href="' . $baseUrl . '/cookie-banner.css">';
                $inject .= "\n" . $bannerHtml;
                $inject .= "\n" . '<script>window.cckConfig = ' . $configJson . ';</script>';
                $inject .= "\n" . '<script src="' . $baseUrl . '/cookie-banner.js" defer></script>';
                $inject .= "\n" . '</body>';

                $pos = strrpos($content, '</body>');
                if ($pos !== false) {
                    $response->content = substr_replace($content, $inject, $pos, strlen('</body>'));
                }
            }
        );
    }
}
