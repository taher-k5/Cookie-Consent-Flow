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

    /** Consent saves permitted per IP per minute. Generous for a human, not for a script. */
    private const SAVE_LIMIT = 20;

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
     * Body: `{ "action": "accept_all|reject_all|custom", "categories": [...], "source": "banner" }`
     * Returns: `{ "success": true, "visitorUuid": "…" }`
     *
     * The authoritative copy of a visitor's decision is the one in their own
     * browser; this records it server-side as evidence. A failure here is
     * therefore reported honestly (so the client can retry) but is never
     * allowed to invalidate the decision the visitor already made.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (($csrfFailure = $this->_requireCsrf()) !== null) {
            return $csrfFailure;
        }

        $request = Craft::$app->getRequest();

        if (!Throttle::allow('consent-save', self::SAVE_LIMIT)) {
            return $this->asJson(['success' => false, 'error' => 'rate_limited'])->setStatusCode(429);
        }

        $settings = Plugin::getInstance()->cookieSettings->getEffectiveSettings();

        $action = (string) $request->getBodyParam('action', '');
        if (!in_array($action, ConsentService::ACTIONS, true)) {
            return $this->asJson(['success' => false, 'error' => 'invalid_action'])->setStatusCode(400);
        }

        $source = (string) $request->getBodyParam('source', ConsentService::SOURCE_BANNER);
        if (!in_array($source, ConsentService::SOURCES, true)) {
            $source = ConsentService::SOURCE_BANNER;
        }

        // Only categories this site actually has are recorded. A client
        // posting an unknown key is either stale (the admin removed a
        // category since the page loaded) or hostile; either way the record
        // must reflect this site's real configuration, not the client's claim.
        $known      = $settings->getCategoryKeys();
        $categories = array_values(array_intersect(
            array_map('strval', (array) $request->getBodyParam('categories', [])),
            $known
        ));

        // Locked categories are always in effect and are not the visitor's to
        // decline, so they are recorded regardless of what the client sent —
        // a record that omitted them would misstate what actually happened.
        $categories = array_values(array_unique(array_merge($categories, $settings->getLockedCategoryKeys())));

        try {
            $result = Plugin::getInstance()->consent->saveConsent($action, $categories, $source);
        } catch (\Throwable $e) {
            // Log the detail server-side; tell the visitor only that it
            // failed. Stack traces are not visitor-facing information.
            Craft::error('Cookie consent save failed: ' . $e->getMessage(), __METHOD__);

            return $this->asJson(['success' => false, 'error' => 'server_error'])->setStatusCode(500);
        }

        $response = $this->asJson([
            'success'     => true,
            'visitorUuid' => $result['visitorUuid'],
            'categories'  => $categories,
        ]);

        // First-party visitor UUID, namespaced by site so a shared-origin
        // multi-site install never lets one site inherit another's visitor
        // identity or consent.
        $response->getCookies()->add(new \yii\web\Cookie([
            'name'     => ConsentHelper::visitorCookieName($result['siteId']),
            'value'    => $result['visitorUuid'],
            'expire'   => time() + 365 * 24 * 3600,
            'httpOnly' => true,
            'secure'   => $request->getIsSecureConnection(),
            'sameSite' => \yii\web\Cookie::SAME_SITE_LAX,
        ]));

        return $response;
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

        $request = Craft::$app->getRequest();
        $siteId  = Craft::$app->getSites()->getCurrentSite()->id;

        $visitorUuid = $request->getCookies()->getValue(ConsentHelper::visitorCookieName($siteId))
            ?? $request->getCookies()->getValue(ConsentHelper::LEGACY_VISITOR_COOKIE);

        if (!$visitorUuid) {
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
