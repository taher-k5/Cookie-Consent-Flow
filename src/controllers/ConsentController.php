<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Consent Controller — front-end AJAX endpoint for recording consent choices.
 */
class ConsentController extends Controller
{
    protected array|int|bool $allowAnonymous = ['save', 'status'];

    /**
    * POST /actions/cookie-consent-flow/consent/save
     *
     * Expected JSON body:
     *   { "action": "accept_all|reject_all|custom", "categories": ["necessary", ...] }
     *
     * Returns JSON:
     *   { "success": true, "visitorUuid": "..." }
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();

        // Validate CSRF token (sent via X-CSRF-Token or request body)
        if (!$request->validateCsrfToken()) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $body       = $request->getBodyParams();
        $action     = $body['action'] ?? '';
        $categories = $body['categories'] ?? [];

        // Sanitise action
        $validActions = ['accept_all', 'reject_all', 'custom'];
        if (!in_array($action, $validActions, true)) {
            return $this->asJson(['success' => false, 'error' => 'Invalid action.'])->setStatusCode(400);
        }

        // Ensure categories is a flat array of strings
        $categories = array_values(array_filter(
            array_map('strval', (array) $categories),
            fn(string $k) => preg_match('/^[a-z0-9_\-]{1,64}$/', $k)
        ));

        $result = Plugin::getInstance()->consent->saveConsent($action, $categories);

        // Set first-party visitor UUID cookie (1 year), namespaced by site
        // so the same origin's other Craft sites (shared-domain multi-site
        // installs) never inherit this visitor identity or its consent.
        $response = $this->asJson(['success' => true, 'visitorUuid' => $result['visitorUuid']]);
        $response->getCookies()->add(new \yii\web\Cookie([
            'name'     => ConsentHelper::visitorCookieName($result['siteId']),
            'value'    => $result['visitorUuid'],
            'expire'   => time() + 365 * 24 * 3600,
            'httpOnly' => true,
            'secure'   => Craft::$app->getRequest()->getIsSecureConnection(),
            'sameSite' => \yii\web\Cookie::SAME_SITE_LAX,
        ]));

        return $response;
    }

    /**
    * GET /actions/cookie-consent-flow/consent/status
     *
     * Looks up the most recent consent record for the visitor UUID cookie.
     * Returns null if no record exists.
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $siteId  = Craft::$app->getSites()->getCurrentSite()->id;

        $visitorUuid = $request->getCookies()->getValue(ConsentHelper::visitorCookieName($siteId))
            ?? $request->getCookies()->getValue(ConsentHelper::LEGACY_VISITOR_COOKIE);

        if (!$visitorUuid) {
            return $this->asJson(['consent' => null]);
        }

        $consent = Plugin::getInstance()->consent->getConsent($visitorUuid, $siteId);

        return $this->asJson(['consent' => $consent]);
    }
}
