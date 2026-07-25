<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\web\Response;

/**
 * Dashboard Controller — renders the plugin's CP dashboard page.
 */
class DashboardController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $plugin   = Plugin::getInstance();
        $settings = $plugin->getSettings();

        // Publish only the banner's CSS (not its JS) so the live preview
        // below renders with the admin's real styling, without wiring up
        // the consent buttons — clicking Accept/Reject inside the CP must
        // never submit a real consent record.
        $assetSrcPath = Craft::getAlias('@sfsinfotech/craftcookieconsentflow') . '/web/assets/banner';
        [, $baseUrl]  = Craft::$app->getAssetManager()->publish($assetSrcPath);
        Craft::$app->getView()->registerCssFile($baseUrl . '/cookie-banner.css');

        return $this->renderTemplate('cookie-consent-flow/dashboard/index', [
            'plugin'   => $plugin,
            'settings' => $settings,
        ]);
    }
}
