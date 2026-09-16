<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\helpers\CookieLibrary;
use sfsinfotech\craftcookieconsentflow\helpers\Permissions;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\web\Response;

/**
 * Cookies Controller — renders and persists the GLOBAL per-cookie disclosure
 * list (name/provider/purpose/duration grouped by category). Site-specific
 * overrides are a "Cookies" card on the Multisite page instead of living
 * here — see SettingsController::actionSaveMultiSiteOverride() /
 * SettingsService::saveSiteOverrides(), which is where a site's own list
 * actually gets saved.
 */
class CookiesController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Same permission as the consolidated Settings form: disclosures are
        // rendered to every visitor and are part of the site's configuration.
        Permissions::requireAny(Permissions::MANAGE_SETTINGS);

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        return $this->renderTemplate('cookie-consent-flow/cookies/index', $this->_templateVars());
    }

    public function actionSave(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $raw    = Craft::$app->getRequest()->getBodyParam('cookies', []);

        if (!$plugin->cookieDefinitions->saveAll($plugin->cookieSettings->getGlobalSettingsId(), $raw)) {
            Craft::$app->getSession()->setError(Craft::t('cookie-consent-flow', "Couldn't save cookies."));

            return $this->renderTemplate('cookie-consent-flow/cookies/index', $this->_templateVars());
        }

        Craft::$app->getSession()->setNotice(Craft::t('cookie-consent-flow', 'Cookies saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Marks every detected row for a cookie name as dismissed (AJAX from the
     * "Detected, undocumented" list's "Dismiss" button) — for cookies an
     * admin has reviewed and deliberately chosen not to document (e.g. a
     * first-party technical cookie that doesn't warrant visitor-facing
     * disclosure), so it stops nagging on every page load.
     */
    public function actionDismissDetected(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $name = (string) Craft::$app->getRequest()->getRequiredBodyParam('name');

        Plugin::getInstance()->cookieDefinitions->dismissDetected($name);

        return $this->asJson(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function _templateVars(): array
    {
        $plugin = Plugin::getInstance();

        $settings   = $plugin->getSettings();
        $settingsId = $plugin->cookieSettings->getGlobalSettingsId();

        return [
            'settings'     => $settings,
            'cookies'      => $plugin->cookieDefinitions->getAll($settingsId),
            'undocumented' => $plugin->cookieDefinitions->getUndocumented(),
            // Disclosures left behind by a renamed or deleted category. They
            // are not shown to visitors, so an admin has to be told they exist
            // rather than discovering it from a gap in the preferences modal.
            'orphaned'     => $plugin->cookieDefinitions->getOrphaned($settingsId, $settings->getCategoryKeys()),
            'library'      => CookieLibrary::getEntries(),
            'plugin'       => $plugin,
        ];
    }
}
