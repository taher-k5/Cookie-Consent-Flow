<?php

namespace sfsinfotech\craftcookieconsentkit\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentkit\Plugin;
use yii\web\Response;

/**
 * Settings Controller — renders and persists the plugin's CP settings.
 */
class SettingsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('cookie-consent-kit/settings/index', [
            'settings' => $settings,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        // TODO: read posted values, populate Settings model, validate, persist to DB.
        Craft::$app->getSession()->setNotice(Craft::t('cookie-consent-kit', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
