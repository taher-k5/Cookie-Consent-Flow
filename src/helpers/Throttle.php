<?php

namespace sfsinfotech\craftcookieconsentflow\helpers;

use Craft;

/**
 * A minimal fixed-window rate limiter for the plugin's anonymous endpoints.
 *
 * These endpoints have to be anonymous — a visitor deciding about cookies is
 * by definition not logged in — which means they are reachable by anyone who
 * can reach the site. None of them can read or leak anything, but consent
 * saving and cookie-name reporting both write rows, so an unthrottled caller
 * could inflate those tables. This caps how often one client may write.
 *
 * Deliberately cache-backed rather than a database table: the counters are
 * disposable, and a throttle that itself writes to the database on every
 * request would defeat its own purpose. If the cache is unavailable the call
 * is allowed through — a rate limiter that fails closed would take consent
 * saving down with the cache, which is a far worse outcome than a burst of
 * writes.
 */
class Throttle
{
    /**
     * Records a hit and returns whether the caller is still within its
     * allowance.
     *
     * @param string $bucket Identifies what is being limited, e.g. 'consent-save'.
     * @param int    $limit  Hits permitted per window.
     * @param int    $window Window length in seconds.
     */
    public static function allow(string $bucket, int $limit, int $window = 60): bool
    {
        $cache = Craft::$app->getCache();
        $key   = self::_key($bucket, $window);

        try {
            $count = (int) $cache->get($key);

            if ($count >= $limit) {
                return false;
            }

            // Re-setting the whole window on each hit keeps this to one cache
            // write and no read-modify-write race worth caring about: a lost
            // increment under concurrency lets a request or two extra
            // through, which is immaterial for an abuse guard.
            $cache->set($key, $count + 1, $window);
        } catch (\Throwable $e) {
            Craft::warning('Cookie Consent Flow throttle unavailable: ' . $e->getMessage(), __METHOD__);

            return true;
        }

        return true;
    }

    /**
     * Buckets by IP and window start. The IP is hashed with Craft's own
     * security key (a stable, install-specific secret) rather than a per-call
     * random salt: a throttle counter must be re-derivable for the same
     * caller, which a random salt makes impossible by design. This value only
     * ever lives in the cache for the length of one window, and is never
     * stored alongside consent records — those keep using
     * ConsentHelper::hashIp()'s deliberately non-correlatable salted hash.
     *
     * The address comes from {@see resolveIp()}.
     */
    private static function _key(string $bucket, int $window): string
    {
        return sprintf(
            'cookie-consent-flow:throttle:%s:%s:%d',
            $bucket,
            substr(hash_hmac('sha256', self::resolveIp(), Craft::$app->getConfig()->getGeneral()->securityKey), 0, 16),
            (int) floor(time() / max(1, $window))
        );
    }

    /**
     * The address a throttle bucket belongs to.
     *
     * `getUserIP()`, not `getRemoteIP()`. The latter is the raw socket peer,
     * which behind a CDN or load balancer is the edge node rather than the
     * visitor — so every visitor arriving through one edge shared a single
     * bucket, and a site busy enough to matter started refusing legitimate
     * consent saves with a 429. `getUserIP()` is the proxy-aware answer: it
     * reads a forwarded header **only** where the install has configured that
     * proxy as trusted (`trustedHosts`), and otherwise falls back to the
     * socket address.
     *
     * Nothing here parses `X-Forwarded-For`, `CF-Connecting-IP` or any other
     * header itself. That is the point: header trust is a deployment fact only
     * the install's configuration knows, and a helper that guessed would hand
     * anyone a free bucket per spoofed header — turning the rate limit off for
     * exactly the caller it exists to stop.
     *
     * A request that is not a web request (a console command, a queue job) has
     * no address at all and gets a shared, constant bucket rather than an
     * error.
     *
     * @param mixed $request Defaults to the current application request;
     *                       injectable so the resolution can be verified.
     */
    public static function resolveIp(mixed $request = null): string
    {
        $request ??= Craft::$app->getRequest();

        return ($request instanceof \yii\web\Request ? $request->getUserIP() : null) ?? 'unknown';
    }
}
