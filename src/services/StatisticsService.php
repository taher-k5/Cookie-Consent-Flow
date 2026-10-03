<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use Craft;
use craft\base\Component;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\caching\TagDependency;

/**
 * Statistics Service — the cached aggregation layer in front of
 * {@see ConsentService}'s raw counting queries.
 *
 * The dashboard, the CP widget and the consent-records page all want the same
 * action counts, on pages an admin reloads constantly. Caching them for a few
 * minutes turns "a grouped scan of a growing table on every render" into
 * "one scan per window".
 *
 * The cache is dependency-tagged, so saving consent invalidates it
 * immediately rather than leaving an admin looking at numbers that silently
 * lag behind by up to the TTL.
 */
class StatisticsService extends Component
{
    /** Cache tag invalidated whenever a consent record is written or purged. */
    public const CACHE_TAG = 'cookie-consent-flow:stats';

    /**
     * How long an aggregate stays cached. Short enough that an admin watching
     * consent come in sees movement, long enough that a dashboard refresh
     * isn't a table scan.
     */
    public const CACHE_DURATION = 300;

    /**
     * Action counts only — what the CP widget and the records page header
     * need, without paying for the per-category scan.
     *
     * @return array{total: int, acceptAll: int, rejectAll: int, custom: int}
     */
    public function getActionCounts(?int $siteId): array
    {
        return $this->_cached(
            "actions:{$siteId}",
            fn(): array => Plugin::getInstance()->consent->getStats($siteId)
        );
    }

    /**
     * Drops every cached aggregate. Called after a consent record is written
     * or purged, so the CP never shows a figure that contradicts the records
     * list next to it.
     */
    public function invalidate(): void
    {
        // TagDependency::invalidate(), not $cache->invalidateTags() — tag
        // invalidation is a static helper in Yii, not a method on the cache
        // component. Craft's default FileCache has no such method, so the
        // wrong call fataled; and because ConsentService::saveConsent() calls
        // this after every write, that fatal surfaced as a 500 on the
        // visitor-facing consent endpoint.
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    /**
     * @template T
     * @param  callable():T $compute
     * @return T
     */
    private function _cached(string $key, callable $compute): mixed
    {
        return Craft::$app->getCache()->getOrSet(
            self::CACHE_TAG . ':' . $key,
            $compute,
            self::CACHE_DURATION,
            new TagDependency(['tags' => [self::CACHE_TAG]])
        );
    }
}
