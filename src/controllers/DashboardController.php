<?php

namespace sfsinfotech\craftcookieconsentkit\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentkit\Plugin;
use yii\web\ForbiddenHttpException;
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

        return $this->renderTemplate('cookie-consent-kit/dashboard/index', [
            'plugin' => Plugin::getInstance(),
        ]);
    }
}
