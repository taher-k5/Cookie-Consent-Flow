<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\helpers\Throttle;
use sfsinfotech\craftcookieconsentflow\Plugin;
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
     * See ConsentController::$enableCsrfValidation — the automatic check
     * throws from `beforeAction()`, where a controller cannot answer, and the
     * caller ends up with a 404 instead of a legible refusal. The token is
     * still required; `actionReport()` enforces it explicitly.
     */
    public $enableCsrfValidation = false;

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
            return $this->asJson(['success' => false, 'error' => 'invalid_csrf'])->setStatusCode(400);
        }

        // Far tighter than the consent endpoint: a browser reports its cookie
        // names at most once a day per name, so any real client needs a
        // handful of calls, not a stream of them.
        if (!Throttle::allow('cookie-report', 10)) {
            return $this->asJson(['success' => false, 'error' => 'rate_limited'])->setStatusCode(429);
        }

        // Cap the batch: a browser has a few dozen cookies at most, and each
        // name becomes an upsert. Anything beyond this is not a real browser.
        $names = array_slice((array) $request->getBodyParam('names', []), 0, 100);
        $names = array_values(array_filter(
            array_map('strval', $names),
            fn(string $n) => preg_match('/^[\w.\-*]{1,255}$/', $n)
        ));

        if ($names !== []) {
            try {
                Plugin::getInstance()->cookieDefinitions->recordDetected(
                    $names,
                    Craft::$app->getSites()->getCurrentSite()->id
                );
            } catch (\Throwable $e) {
                // Best-effort admin telemetry. Never surface a failure here to
                // a visitor, and never let it affect the page they asked for.
                Craft::warning('Cookie detection report failed: ' . $e->getMessage(), __METHOD__);
            }
        }

        return $this->asJson(['success' => true]);
    }
}
