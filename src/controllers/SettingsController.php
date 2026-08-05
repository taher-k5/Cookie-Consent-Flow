<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\web\Response;

/**
 * Settings Controller — renders and persists the plugin's CP settings.
 */
class SettingsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Editing these settings means editing HTML rendered on every
        // front-end page for every visitor — restrict beyond "logged into
        // the control panel" to a specific, grantable permission.
        $this->requirePermission('cookieConsentFlow:manageSettings');

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('cookie-consent-flow/settings/index', [
            'settings' => $settings,
            'plugin'   => Plugin::getInstance(),
        ]);
    }

    /**
     * Render the "Multi Site Override" page: one collapsible panel per
     * Craft site, listing that site's current overrides against global
     * settings.
     */
    public function actionMultiSiteOverride(): Response
    {
        $this->requireCpRequest();

        $settings = Plugin::getInstance()->getSettings();
        $sites    = Craft::$app->getSites()->getAllSites();

        return $this->renderTemplate('cookie-consent-flow/settings/site-overrides', [
            'settings' => $settings,
            'sites'    => $sites,
            'plugin'   => Plugin::getInstance(),
        ]);
    }

    /**
     * Save per-site overrides (POST from the Multi Site Override page).
     * Body params are structured as sites[siteId][field] plus
     * sites[siteId][__useGlobal][field] for the inheritance checkboxes.
     */
    public function actionSaveMultiSiteOverride(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin  = Plugin::getInstance();
        $sitesIn = $request->getBodyParam('sites', []);

        foreach ($sitesIn as $siteId => $siteData) {
            // Guard against a stale form submission referencing a site that
            // was deleted while the page was open — silently skip rather
            // than writing overrides for a nonexistent site ID.
            if (Craft::$app->getSites()->getSiteById((int) $siteId) === null) {
                continue;
            }

            $useGlobal = $siteData['__useGlobal'] ?? [];
            unset($siteData['__useGlobal']);

            if (!$plugin->cookieSettings->saveSiteOverrides((int) $siteId, $siteData, $useGlobal)) {
                Craft::$app->getSession()->setError(
                    Craft::t('cookie-consent-flow', "Couldn't save Multi Site Override.")
                );

                return $this->renderTemplate('cookie-consent-flow/settings/site-overrides', [
                    'settings' => $plugin->getSettings(),
                    'sites'    => Craft::$app->getSites()->getAllSites(),
                    'plugin'   => $plugin,
                ]);
            }
        }

        $totalOverrides = 0;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $totalOverrides += $plugin->getSettings()->getSiteOverrideCount($site->id);
        }

        Craft::$app->getSession()->setNotice(Craft::t(
            'cookie-consent-flow',
            '✓ Multi Site Override Saved — {count, plural, =1{1 overridden setting} other{# overridden settings}}',
            ['count' => $totalOverrides]
        ));

        return $this->redirectToPostedUrl();
    }

    /**
     * Clears every override for a single site (AJAX from the Multi Site
     * Override page's "Reset Multi Site Override" button, with a JSON
     * response; falls back to a flash + redirect for a plain form
     * submission).
     */
    public function actionResetMultiSiteOverride(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin  = Plugin::getInstance();
        $siteId  = (int) $request->getRequiredBodyParam('siteId');

        if (Craft::$app->getSites()->getSiteById($siteId) === null) {
            return $request->getAcceptsJson()
                ? $this->asFailure(Craft::t('cookie-consent-flow', 'That site no longer exists.'))
                : $this->redirectToPostedUrl();
        }

        $success = $plugin->cookieSettings->resetSiteOverrides($siteId);

        if ($request->getAcceptsJson()) {
            return $success
                ? $this->asJson(['success' => true, 'message' => Craft::t('cookie-consent-flow', 'Multi Site Override reset.')])
                : $this->asFailure(Craft::t('cookie-consent-flow', "Couldn't reset Multi Site Override."));
        }

        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('cookie-consent-flow', 'Multi Site Override reset.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('cookie-consent-flow', "Couldn't reset Multi Site Override."));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Replaces one site's overrides with a full copy of another site's
     * (AJAX from the "Copy From" control; same JSON/redirect duality as
     * actionResetMultiSiteOverride()).
     */
    public function actionCopyMultiSiteOverride(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request     = Craft::$app->getRequest();
        $plugin      = Plugin::getInstance();
        $fromSiteId  = (int) $request->getRequiredBodyParam('fromSiteId');
        $toSiteId    = (int) $request->getRequiredBodyParam('toSiteId');

        $sitesService = Craft::$app->getSites();
        if ($sitesService->getSiteById($fromSiteId) === null || $sitesService->getSiteById($toSiteId) === null) {
            return $request->getAcceptsJson()
                ? $this->asFailure(Craft::t('cookie-consent-flow', 'That site no longer exists.'))
                : $this->redirectToPostedUrl();
        }

        $success = $plugin->cookieSettings->copySiteOverrides($fromSiteId, $toSiteId);

        if ($request->getAcceptsJson()) {
            return $success
                ? $this->asJson(['success' => true, 'message' => Craft::t('cookie-consent-flow', 'Multi Site Override copied.')])
                : $this->asFailure(Craft::t('cookie-consent-flow', "Couldn't copy Multi Site Override."));
        }

        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('cookie-consent-flow', 'Multi Site Override copied.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('cookie-consent-flow', "Couldn't copy Multi Site Override."));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Render the consolidated Banner settings page.
     */
    public function actionBanner(): Response
    {
        $this->requireCpRequest();

        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('cookie-consent-flow/settings/banner', [
            'settings'       => $settings,
            'plugin'         => Plugin::getInstance(),
            'countryOptions' => Settings::getCountryOptions(),
        ]);
    }

    /**
     * Save the banner settings (POST from the Banner tab).
     */
    public function actionSaveBanner(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin  = Plugin::getInstance();

        $raw = $request->getBodyParam('settings', []);

        if (!$plugin->cookieSettings->saveGlobalSettings($raw)) {
            Craft::$app->getSession()->setError(Craft::t('cookie-consent-flow', 'Couldn\'t save banner settings.'));

            return $this->renderTemplate('cookie-consent-flow/settings/banner', [
                'settings'       => $plugin->getSettings(),
                'plugin'         => $plugin,
                'countryOptions' => Settings::getCountryOptions(),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('cookie-consent-flow', 'Banner settings saved.'));

        return $this->redirectToPostedUrl();
    }

    public function actionSave(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin  = Plugin::getInstance();

        $raw = $request->getBodyParam('settings', []);

        if (!$plugin->cookieSettings->saveGlobalSettings($raw)) {
            Craft::$app->getSession()->setError(Craft::t('cookie-consent-flow', 'Couldn\'t save settings.'));

            $redirect = $request->getBodyParam('redirect');
            $template = strpos((string)$redirect, '/banner') !== false
                ? 'cookie-consent-flow/settings/banner'
                : 'cookie-consent-flow/settings/index';

            return $this->renderTemplate($template, [
                'settings'       => $plugin->getSettings(),
                'plugin'         => $plugin,
                'countryOptions' => Settings::getCountryOptions(),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('cookie-consent-flow', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}

