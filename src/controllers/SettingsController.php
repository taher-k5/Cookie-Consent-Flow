<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\helpers\Permissions;
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
        $this->requirePermission(Permissions::MANAGE_SETTINGS);

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
     *
     * The whole page saves as one unit. It is a single form covering every
     * site, so a failure partway through used to leave the earlier sites
     * written and the rest not — a half-applied configuration that the
     * reloaded page then presented as the current state. Each site's own save
     * is already transactional; this wraps the set of them in one outer
     * transaction (a savepoint per site on both supported drivers) so the
     * page either saves completely or changes nothing.
     */
    public function actionSaveMultiSiteOverride(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin  = Plugin::getInstance();
        $sitesIn = $request->getBodyParam('sites', []);

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
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
                    $transaction->rollBack();

                    return $this->_multiSiteOverrideFailure($siteId);
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }

            Craft::error('Cookie consent multisite save failed: ' . $e->getMessage(), __METHOD__);

            return $this->_multiSiteOverrideFailure();
        }

        $totalOverrides = 0;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $totalOverrides += $plugin->getSettings()->getSiteOverrideCount($site->id);
        }

        Craft::$app->getSession()->setSuccess(Craft::t(
            'cookie-consent-flow',
            'Multisite Saved — {count, plural, =1{1 overridden setting} other{# overridden settings}}',
            ['count' => $totalOverrides]
        ));

        return $this->redirectToPostedUrl();
    }

    /**
     * Appends the reason a save was refused, when the service recorded one.
     *
     * "Couldn't save settings." on its own leaves an admin guessing which of
     * sixty fields the server rejected, on a page where most values look
     * plausible; the field name and rule are what make the message actionable.
     * A failure with no recorded reason (a database error) keeps the plain
     * message, with the detail in the Craft log where it belongs.
     */
    private function _saveErrorMessage(string $message): string
    {
        $errors = Plugin::getInstance()->cookieSettings->getValidationErrors();

        return $errors === [] ? $message : $message . ' ' . implode(' ', $errors);
    }

    /**
     * Reports a failed multisite save and re-renders the page.
     *
     * The rolled-back write leaves the service's request cache describing
     * changes that no longer exist in the database, so it is dropped before
     * the page is rebuilt — otherwise the admin would be shown the values
     * that were just discarded, as though they had been saved.
     */
    private function _multiSiteOverrideFailure(int|string|null $siteId = null): Response
    {
        $plugin = Plugin::getInstance();
        $plugin->cookieSettings->clearCache();

        $site = $siteId !== null ? Craft::$app->getSites()->getSiteById((int) $siteId) : null;

        Craft::$app->getSession()->setError($this->_saveErrorMessage(
            $site !== null
                ? Craft::t('cookie-consent-flow', "Couldn't save Multisite — no site was changed. Check {site}.", ['site' => $site->name])
                : Craft::t('cookie-consent-flow', "Couldn't save Multisite — no site was changed.")
        ));

        return $this->renderTemplate('cookie-consent-flow/settings/site-overrides', [
            'settings' => $plugin->getSettings(),
            'sites'    => Craft::$app->getSites()->getAllSites(),
            'plugin'   => $plugin,
        ]);
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
                ? $this->asJson(['success' => true, 'message' => Craft::t('cookie-consent-flow', 'Multisite reset.')])
                : $this->asFailure(Craft::t('cookie-consent-flow', "Couldn't reset Multisite."));
        }

        if ($success) {
            Craft::$app->getSession()->setSuccess(Craft::t('cookie-consent-flow', 'Multisite reset.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('cookie-consent-flow', "Couldn't reset Multisite."));
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
                ? $this->asJson(['success' => true, 'message' => Craft::t('cookie-consent-flow', 'Multisite copied.')])
                : $this->asFailure(Craft::t('cookie-consent-flow', "Couldn't copy Multisite."));
        }

        if ($success) {
            Craft::$app->getSession()->setSuccess(Craft::t('cookie-consent-flow', 'Multisite copied.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('cookie-consent-flow', "Couldn't copy Multisite."));
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
            Craft::$app->getSession()->setError($this->_saveErrorMessage(
                Craft::t('cookie-consent-flow', "Couldn't save banner settings.")
            ));

            return $this->renderTemplate('cookie-consent-flow/settings/banner', [
                'settings'       => $plugin->getSettings(),
                'plugin'         => $plugin,
                'countryOptions' => Settings::getCountryOptions(),
            ]);
        }

        Craft::$app->getSession()->setSuccess(Craft::t('cookie-consent-flow', 'Banner settings saved.'));

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
            Craft::$app->getSession()->setError($this->_saveErrorMessage(
                Craft::t('cookie-consent-flow', "Couldn't save settings.")
            ));

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

        Craft::$app->getSession()->setSuccess(Craft::t('cookie-consent-flow', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Invalidates every visitor's stored consent, so the banner is shown
     * again and each visitor makes a fresh decision.
     *
     * Implemented by bumping `policyVersion`, which is the value each stored
     * decision carries and is checked against on every page load. That makes
     * invalidation a single, auditable configuration change rather than an
     * attempt to reach into browsers the site does not control — and it means
     * existing consent *records* are untouched: they remain accurate evidence
     * of what each visitor agreed to under the previous policy.
     *
     * Deliberately explicit, not automatic. Changing a colour or fixing a typo
     * in a category description must not force an entire audience to
     * re-consent; only a material change to what is being collected should,
     * and only a human can judge that.
     */
    public function actionInvalidateConsent(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $plugin  = Plugin::getInstance();
        $current = $plugin->getSettings()->policyVersion;

        // Date-stamped and suffixed, so repeated invalidations on one day
        // still differ and the value reads as a date in the CP rather than an
        // opaque counter.
        $version = (new \DateTimeImmutable())->format('Y-m-d') . '.' . substr((string) time(), -4);

        $request = Craft::$app->getRequest();

        if ($version === $current || !$plugin->cookieSettings->saveGlobalSettings(['policyVersion' => $version])) {
            $message = Craft::t('cookie-consent-flow', "Couldn't invalidate existing consent.");

            if ($request->getAcceptsJson()) {
                return $this->asFailure($message);
            }

            Craft::$app->getSession()->setError($message);

            return $this->redirectToPostedUrl();
        }

        Craft::info(
            "Cookie consent invalidated: policyVersion {$current} → {$version} by user #"
            . (Craft::$app->getUser()->getId() ?? 0),
            __METHOD__
        );

        $message = Craft::t(
            'cookie-consent-flow',
            'Existing consent invalidated — visitors will be asked again. Policy version is now {version}.',
            ['version' => $version]
        );

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message, 'policyVersion' => $version]);
        }

        Craft::$app->getSession()->setSuccess($message);

        return $this->redirectToPostedUrl();
    }
}
