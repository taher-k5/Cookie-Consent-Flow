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

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('cookie-consent-flow/dashboard/index', [
            'plugin'   => $plugin,
            'settings' => $plugin->getSettings(),
            'stats'    => $plugin->consent->getStats(),
        ]);
    }
}
