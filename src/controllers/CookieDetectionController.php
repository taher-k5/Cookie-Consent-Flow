<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Cookie Detection Controller — anonymous front-end endpoint cookie-banner.js
 * reports actual cookie NAMES to (never values), so the Cookies CP page can
 * show "detected, undocumented" cookies without a developer having to
 * already know what's running from memory. Deliberately separate from
 * ConsentController: this is site-inventory telemetry, not a consent action,
 * and runs regardless of consent state (see CLAUDE.md for why that's fine).
 */
class CookieDetectionController extends Controller
{
    protected array|int|bool $allowAnonymous = ['report'];

    /**
     * POST /actions/cookie-consent-flow/cookie-detection/report
     *
     * Expected JSON body: { "names": ["_ga", "CraftSessionId", ...] }
     */
    public function actionReport(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();

        if (!$request->validateCsrfToken()) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $names = (array) $request->getBodyParam('names', []);
        $names = array_values(array_filter(
            array_map('strval', $names),
            fn(string $n) => preg_match('/^[\w.\-*]{1,255}$/', $n)
        ));

        if ($names !== []) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
            Plugin::getInstance()->cookieDefinitions->recordDetected($names, $siteId);
        }

        return $this->asJson(['success' => true]);
    }
}
