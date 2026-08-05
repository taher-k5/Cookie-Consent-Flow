<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Dashboard Controller — renders the plugin's CP dashboard page.
 */
class DashboardController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // The dashboard surfaces the live banner preview (built from real
        // settings) and links into Settings/Logs — gate it behind either
        // permission rather than leaving it open to any CP user, matching
        // SettingsController/LogsController's posture. Admins always pass
        // checkPermission() regardless, per Craft's own convention.
        $user = Craft::$app->getUser();
        if (
            !$user->checkPermission('cookieConsentFlow:manageSettings') &&
            !$user->checkPermission('cookieConsentFlow:viewLogs')
        ) {
            throw new ForbiddenHttpException('User is not permitted to perform this action.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $plugin = Plugin::getInstance();
        $site   = ConsentHelper::resolveSiteFromParam(Craft::$app->getRequest()->getParam('site'));

        $settings = $plugin->cookieSettings->getEffectiveSettings($site->id);

        // Publish only the banner's CSS (not its JS) so the live preview
        // below renders with the admin's real styling, without wiring up
        // the consent buttons — clicking Accept/Reject inside the CP must
        // never submit a real consent record.
        $assetSrcPath = Craft::getAlias('@sfsinfotech/craftcookieconsentflow') . '/web/assets/banner';
        [, $baseUrl]  = Craft::$app->getAssetManager()->publish($assetSrcPath);
        Craft::$app->getView()->registerCssFile($baseUrl . '/cookie-banner.css');

        return $this->renderTemplate('cookie-consent-flow/dashboard/index', [
            'plugin'      => $plugin,
            'settings'    => $settings,
            'currentSite' => $site,
            'allSites'    => Craft::$app->getSites()->getAllSites(),
        ]);
    }
}
