<?php

namespace sfsinfotech\craftcookieconsentkit;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Dashboard;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use sfsinfotech\craftcookieconsentkit\models\Settings;
use sfsinfotech\craftcookieconsentkit\services\ConsentService;
use sfsinfotech\craftcookieconsentkit\services\GeoService;
use sfsinfotech\craftcookieconsentkit\variables\CookieConsentVariable;
use sfsinfotech\craftcookieconsentkit\widgets\ConsentWidget;
use sfsinfotech\craftcookieconsentkit\web\assets\cp\CpAsset;
use sfsinfotech\craftcookieconsentkit\web\assets\banner\BannerAsset;
use yii\base\Event;

/**
 * Cookie Consent Kit plugin for Craft CMS 5.
 *
 * @property-read ConsentService $consent
 * @property-read GeoService     $geo
 * @property-read Settings       $settings
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

        Craft::setAlias('@sfsinfotech/craftcookieconsentkit', __DIR__);

        $this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
            ? 'sfsinfotech\\craftcookieconsentkit\\console\\controllers'
            : 'sfsinfotech\\craftcookieconsentkit\\controllers';

        $this->_registerServices();
        $this->_registerTemplateRoots();
        $this->_registerCpRoutes();
        $this->_registerVariable();
        $this->_registerWidget();
        $this->_registerCpNavItem();

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

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('cookie-consent-kit/settings')
        );
    }

    // CP Nav
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $item['label'] = Craft::t('cookie-consent-kit', 'Cookie Consent');
        $item['icon']  = '@sfsinfotech/craftcookieconsentkit/icon-mask.svg';

        $item['subnav'] = [
            'dashboard' => [
                'label' => Craft::t('cookie-consent-kit', 'Dashboard'),
                'url'   => 'cookie-consent-kit',
            ],
            'banner' => [
                'label' => Craft::t('cookie-consent-kit', 'Banner'),
                'url'   => 'cookie-consent-kit/banner',
            ],
            'settings' => [
                'label' => Craft::t('cookie-consent-kit', 'Settings'),
                'url'   => 'cookie-consent-kit/settings',
            ],
        ];

        return $item;
    }

    // Private helpers
    private function _registerServices(): void
    {
        $this->setComponents([
            'consent' => ConsentService::class,
            'geo'     => GeoService::class,
        ]);
    }

    private function _registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event): void {
                $event->roots['cookie-consent-kit'] = __DIR__ . '/templates';
            }
        );

        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event): void {
                $event->roots['cookie-consent-kit'] = __DIR__ . '/templates';
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules['cookie-consent-kit']                     = 'cookie-consent-kit/dashboard/index';
                $event->rules['cookie-consent-kit/banner']                  = 'cookie-consent-kit/settings/banner';
                $event->rules['POST cookie-consent-kit/banner/save']      = 'cookie-consent-kit/settings/save-banner';
                $event->rules['cookie-consent-kit/settings']              = 'cookie-consent-kit/settings/index';
                $event->rules['POST cookie-consent-kit/settings/save']    = 'cookie-consent-kit/settings/save';
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

                $settings = $this->getSettings();

                if (!$settings->bannerEnabled) {
                    return;
                }

                if (!$this->geo->shouldShowBanner()) {
                    return;
                }

                // Render banner HTML (template already includes inline CSS vars)
                $view        = Craft::$app->getView();
                $currentMode = $view->getTemplateMode();
                try {
                    $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
                    $bannerHtml = $view->renderTemplate(
                        'cookie-consent-kit/banner/_banner',
                        ['settings' => $settings]
                    );
                } catch (\Throwable $e) {
                    Craft::error('CookieConsentKit banner render failed: ' . $e->getMessage(), __METHOD__);
                    return;
                } finally {
                    $view->setTemplateMode($currentMode);
                }

                // Publish banner asset directory and get the public base URL
                $assetSrcPath = Craft::getAlias('@sfsinfotech/craftcookieconsentkit') . '/web/assets/banner';
                [, $baseUrl]  = Craft::$app->getAssetManager()->publish($assetSrcPath);

                // Build the JS config object
                $configJson = \craft\helpers\Json::encode([
                    'saveUrl'          => \craft\helpers\UrlHelper::actionUrl('cookie-consent-kit/consent/save'),
                    'csrfTokenName'    => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
                    'csrfToken'        => Craft::$app->getRequest()->getCsrfToken(),
                    'allCategories'    => $settings->getCategoryKeys(),
                    'lockedCategories' => $settings->getLockedCategoryKeys(),
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
