<?php

namespace sfsinfotech\craftcookieconsentflow\events;

use craft\models\Site;
use yii\base\Event;

/**
 * ResolveConsentScopeEvent — fired when the plugin works out which consent
 * scope a site belongs to.
 *
 * Sites that resolve to the same `$scopeKey` share one consent decision: one
 * browser storage key (`cck_consent_<scopeKey>`) and one visitor cookie
 * (`cck_visitor_<scopeKey>`). Consent records still carry the real site ID.
 *
 * `$scopeKey` arrives set from the Consent Scope setting — the site ID for
 * "Per site", `g<groupId>` for "Per site group" — and a listener may replace
 * it to group sites some other way:
 *
 * ```php
 * Event::on(
 *     Plugin::class,
 *     Plugin::EVENT_RESOLVE_CONSENT_SCOPE,
 *     function (ResolveConsentScopeEvent $event) {
 *         if (in_array($event->site->handle, ['hr', 'en', 'de'], true)) {
 *             $event->scopeKey = 'brand';
 *         }
 *     }
 * );
 * ```
 *
 * The key must match `[A-Za-z0-9_-]{1,64}`, because it becomes part of a
 * cookie name and a storage key. A key that does not is ignored, and the
 * setting's key is used instead, with a warning in the Craft log.
 *
 * Sharing works only between sites served from the same origin (scheme,
 * host and port): browser storage cannot be read across origins.
 */
class ResolveConsentScopeEvent extends Event
{
    /** The site whose scope is being resolved. */
    public Site $site;

    /** The Consent Scope setting — `Settings::CONSENT_SCOPE_SITE` or `CONSENT_SCOPE_GROUP`. */
    public string $scope = 'site';

    /** The resolved key. Change it to group sites differently. */
    public string $scopeKey = '';
}
