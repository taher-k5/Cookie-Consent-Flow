<?php

namespace sfsinfotech\craftcookieconsentflow;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\DeleteSiteEvent;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\Sites;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use sfsinfotech\craftcookieconsentflow\controllers\LogsController;
use sfsinfotech\craftcookieconsentflow\events\BeforeBannerRenderEvent;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentScope;
use sfsinfotech\craftcookieconsentflow\helpers\Onboarding;
use sfsinfotech\craftcookieconsentflow\helpers\Permissions;
use sfsinfotech\craftcookieconsentflow\helpers\PluginConfig;
use sfsinfotech\craftcookieconsentflow\services\ConsentModeService;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;
use sfsinfotech\craftcookieconsentflow\services\StatisticsService;
use sfsinfotech\craftcookieconsentflow\services\UserLinkService;
use sfsinfotech\craftcookieconsentflow\services\CookieDefinitionService;
use sfsinfotech\craftcookieconsentflow\services\GeoService;
use sfsinfotech\craftcookieconsentflow\services\SettingsService;
use sfsinfotech\craftcookieconsentflow\variables\CookieConsentVariable;
use sfsinfotech\craftcookieconsentflow\widgets\ConsentWidget;
use sfsinfotech\craftcookieconsentflow\web\assets\cp\CpAsset;
use sfsinfotech\craftcookieconsentflow\web\assets\onboarding\OnboardingAsset;
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

    /** Whether this request's page already has the welcome tour registered. */
    private bool $_onboardingRegistered = false;

    /**
     * Craft compares this against the version stored at install time to decide
     * whether migrations are pending, and refuses to apply project config
     * whose recorded plugin schema differs from it.
     *
     * It must never go down. Development builds of this plugin were installed
     * at 1.6.0 (the `main` branch) and 1.7.0, and a version below what a
     * project config records makes Craft refuse `project-config/apply`. 1.0.0
     * shipped at 1.8.0.
     *
     * `Install.php` remains the whole schema. Since the first published
     * release, every schema change also bumps this and ships a timestamped
     * migration that calls `Install::reconcile()`:
     *
     * - 1.12.0 (release 1.0.5) — consent scope, consent ID and user linking
     *   (m261016_300000_consent_scope_id_users). Development builds of 1.0.5
     *   recorded 1.9.0 to 1.11.0, so it is 1.12.0 rather than 1.9.0.
     */
    public string $schemaVersion = '1.12.0';
    public bool $hasCpSettings   = true;
    public bool $hasCpSection     = true;

    // Event name constants

    /** Fired before the banner HTML is rendered. Set $event->cancel = true to suppress. */
    public const EVENT_BEFORE_BANNER_RENDER = 'beforeBannerRender';

    /** Fired after a visitor's consent has been saved. */
    public const EVENT_AFTER_CONSENT_SAVE   = 'afterConsentSave';

    /**
     * Fired when a site's consent scope is resolved. Change
     * `$event->scopeKey` to choose which sites share a decision.
     *
     * @see \sfsinfotech\craftcookieconsentflow\events\ResolveConsentScopeEvent
     */
    public const EVENT_RESOLVE_CONSENT_SCOPE = 'resolveConsentScope';

    // Bootstrap
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Craft::setAlias('@sfsinfotech/craftcookieconsentflow', __DIR__);

        $this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
            ? 'sfsinfotech\\craftcookieconsentflow\\console\\controllers'
            : 'sfsinfotech\\craftcookieconsentflow\\controllers';

        $this->_registerTemplateRoots();
        $this->_registerCpRoutes();
        $this->_registerVariable();
        $this->_registerWidget();
        $this->_registerPermissions();
        $this->_registerSiteCleanup();
        $this->_registerGarbageCollection();
        $this->_registerUserLinking();

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

    /**
     * The plugin's CP navigation, limited to what the current user can open.
     *
     * Every item used to be listed for everyone with control-panel access,
     * and the controllers' 403 was the only thing standing between a user and
     * a page they had no permission for. Each item now carries the same
     * permission its controller enforces, and a user holding none of them
     * does not see the section at all.
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $item['label'] = Craft::t('cookie-consent-flow', 'Cookie Consent');
        $item['icon']  = '@sfsinfotech/craftcookieconsentflow/icon-mask.svg';

        $subnav = self::subnavFor(
            Permissions::canAny(Permissions::MANAGE_SETTINGS),
            Permissions::canAny(Permissions::VIEW_LOGS),
            count(Craft::$app->getSites()->getAllSites()) > 1
        );

        if ($subnav === []) {
            return null;
        }

        $item['subnav'] = $subnav;

        // The section's own link goes to the first page this user can open —
        // the dashboard for anyone who can see it.
        $item['url'] = reset($subnav)['url'];

        return $item;
    }

    /**
     * The subnav a user with these permissions can use, in display order.
     * Pure, so the permission rules can be verified without a user session.
     *
     * - Dashboard: either permission (it shows configuration and statistics).
     * - Banner, Cookies, Multisite, Settings: manage configuration.
     * - Consent Records: view records.
     *
     * @return array<string, array{label: string, url: string}>
     */
    public static function subnavFor(bool $canManage, bool $canViewLogs, bool $multiSite): array
    {
        $t = static fn(string $message): string => Craft::t('cookie-consent-flow', $message);

        $items = [];

        if ($canManage || $canViewLogs) {
            $items['dashboard'] = ['label' => $t('Dashboard'), 'url' => 'cookie-consent-flow'];
        }

        if ($canManage) {
            $items['banner']  = ['label' => $t('Banner'), 'url' => 'cookie-consent-flow/banner'];
            $items['cookies'] = ['label' => $t('Cookies'), 'url' => 'cookie-consent-flow/cookies'];
        }

        if ($canViewLogs) {
            $items['logs'] = ['label' => $t('Consent Records'), 'url' => 'cookie-consent-flow/logs'];
        }

        // Only on genuinely multi-site installs, to keep the nav uncluttered
        // for the common single-site case, and just to the left of Settings.
        if ($canManage && $multiSite) {
            $items['multi-site-override'] = [
                'label' => $t('Multisite'),
                'url'   => 'cookie-consent-flow/settings/multi-site-override',
            ];
        }

        if ($canManage) {
            $items['settings'] = ['label' => $t('Settings'), 'url' => 'cookie-consent-flow/settings'];
        }

        return $items;
    }

    // Private helpers
    /**
     * The plugin's components, declared where Craft merges configuration.
     *
     * Craft builds the plugin from this array merged with the project's
     * `pluginConfigs['cookie-consent-flow']`, so a site can reconfigure a
     * component — most usefully the geo providers:
     *
     * ```php
     * // config/app.php
     * 'components' => ['plugins' => ['pluginConfigs' => ['cookie-consent-flow' => [
     *     'components' => ['geo' => ['providers' => [MyGeoProvider::class]]],
     * ]]]],
     * ```
     *
     * They used to be registered with setComponents() in init(), which runs
     * after that merge and replaced every override with the defaults — so
     * the documented way to add a geo provider silently did nothing.
     */
    public static function config(): array
    {
        return [
            'components' => [
                'consent'           => ['class' => ConsentService::class],
                'consentMode'       => ['class' => ConsentModeService::class],
                'geo'               => ['class' => GeoService::class],
                'cookieSettings'    => ['class' => SettingsService::class],
                'cookieDefinitions' => ['class' => CookieDefinitionService::class],
                'statistics'        => ['class' => StatisticsService::class],
                'userLinks'         => ['class' => UserLinkService::class],
            ],
        ];
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
                $event->rules['POST cookie-consent-flow/logs/delete-visitor']                = 'cookie-consent-flow/logs/delete-visitor';
                $event->rules['POST cookie-consent-flow/logs/delete-user']                   = 'cookie-consent-flow/logs/delete-user';

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

    /**
     * Removes a deleted site's configuration and detected-cookie inventory.
     *
     * Settings rows, their categories and cookie disclosures (by cascade), and
     * detected cookie names all belong to one site and mean nothing once it
     * is gone; left behind they were orphans nobody could see or remove.
     *
     * Consent **records** for the site are deliberately kept. They are
     * evidence of what visitors agreed to while the site existed, which is
     * exactly when that evidence may still be asked for; they remain visible
     * under Consent Records (labelled as a deleted site), exportable, and are
     * removed by retention like any other record.
     */
    private function _registerSiteCleanup(): void
    {
        Event::on(
            Sites::class,
            Sites::EVENT_AFTER_DELETE_SITE,
            function (DeleteSiteEvent $event): void {
                $siteId = (int) $event->site->id;

                try {
                    $this->cookieSettings->deleteSiteData($siteId);
                } catch (\Throwable $e) {
                    Craft::error(
                        "Cookie Consent Flow could not remove data for deleted site #{$siteId}: " . $e->getMessage(),
                        __METHOD__
                    );
                }
            }
        );
    }

    /**
     * Linking consent records to logged-in users (off unless the site turns
     * on "Link consent records to logged-in users"; see UserLinkService).
     *
     * - **Login and registration:** the browser's visitor IDs are linked to
     *   the user, and the decision in effect is carried into their account,
     *   which is what makes a decision made before logging in — or before
     *   registering — theirs. Not when an admin logs in as the user, or
     *   creates one in the control panel.
     * - **User deleted** (moving them to the trash included — Craft's own
     *   trash clean-up removes users without any event a plugin could act on,
     *   so waiting for it would leave the person on their records for weeks):
     *   their visitor-ID links are removed, and their records are either
     *   kept without them ("anonymise") or deleted ("delete"). What to do is
     *   decided before the user is deleted and done after, so a deletion that
     *   does not happen changes nothing. The foreign keys repeat the
     *   anonymising for any user removed some other way.
     * - **User page:** a "Consent records" line, for those who may view them.
     *
     * Every handler fails open: none can stop a login, a deletion or a page.
     */
    private function _registerUserLinking(): void
    {
        Event::on(
            \yii\web\User::class,
            \yii\web\User::EVENT_AFTER_LOGIN,
            function (\yii\web\UserEvent $event): void {
                try {
                    $session = Craft::$app->getUser();

                    if (!$this->userLinks->isEnabled() || $event->identity === null || UserLinkService::isImpersonating($session)) {
                        return;
                    }

                    $this->userLinks->linkRequestVisitorIds((int) $event->identity->getId());
                } catch (\Throwable $e) {
                    Craft::error('Cookie Consent Flow could not link visitor IDs at login: ' . $e->getMessage(), __METHOD__);
                }
            }
        );

        // Registration: a guest creating their own account on the front end
        // links the browser just as logging in does — the account was made
        // from that browser, and Craft does not log a newly registered user in
        // by default (autoLoginAfterAccountActivation is off). An admin
        // creating a user in the control panel, or a console command, links
        // nothing.
        Event::on(
            \craft\elements\User::class,
            \craft\base\Element::EVENT_AFTER_SAVE,
            function (\craft\events\ModelEvent $event): void {
                try {
                    $request = Craft::$app->getRequest();

                    if (!$event->isNew || !$request instanceof \craft\web\Request || $request->getIsCpRequest()
                        || !Craft::$app->getUser()->getIsGuest() || !$this->userLinks->isEnabled()) {
                        return;
                    }

                    /** @var \craft\elements\User $user */
                    $user = $event->sender;

                    if ($user->id && !$user->getIsDraft() && !$user->getIsRevision()) {
                        $this->userLinks->linkRequestVisitorIds((int) $user->id);
                    }
                } catch (\Throwable $e) {
                    Craft::error('Cookie Consent Flow could not link visitor IDs at registration: ' . $e->getMessage(), __METHOD__);
                }
            }
        );

        $pendingDeletes = [];

        Event::on(
            \craft\services\Elements::class,
            \craft\services\Elements::EVENT_BEFORE_DELETE_ELEMENT,
            function (\craft\events\DeleteElementEvent $event) use (&$pendingDeletes): void {
                if (!$event->element instanceof \craft\elements\User || !$event->element->id) {
                    return;
                }

                try {
                    // The record IDs to delete, or null when the site keeps
                    // (anonymises) them instead.
                    $pendingDeletes[(int) $event->element->id] = $this->userLinks->recordIdsToDeleteWithUser((int) $event->element->id);
                } catch (\Throwable $e) {
                    Craft::error('Cookie Consent Flow could not prepare the deletion of a user\'s consent records: ' . $e->getMessage(), __METHOD__);
                }
            }
        );

        Event::on(
            \craft\services\Elements::class,
            \craft\services\Elements::EVENT_AFTER_DELETE_ELEMENT,
            function (\craft\events\ElementEvent $event) use (&$pendingDeletes): void {
                if (!$event->element instanceof \craft\elements\User) {
                    return;
                }

                $userId = (int) $event->element->id;

                if (!array_key_exists($userId, $pendingDeletes)) {
                    return;
                }

                $ids = $pendingDeletes[$userId];
                unset($pendingDeletes[$userId]);

                try {
                    if ($ids === null) {
                        // Anonymise: the records stay, as proof of consent,
                        // without the person — now, not when Craft's trash is
                        // emptied weeks later. dateUpdated is left alone: it
                        // is what marks a record attached at login.
                        $anonymised = (int) \sfsinfotech\craftcookieconsentflow\records\ConsentLogRecord::updateAll(['userId' => null], ['userId' => $userId]);
                        \sfsinfotech\craftcookieconsentflow\records\UserVisitorRecord::deleteAll(['userId' => $userId]);

                        Craft::info("Cookie consent records anonymised with user #{$userId}: {$anonymised} record(s).", __METHOD__);

                        return;
                    }

                    $deleted = $ids === [] ? 0 : (int) \sfsinfotech\craftcookieconsentflow\records\ConsentLogRecord::deleteAll(['id' => $ids]);
                    \sfsinfotech\craftcookieconsentflow\records\UserVisitorRecord::deleteAll(['userId' => $userId]);

                    if ($deleted > 0) {
                        $this->statistics->invalidate();
                    }

                    Craft::warning("Cookie consent records deleted with user #{$userId}: {$deleted} record(s).", __METHOD__);
                } catch (\Throwable $e) {
                    Craft::error('Cookie Consent Flow could not delete a deleted user\'s consent records: ' . $e->getMessage(), __METHOD__);
                }
            }
        );

        Event::on(
            \craft\elements\User::class,
            \craft\base\Element::EVENT_DEFINE_METADATA,
            function (\craft\events\DefineMetadataEvent $event): void {
                try {
                    /** @var \craft\elements\User $user */
                    $user = $event->sender;

                    if (!$user->id || !Craft::$app->getRequest()->getIsCpRequest()
                        || !Permissions::canAny(Permissions::VIEW_LOGS) || !LogsController::canViewUsers()) {
                        return;
                    }

                    $count = (int) $this->consent->buildQuery(null, ['userId' => (int) $user->id] + LogsController::scopeFiltersForCurrentUser())->count();

                    $event->metadata[Craft::t('cookie-consent-flow', 'Consent records')] = \craft\helpers\Html::a(
                        Craft::t('cookie-consent-flow', '{count, plural, =0{None} =1{# record} other{# records}}', ['count' => $count]),
                        \craft\helpers\UrlHelper::cpUrl('cookie-consent-flow/logs', ['site' => 'all', 'user' => (string) $user->id])
                    );
                } catch (\Throwable $e) {
                    Craft::warning('Cookie Consent Flow could not count a user\'s consent records: ' . $e->getMessage(), __METHOD__);
                }
            }
        );
    }

    /**
     * Purges consent records past retention during Craft's garbage
     * collection, when `automaticRetention` is enabled in
     * `config/cookie-consent-flow.php`.
     *
     * Opt-in because it deletes evidence: an install that has only ever run
     * the retention command by hand keeps exactly that behaviour until
     * someone decides otherwise. When enabled it runs wherever Craft's GC
     * runs — `php craft gc`, and probabilistically on web requests — in
     * batches, so it never holds one long lock on a large table.
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function (): void {
                if (!PluginConfig::automaticRetention()) {
                    return;
                }

                try {
                    $deleted = $this->consent->purgeOldLogs();

                    if ($deleted > 0) {
                        Craft::info("Cookie Consent Flow retention removed {$deleted} consent record(s).", __METHOD__);
                    }
                } catch (\Throwable $e) {
                    Craft::error('Cookie Consent Flow automatic retention failed: ' . $e->getMessage(), __METHOD__);
                }
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
                    'window.cckStrings = ' . ConsentHelper::jsonForHtml($this->_cpStrings()) . ';',
                    View::POS_HEAD
                );

                $this->_registerOnboarding($view);
            }
        );
    }

    /**
     * Offers the welcome tour on the plugin's own pages (see
     * helpers/Onboarding). The script and its steps are registered for every
     * user who can open a plugin page, so Settings can replay the tour; it
     * starts on its own only for a user who has not finished or dismissed it.
     */
    private function _registerOnboarding(View $view): void
    {
        if ($this->_onboardingRegistered) {
            return;
        }

        $request = Craft::$app->getRequest();
        $user    = Craft::$app->getUser()->getIdentity();

        if ($user === null || $request->getIsActionRequest() || !Onboarding::isPluginPage($request->getPathInfo())) {
            return;
        }

        $subnav = self::subnavFor(
            Permissions::canAny(Permissions::MANAGE_SETTINGS),
            Permissions::canAny(Permissions::VIEW_LOGS),
            count(Craft::$app->getSites()->getAllSites()) > 1
        );

        if ($subnav === []) {
            return;
        }

        $this->_onboardingRegistered = true;

        try {
            $seen = Onboarding::hasSeen($user);
        } catch (\Throwable $e) {
            // An unreadable preference must not pester the user on every page.
            Craft::warning('Cookie Consent Flow could not read the welcome tour preference: ' . $e->getMessage(), __METHOD__);
            $seen = true;
        }

        $view->registerAssetBundle(OnboardingAsset::class);
        $view->registerJs(
            'window.cckTour = ' . ConsentHelper::jsonForHtml(Onboarding::config($seen, $subnav)) . ';',
            View::POS_HEAD
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

                if (!self::isInjectableResponse($response)) {
                    return;
                }

                $content = $response->content;

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
     * Whether a prepared response is an HTML page the banner may be spliced
     * into.
     *
     * The hook used to check only for a successful status and a `</body>`
     * string, so a JSON response (Element API, GraphQL, a controller's
     * `asJson()`), an RSS or XML template, or any other text response that
     * happened to contain rich-text HTML had the banner, its stylesheet and
     * scripts written into the middle of it — corrupting the payload for
     * every consumer. Now every one of these must hold:
     *
     * - the status is 200 (redirects, errors and 204s carry no page);
     * - the body is a non-empty string, not a stream or file download;
     * - the `Content-Type` is HTML (`text/html` or `application/xhtml+xml`),
     *   or, when no type has been set, the response format is HTML;
     * - the document has a closing body tag to insert before.
     */
    public static function isInjectableResponse(\yii\web\Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if ($response->stream !== null) {
            return false;
        }

        $content = $response->content;

        if (!is_string($content) || $content === '') {
            return false;
        }

        $contentType = strtolower(trim(explode(';', (string) $response->getHeaders()->get('content-type', ''))[0]));

        if ($contentType !== '') {
            if (!in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
                return false;
            }
        } elseif (!in_array($response->format, [\yii\web\Response::FORMAT_HTML, 'template'], true)) {
            return false;
        }

        return self::findBodyClose($content) !== null;
    }

    /**
     * Locates the document's **last** closing body tag, as `[offset, length]`,
     * or null when there is none.
     *
     * Matched case-insensitively and tolerant of whitespace before the `>`,
     * because HTML is: `</body>`, `</BODY>`, `</Body>` and `</body >` are all
     * the same tag. The guard used to test case-insensitively while the
     * insertion used a case-sensitive `strrpos()`, so a document written with
     * `</BODY>` passed the guard, received the Consent Mode snippet, and then
     * silently got no banner at all.
     *
     * The *last* match is used rather than the first, matching the previous
     * `strrpos()` behaviour: a page whose content mentions the string earlier
     * (an escaped example in an article, say) must still have the banner
     * appended at the real end of the document.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function findBodyClose(string $content): ?array
    {
        if (!preg_match_all('/<\/body\s*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        /** @var array{0: string, 1: int} $last */
        $last = end($matches[0]);

        return [$last[1], strlen($last[0])];
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

        return self::insertIntoHead($content, $snippet);
    }

    /**
     * Inserts markup as early in the document as is valid: right after
     * `<head …>`, else after `<html …>`, else after the doctype. It used to be
     * prepended to the whole document when there was no `<head>` tag (which
     * HTML5 allows), putting a script before `<!DOCTYPE html>` — which sends
     * the browser into quirks mode for the entire page.
     */
    public static function insertIntoHead(string $content, string $snippet): string
    {
        foreach (['/<head\b[^>]*>/i', '/<html\b[^>]*>/i', '/<!doctype\b[^>]*>/i'] as $pattern) {
            if (preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
                $at = $match[0][1] + strlen($match[0][0]);

                return substr_replace($content, "\n" . $snippet, $at, 0);
            }
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

        // Re-located here rather than reused from the guard: the Consent Mode
        // snippet may already have been spliced into <head> above, which moves
        // every offset after it.
        $body = self::findBodyClose($content);

        if ($body === null) {
            return $content;
        }

        // A page that already includes the runtime — the preferences and
        // reset helpers used to register its asset bundle, and a site's own
        // template or layout still might — must not get a second copy: two
        // copies meant two sets of click handlers, so every decision was
        // committed and recorded twice. Read from the rendered document
        // rather than from the view, whose bundle list has been cleared by
        // the time the response is prepared, and which would not know about
        // a tag written into a layout by hand.
        $withScript     = !self::runtimeAlreadyIncluded($content);
        $withStylesheet = !self::stylesheetAlreadyIncluded($content);

        $baseUrl = '';
        if ($withScript || $withStylesheet) {
            $assetSrcPath = Craft::getAlias('@sfsinfotech/craftcookieconsentflow') . '/web/assets/banner';
            [, $baseUrl]  = Craft::$app->getAssetManager()->publish($assetSrcPath);
        }

        [$pos, $length] = $body;

        $inject = self::renderInjection(
            $bannerHtml,
            $this->buildRuntimeConfig($settings),
            $withStylesheet ? $baseUrl . '/cookie-banner.css' : null,
            $withScript ? $baseUrl . '/cookie-banner.js' : null
        )
            // The document's own closing tag is put back verbatim, so a page
            // that wrote `</BODY>` still reads as it did before the banner
            // arrived.
            . "\n" . substr($content, $pos, $length);

        return substr_replace($content, $inject, $pos, $length);
    }

    /**
     * The markup spliced in before `</body>`: stylesheet, banner, runtime
     * configuration and runtime script. The stylesheet and script are each
     * omitted when their URL is null — i.e. when the page already includes
     * them.
     *
     * The configuration is an inert `type="application/json"` block rather
     * than an inline script assigning a global: it is never executed, so it
     * needs no CSP nonce, and it is encoded with {@see ConsentHelper::jsonForHtml()}
     * so no configured value can end the element or switch the parser into
     * the double-escaped script state.
     *
     * @param array<string, mixed> $config
     */
    public static function renderInjection(string $bannerHtml, array $config, ?string $stylesheetUrl, ?string $scriptUrl): string
    {
        $inject = '';

        if ($stylesheetUrl !== null) {
            $inject .= "\n" . '<link rel="stylesheet" href="' . htmlspecialchars($stylesheetUrl, ENT_QUOTES) . '" data-cck-runtime>';
        }

        $inject .= "\n" . $bannerHtml;
        $inject .= "\n" . self::renderConfigBlock($config);

        if ($scriptUrl !== null) {
            $inject .= "\n" . '<script src="' . htmlspecialchars($scriptUrl, ENT_QUOTES) . '" defer data-cck-runtime></script>';
        }

        return $inject;
    }

    /**
     * Whether a rendered document already loads the consent runtime script.
     */
    public static function runtimeAlreadyIncluded(string $content): bool
    {
        // Recognised by the marker the plugin puts on its own tags (the asset
        // bundle and auto-injection both set it), not by file name: any theme
        // script that happened to be called cookie-banner.js used to count as
        // the runtime, so the real one was never added and the banner never
        // appeared — leaving visitors no way to consent.
        return (bool) preg_match('#<script\b[^>]*\bdata-cck-runtime\b#i', $content);
    }

    /** Whether a rendered document already links the banner stylesheet. */
    public static function stylesheetAlreadyIncluded(string $content): bool
    {
        return (bool) preg_match('#<link\b[^>]*\bdata-cck-runtime\b#i', $content);
    }

    /**
     * The runtime configuration as the inert data block the runtime reads.
     *
     * @param array<string, mixed> $config
     */
    public static function renderConfigBlock(array $config): string
    {
        return '<script type="application/json" id="cck-config">' . ConsentHelper::jsonForHtml($config) . '</script>';
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
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;
        $scope  = ConsentScope::forSite($siteId);

        return [
            // On this site's own base URL, so each request is handled as this
            // site: see siteActionUrl().
            'saveUrl'           => self::siteActionUrl('cookie-consent-flow/consent/save', $siteId),
            'reportCookiesUrl'  => self::siteActionUrl('cookie-consent-flow/cookie-detection/report', $siteId),
            'geoUrl'            => self::siteActionUrl('cookie-consent-flow/consent/geo', $siteId),
            'consentIdUrl'      => self::siteActionUrl('cookie-consent-flow/consent/id', $siteId),
            // Craft's own anonymous session endpoint. The runtime reads a
            // fresh CSRF token from here rather than one baked into possibly
            // cached HTML.
            'csrfUrl'           => self::runtimeCsrfUrl(Craft::$app->getConfig()->getGeneral()->enableCsrfProtection),
            'csrfTokenName'     => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'allCategories'     => $settings->getCategoryKeys(),
            'lockedCategories'  => $settings->getLockedCategoryKeys(),
            'defaultCategories' => $settings->getDefaultCategoryKeys(),
            'siteId'            => $siteId,
            // Names the browser storage that holds the decision; sites with
            // the same key share one (see ConsentScope). The same for every
            // visitor of the site, so as cacheable as the rest.
            'scopeKey'          => $scope->key,
            'scopeShared'       => $scope->isShared(),
            // Where the other Consent Scope setting stored this site's
            // decision; the runtime removes what it finds there.
            'staleScopeKeys'    => $scope->staleKeys,
            'consentExpiryDays' => $settings->consentExpiryDays,
            'policyVersion'     => $settings->policyVersion,
            'geoEnabled'        => $settings->geoEnabled && !empty($settings->geoTargetCountries),
            'respectGpc'        => $settings->respectGpc,
            'respectDnt'        => $settings->respectDnt,
            // Whether the preference centre shows the visitor their consent
            // ID. The ID itself is never here: the runtime makes or fetches
            // it on the visitor's own device.
            'showConsentId'     => $settings->showConsentId && $settings->logEnabled,
            // Applied by the runtime through the CSSOM; see Settings::getCssVarMap().
            'cssVars'           => $settings->getCssVarMap(),
            'consentMode'       => [
                'enabled' => $settings->consentModeEnabled,
                'type'    => $settings->consentModeType,
                'signals' => $settings->getCategoryGcmSignals(),
            ],
        ];
    }

    /**
     * An action URL that Craft handles as the given site.
     *
     * Craft decides which site a request belongs to from its URL. On a
     * multisite install whose sites differ by path — `example.com/` and
     * `example.com/uk/` — a request to `/actions/…` was handled as the
     * primary site, whichever site the visitor was on: a decision made on
     * the UK site was checked against the primary site's categories,
     * recorded against the primary site, and its geo answer and cookie
     * report used the primary site's settings.
     *
     * The URL is Craft's own `actionUrl()` — the host and scheme the page was
     * served on, the install's subfolder, and the script-name format, exactly
     * as before — with the site's path put in front of the action trigger
     * (`/uk/actions/…`, or `?p=uk/actions/…`). Craft strips that path again
     * before looking for the action. A site at the root of its host gets
     * `actionUrl()` unchanged.
     */
    public static function siteActionUrl(string $route, int $siteId): string
    {
        $actionUrl = \craft\helpers\UrlHelper::actionUrl($route);

        try {
            $general = Craft::$app->getConfig()->getGeneral();
            $site    = Craft::$app->getSites()->getSiteById($siteId);

            if ($general->headlessMode || $site === null || !$site->hasUrls) {
                return $actionUrl;
            }

            $sitePath = self::siteBasePath($site->getBaseUrl(), Craft::$app->getRequest()->getBaseUrl());

            return self::insertSitePath($actionUrl, $general->actionTrigger . '/' . trim($route, '/'), $sitePath);
        } catch (\Throwable $e) {
            Craft::warning('Cookie Consent Flow could not build a site action URL: ' . $e->getMessage(), __METHOD__);

            return $actionUrl;
        }
    }

    /**
     * A site's base path below the install's own (`/sub/uk/` under `/sub` is
     * `uk`), or '' for a site at the root — or one whose base URL has no
     * path, or does not sit under the install.
     */
    public static function siteBasePath(?string $siteBaseUrl, ?string $requestBaseUrl): string
    {
        $path = trim((string) parse_url((string) $siteBaseUrl, PHP_URL_PATH), '/');
        $base = trim((string) parse_url((string) $requestBaseUrl, PHP_URL_PATH), '/');

        if ($path === '') {
            return '';
        }

        if ($base !== '') {
            if ($path === $base) {
                return '';
            }

            if (!str_starts_with($path . '/', $base . '/')) {
                return '';
            }

            $path = substr($path, strlen($base) + 1);
        }

        // Only plain path segments, so nothing about the URL can be changed
        // beyond where the request is routed.
        return preg_match('#^[A-Za-z0-9._~\-]+(/[A-Za-z0-9._~\-]+)*$#D', $path) ? $path : '';
    }

    /**
     * Puts the site path in front of the action in a URL from `actionUrl()`,
     * whichever form it has (`…/actions/x` or `…?p=actions/x`). Returns the
     * URL unchanged when there is no path, or the action cannot be found.
     */
    public static function insertSitePath(string $actionUrl, string $action, string $sitePath): string
    {
        if ($sitePath === '') {
            return $actionUrl;
        }

        $pos = strpos($actionUrl, $action);

        if ($pos === false) {
            return $actionUrl;
        }

        return substr($actionUrl, 0, $pos) . $sitePath . '/' . substr($actionUrl, $pos);
    }

    /**
     * Where the runtime fetches a CSRF token, or null when Craft enforces
     * none.
     *
     * With `enableCsrfProtection` off, Craft's session endpoint returns no
     * token and every request's CSRF check passes (Craft sets the request's
     * `enableCsrfValidation` from that setting, and the consent endpoints
     * validate through it). The runtime treated the missing token as an
     * outage, so on such an install no consent was ever recorded — while
     * refusing to send added no protection, since the server accepts the
     * request either way. The server stays the enforcement point: whenever
     * Craft validates CSRF, the runtime is told to fetch a token.
     */
    public static function runtimeCsrfUrl(bool $csrfProtectionEnabled): ?string
    {
        return $csrfProtectionEnabled ? \craft\helpers\UrlHelper::actionUrl('users/session-info') : null;
    }

    /** Marks manual Twig rendering as authoritative for the current request. */
    public function markBannerHandled(): void
    {
        $this->_bannerHandled = true;
    }
}
