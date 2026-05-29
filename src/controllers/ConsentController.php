<?php

namespace sfsinfotech\craftcookieconsentkit\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentkit\Plugin;
use yii\web\Response;

/**
 * Consent Controller — front-end AJAX endpoint for recording consent choices.
 */
class ConsentController extends Controller
{
    protected array|int|bool $allowAnonymous = ['save'];

    /**
     * POST /actions/cookie-consent-kit/consent/save
     *
     * Accepts a JSON body with the visitor's consent selections and persists them.
     *
     * TODO: Implement body parsing and delegation to ConsentService::saveConsent().
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // TODO: validate CSRF token, parse categories from request body,
        //       delegate to Plugin::getInstance()->consent->saveConsent()

        return $this->asJson(['success' => true]);
    }

    /**
     * GET /actions/cookie-consent-kit/consent/status
     *
     * Returns the current visitor's consent state as JSON.
     *
     * TODO: Implement delegation to ConsentService::getConsent().
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        // TODO: return current consent record for this visitor
        return $this->asJson(['consent' => null]);
    }
}
