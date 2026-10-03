<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\helpers\Onboarding;
use sfsinfotech\craftcookieconsentflow\helpers\Permissions;
use yii\web\Response;

/**
 * Onboarding Controller — records that the current user has finished or
 * dismissed the welcome tour (see helpers/Onboarding).
 */
class OnboardingController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // The tour is only offered on the plugin's own pages, to users who
        // can open at least one of them.
        Permissions::requireAny(Permissions::MANAGE_SETTINGS, Permissions::VIEW_LOGS);

        return true;
    }

    /**
     * POST cookie-consent-flow/onboarding/complete
     *
     * Saves only the current user's own preference; there is nothing to post.
     */
    public function actionComplete(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return $this->asFailure();
        }

        try {
            Onboarding::markSeen($user);
        } catch (\Throwable $e) {
            Craft::error('Cookie Consent Flow could not save the welcome tour preference: ' . $e->getMessage(), __METHOD__);

            return $this->asFailure();
        }

        return $this->asSuccess();
    }
}
