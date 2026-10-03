<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\helpers\Throttle;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;
use yii\web\Response;

/**
 * Consent Controller — the visitor-facing endpoints.
 *
 * These are anonymous by necessity: a visitor deciding about cookies is not
 * logged in. They are scoped accordingly — each one does exactly one narrow,
 * non-privileged thing, validates its input against an allow-list, is CSRF
 * checked, and is rate limited. No administrative capability is reachable
 * from here.
 */
class ConsentController extends Controller
{
    protected array|int|bool $allowAnonymous = ['save', 'status', 'geo'];

    /** Consent saves permitted per client per minute. Generous for a human, not for a script. */
    private const SAVE_LIMIT = 20;

    /**
     * Geo lookups permitted per client per minute. The runtime asks once per
     * tab (the answer is kept in sessionStorage), so a real visitor needs a
     * handful; the limit exists because a site may configure a paid lookup
     * provider behind this endpoint.
     */
    private const GEO_LIMIT = 30;

    /** Status lookups permitted per client per minute. Each one is a database read. */
    private const STATUS_LIMIT = 30;

    /** More categories than any real configuration has; anything beyond is not a browser. */
    private const MAX_CATEGORIES = 100;

    /**
     * Craft's automatic CSRF check is turned off here so each action can
     * enforce it itself and answer in JSON.
     *
     * Not a weakening: every unsafe action below calls `_requireCsrf()` as its
     * first statement, so the token is still required. The reason for taking
     * it over is that the automatic check throws inside `beforeAction()`, and
     * a controller cannot answer from there — returning `false` makes
     * `runAction()` return null, at which point Craft falls through to normal
     * URL routing and the caller gets a **404**, not the JSON (or even the
     * 400) anyone would expect.
     *
     * That matters because a rejected token is an *expected*, recoverable
     * condition for this plugin rather than an error: the runtime deliberately
     * embeds no CSRF token in (cacheable) markup, fetches one when it needs to
     * save, and retries once if it is refused. It can only do that if the
     * refusal is legible.
     */
    public $enableCsrfValidation = false;

    /**
     * Returns a JSON 400 when the CSRF token is missing or invalid, or null
     * when it is fine.
     *
     * The `invalid_csrf` code is what lets the runtime distinguish "my token
     * went stale, get a fresh one and retry" from "the server rejected the
     * payload" — the first is routine, the second is a bug.
     */
    private function _requireCsrf(): ?Response
    {
        if (Craft::$app->getRequest()->validateCsrfToken()) {
            return null;
        }

        return $this->asJson(['success' => false, 'error' => 'invalid_csrf'])->setStatusCode(400);
    }

    /**
     * POST /actions/cookie-consent-flow/consent/save
     *
     * Body: `{ "action": "accept_all|reject_all|custom", "categories": [...], "source": "banner", "policyVersion": "…" }`
     * Returns: `{ "success": true, "action": "…", "categories": [...], "recorded": true }`
     *
     * The authoritative copy of a visitor's decision is the one in their own
     * browser; this records it server-side as evidence. A failure here is
     * therefore reported honestly (so the client can retry) but is never
     * allowed to invalidate the decision the visitor already made.
     *
     * The response carries the decision *as recorded* — see
     * ConsentService::normalizeDecision() — and no longer the visitor
     * identifier, which stays in its httpOnly cookie where page scripts
     * cannot read it.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (($csrfFailure = $this->_requireCsrf()) !== null) {
            return $csrfFailure;
        }

        $request = Craft::$app->getRequest();

        if (!Throttle::check('consent-save', self::SAVE_LIMIT)) {
            return $this->asJson(['success' => false, 'error' => 'rate_limited'])->setStatusCode(429);
        }

        $payload = self::validatePayload(
            $request->getBodyParam('action'),
            $request->getBodyParam('categories', []),
            $request->getBodyParam('source'),
            $request->getBodyParam('policyVersion')
        );

        if (isset($payload['error'])) {
            return $this->asJson(['success' => false, 'error' => $payload['error']])->setStatusCode(400);
        }

        // Unknown categories are dropped and locked ones added by the
        // service, against this site's real configuration, so the record
        // reflects the site rather than the client's claim.
        try {
            $result = Plugin::getInstance()->consent->saveConsent(
                $payload['action'],
                $payload['categories'],
                $payload['source'],
                $payload['policyVersion']
            );
        } catch (\Throwable $e) {
            // Log the detail server-side; tell the visitor only that it
            // failed. Stack traces are not visitor-facing information.
            Craft::error('Cookie consent save failed: ' . $e->getMessage(), __METHOD__);

            return $this->asJson(['success' => false, 'error' => 'server_error'])->setStatusCode(500);
        }

        $response = $this->asJson([
            'success'    => true,
            'action'     => $result['action'],
            'categories' => $result['categories'],
            'recorded'   => $result['recorded'],
        ]);

        // The visitor identifier exists only to link a visitor's records to
        // each other. With logging off there are no records, so no
        // identifier is issued: a year-long tracking cookie with nothing to
        // link would be exactly the kind of cookie this plugin is for.
        //
        // First-party and namespaced by site, so a shared-origin multi-site
        // install never lets one site inherit another's visitor identity.
        if ($result['recorded'] && $result['visitorUuid'] !== null) {
            $response->getCookies()->add(new \yii\web\Cookie([
                'name'     => ConsentHelper::visitorCookieName($result['siteId']),
                'value'    => $result['visitorUuid'],
                'expire'   => time() + 365 * 24 * 3600,
                'httpOnly' => true,
                'secure'   => $request->getIsSecureConnection(),
                'sameSite' => \yii\web\Cookie::SAME_SITE_LAX,
            ]));
        }

        return $response;
    }

    /**
     * Type-checks a posted decision before anything is done with it.
     *
     * The previous code cast blindly — `(string)` on an array, `strval` over
     * nested arrays — so a malformed body raised "Array to string
     * conversion" outside any handler and was answered with a 500. A payload
     * of the wrong shape is the client's error and gets a 400 with a code
     * saying which part was wrong.
     *
     * @return array{action: string, categories: string[], source: string}|array{error: string}
     */
    public static function validatePayload(mixed $action, mixed $categories, mixed $source, mixed $policyVersion = null): array
    {
        if (!is_string($action) || !in_array($action, ConsentService::ACTIONS, true)) {
            return ['error' => 'invalid_action'];
        }

        if ($categories === null || $categories === '') {
            $categories = [];
        }

        if (!is_array($categories) || count($categories) > self::MAX_CATEGORIES) {
            return ['error' => 'invalid_categories'];
        }

        foreach ($categories as $category) {
            if (!is_string($category) || strlen($category) > 100) {
                return ['error' => 'invalid_categories'];
            }
        }

        if ($source !== null && !is_string($source)) {
            return ['error' => 'invalid_source'];
        }

        // An unrecognised source string is recorded as the banner, as before:
        // older runtimes send none, and the value is informational.
        $source = in_array($source, ConsentService::SOURCES, true) ? $source : ConsentService::SOURCE_BANNER;

        // The policy version the visitor's page was showing when they decided.
        // Absent from older runtimes, in which case the current one is used.
        if ($policyVersion !== null && (!is_string($policyVersion) || !preg_match(ConsentService::POLICY_VERSION_PATTERN, $policyVersion))) {
            return ['error' => 'invalid_policy_version'];
        }

        return [
            'action'        => $action,
            'categories'    => array_values($categories),
            'source'        => $source,
            'policyVersion' => $policyVersion,
        ];
    }

    /**
     * GET /actions/cookie-consent-flow/consent/status
     *
     * The most recent server-side record for this visitor on this site, or
     * null. Intended for debugging and for server-backed reconciliation —
     * the front-end runtime does not depend on it, because a visitor's own
     * browser is the source of truth for their current state.
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        if (!Throttle::check('consent-status', self::STATUS_LIMIT)) {
            return $this->_uncached($this->asJson(['consent' => null, 'error' => 'rate_limited'])->setStatusCode(429));
        }

        $request = Craft::$app->getRequest();
        $siteId  = Craft::$app->getSites()->getCurrentSite()->id;

        $visitorUuid = $request->getCookies()->getValue(ConsentHelper::visitorCookieName($siteId));

        if (!is_string($visitorUuid) || $visitorUuid === '') {
            return $this->_uncached($this->asJson(['consent' => null]));
        }

        return $this->_uncached($this->asJson([
            'consent' => Plugin::getInstance()->consent->getConsent($visitorUuid, $siteId),
        ]));
    }

    /**
     * GET /actions/cookie-consent-flow/consent/geo
     *
     * Resolves whether the banner applies to *this* visitor's country.
     *
     * This exists specifically for static caching. Deciding geo-targeting
     * server-side while rendering the page bakes the first visitor's country
     * into the cached HTML that every later visitor receives — so with
     * geo-targeting on, the front-end runtime asks here instead, per visitor,
     * on an explicitly uncacheable response.
     *
     * Returns only `{ show, country }`: whether the banner applies, and the
     * country the site itself already knows from the request. It reveals
     * nothing to the caller that the caller did not supply.
     */
    public function actionGeo(): Response
    {
        $this->requireAcceptsJson();

        // Refused with the fail-open answer, so a throttled visitor is shown
        // the banner (the runtime treats any non-2xx as "show") rather than
        // having optional content activated on a guess.
        if (!Throttle::check('consent-geo', self::GEO_LIMIT)) {
            return $this->_uncached(
                $this->asJson(['show' => true, 'country' => null, 'error' => 'rate_limited'])->setStatusCode(429)
            );
        }

        $plugin   = Plugin::getInstance();
        $settings = $plugin->cookieSettings->getEffectiveSettings();

        return $this->_uncached($this->asJson([
            'show'    => $plugin->geo->shouldShowBanner($settings),
            'country' => $plugin->geo->getCountryCode(),
        ]));
    }

    /**
     * Marks a response as per-visitor and never storable, so no shared cache
     * (CDN, reverse proxy, Blitz) can hand one visitor's answer to another.
     * This is the guarantee that makes the client-side geo/status split safe.
     */
    private function _uncached(Response $response): Response
    {
        $response->getHeaders()
            ->set('Cache-Control', 'private, no-store, max-age=0')
            ->set('Vary', 'Cookie');

        return $response;
    }
}
