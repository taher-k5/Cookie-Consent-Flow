<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\caching\TagDependency;

/**
 * Statistics Service — the cached aggregation layer in front of
 * {@see ConsentService}'s raw counting queries.
 *
 * The dashboard, the CP widget and the consent-records page all want the same
 * numbers, and two of the three render on pages an admin reloads constantly.
 * The action counts and the daily trend are cheap grouped queries, but the
 * per-category breakdown has to scan the `categories` JSON column row by row,
 * which is linear in the size of the log table. Caching the whole bundle for a
 * few minutes turns "expensive on a big table, on every render" into
 * "expensive once per window".
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
     * Everything the dashboard needs, in one call.
     *
     * `$filters` (see ConsentService::buildQuery()) is applied to the outcome
     * counts and the category breakdown together, so the two always describe
     * the same set of records. The trend line keeps its own `$trendDays`
     * window, which is what it is for.
     *
     * @param array<string, mixed> $filters
     * @return array{
     *     total: int, acceptAll: int, rejectAll: int, custom: int,
     *     categories: array<string, array{count: int, percent: float, label: string}>,
     *     trend: array<int, array{date: string, count: int}>
     * }
     */
    public function getOverview(?int $siteId, int $trendDays = 30, array $filters = []): array
    {
        $key = "overview:{$siteId}:{$trendDays}:" . self::filterKey($filters);

        return $this->_cached($key, function () use ($siteId, $trendDays, $filters): array {
            $plugin = Plugin::getInstance();
            $labels = $this->_categoryLabels($siteId);

            $stats      = $plugin->consent->getStats($siteId, $filters);
            $categories = $plugin->consent->getCategoryStats($siteId, array_keys($labels), $filters);

            foreach ($categories as $key => $data) {
                $categories[$key]['label'] = $labels[$key];
            }

            return $stats + [
                'categories' => $categories,
                'trend'      => $plugin->consent->getDailyTrend($siteId, $trendDays),
            ];
        });
    }

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
     * A short, stable cache-key fragment for a filter set.
     *
     * Order-independent and value-sensitive: two callers asking the same
     * question with the keys written in a different order must hit the same
     * entry, and two callers asking different questions must never share one.
     * An empty set gets a fixed marker rather than an empty string, so an
     * unfiltered key can't collide with anything.
     *
     * @param array<string, mixed> $filters
     */
    public static function filterKey(array $filters): string
    {
        if ($filters === []) {
            return 'all';
        }

        ksort($filters);

        return substr(sha1(Json::encode($filters)), 0, 12);
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
     * The category key → label map a breakdown should be reported under.
     *
     * For one site that is simply that site's effective categories. For "All
     * Sites" it is the union across every site, because the records being
     * counted come from every site: labelling that aggregate with the primary
     * site's vocabulary alone both mislabels categories a site has renamed and
     * drops categories only another site defines — those records were counted
     * but had nowhere to appear.
     *
     * The union is ordered deterministically: global categories first in their
     * configured order, then each site's own additions in site-ID order, and
     * the first label seen for a key wins. No extra queries — every site's
     * overrides are already in the loaded settings model, which is
     * request-cached, and the whole result is cached by the caller.
     *
     * @return array<string, string>
     */
    private function _categoryLabels(?int $siteId): array
    {
        $cookieSettings = Plugin::getInstance()->cookieSettings;

        if ($siteId !== null) {
            return $this->_labelsFrom($cookieSettings->getEffectiveSettings($siteId)->categories);
        }

        $labels = $this->_labelsFrom($cookieSettings->loadSettings()->categories);

        $sites = Craft::$app->getSites()->getAllSites();
        usort($sites, static fn($a, $b): int => $a->id <=> $b->id);

        foreach ($sites as $site) {
            foreach ($this->_labelsFrom($cookieSettings->getEffectiveSettings($site->id)->categories) as $key => $label) {
                $labels[$key] ??= $label;
            }
        }

        return $labels;
    }

    /**
     * @param  array<int, array<string, mixed>> $categories
     * @return array<string, string>
     */
    private function _labelsFrom(array $categories): array
    {
        $labels = [];

        foreach ($categories as $category) {
            $key = (string) ($category['key'] ?? '');

            if ($key !== '') {
                $labels[$key] = (string) ($category['label'] ?? '') ?: $key;
            }
        }

        return $labels;
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
