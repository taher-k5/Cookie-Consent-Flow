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
 * ConsentController: this is site-inventory telemetry, not a consent action.
 * The runtime calls it only once the page has a settled consent state — see
 * `_reportDetectedCookies()` in cookie-banner.js.
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

        // 10 requests per minute per client (see Throttle::check(): per
        // visitor with a real trustedHosts, otherwise per claimed client
        // within a cap of 10 × PEER_MULTIPLIER per socket address), each
        // carrying at most 100 names. Far tighter than the consent endpoint,
        // because the runtime reports each name at most once a day, so a
        // real browser needs a handful of calls, not a stream of them.
        if (!Throttle::check('cookie-report', 10)) {
            return $this->asJson(['success' => false, 'error' => 'rate_limited'])->setStatusCode(429);
        }

        $names = self::validateNames($request->getBodyParam('names', []));

        if ($names === null) {
            return $this->asJson(['success' => false, 'error' => 'invalid_names'])->setStatusCode(400);
        }

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

    /**
     * A reportable cookie name: 1–255 characters from the RFC 6265 `token`
     * set, minus `*`. Exactly these, and nothing else:
     *
     *     A–Z a–z 0–9 ! # $ % & ' + - . ^ _ ` | ~
     *
     * Refused: `*` (see below); the RFC's separators — `( ) < > @ , ; : \ " /
     * [ ] ? = { }` — of which the backslash is one, so no real cookie name
     * contains it; space, tab and every other control character, including
     * DEL; and any non-ASCII byte. The `D` modifier stops `$` matching before
     * a trailing newline. `CookieDetectionControllerTest` checks every byte.
     *
     * The previous pattern (`[\w.\-*]`) dropped legitimate names — Adobe's
     * `AMCV_…%40AdobeOrg`, anything with `|`, `~`, `!` — while accepting `*`.
     * `*` is a valid token character but is excluded here on purpose: it is
     * the wildcard in documented cookie patterns, so a reported name of `*`,
     * documented from the Cookies page with one click, would match every
     * cookie and hide all undocumented ones.
     *
     * Separators, whitespace, quotes and control characters can never be
     * part of a name, which also keeps names inert in HTML, JSON and SQL
     * (they are escaped and bound regardless).
     */
    public const NAME_PATTERN = "/^[A-Za-z0-9!#$%&'+\\-.^_`|~]{1,255}$/D";

    /**
     * The reportable names in a posted list, or null when the list itself is
     * malformed (not a list, or containing anything but strings) — a 400,
     * rather than the "Array to string conversion" 500 a blind cast raised.
     *
     * Capped: a browser has a few dozen cookies at most, and each name
     * becomes an upsert. Names that are not plausible cookie names are
     * dropped individually; a browser can hold odd ones and that is not the
     * client's error.
     *
     * @return string[]|null
     */
    public static function validateNames(mixed $names): ?array
    {
        if ($names === null || $names === '') {
            return [];
        }

        if (!is_array($names)) {
            return null;
        }

        foreach ($names as $name) {
            if (!is_string($name)) {
                return null;
            }
        }

        return array_values(array_filter(
            array_slice(array_values($names), 0, 100),
            static fn(string $name): bool => (bool) preg_match(self::NAME_PATTERN, $name)
        ));
    }
}
