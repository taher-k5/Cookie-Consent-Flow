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

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings   = true;
    public bool $hasCpSection     = true;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

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

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpAsset();
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

    // -------------------------------------------------------------------------
    // CP Nav
    // -------------------------------------------------------------------------

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
            'settings' => [
                'label' => Craft::t('cookie-consent-kit', 'Settings'),
                'url'   => 'cookie-consent-kit/settings',
            ],
        ];

        return $item;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules['cookie-consent-kit']                     = 'cookie-consent-kit/dashboard/index';
                $event->rules['cookie-consent-kit/settings']            = 'cookie-consent-kit/settings/index';
                $event->rules['POST cookie-consent-kit/settings/save']  = 'cookie-consent-kit/settings/save';
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
}
