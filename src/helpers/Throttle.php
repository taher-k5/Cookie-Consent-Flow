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
 * "One client" is one address as {@see resolveIp()} determines it, which is
 * only as good as the install's proxy configuration: behind a CDN, list its
 * ranges in Craft's `trustedHosts` or every visitor through one edge node
 * shares a bucket. What no configuration allows is a caller choosing its own
 * address by sending a forwarding header.
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
     * @param string      $bucket Identifies what is being limited, e.g. 'consent-save'.
     * @param int         $limit  Hits permitted per window.
     * @param int         $window Window length in seconds.
     * @param string|null $client The caller's address; defaults to {@see resolveIp()}
     *                            for the current request.
     */
    /**
     * How much more the socket peer may send, in total, than one visitor
     * behind it when the install has no proxy configuration. See check().
     */
    public const PEER_MULTIPLIER = 10;

    /**
     * Rate-limits the current request — what the controllers call.
     *
     * With a real proxy configuration (`trustedHosts` naming the proxy's
     * ranges), the visitor's address is known and they get one bucket.
     *
     * Without one — Craft's default `['any']` — no forwarding header can be
     * believed, but using the socket address alone would put every visitor
     * behind a CDN or load balancer into one bucket, so a busy site would
     * refuse, and eventually drop, real consent records. So two limits apply:
     *
     * - per claimed client: the socket peer plus the nearest
     *   `X-Forwarded-For` hop, at `$limit` — one visitor behind a proxy;
     * - per socket peer: at `$limit × PEER_MULTIPLIER` — the most anything
     *   behind one address can send, however many clients it claims.
     *
     * A caller inventing a new `X-Forwarded-For` per request therefore gains
     * at most the peer allowance, not an unlimited supply of buckets.
     *
     * @param \yii\web\Request|null $request Defaults to the current request.
     */
    public static function check(string $bucket, int $limit, int $window = 60, mixed $request = null): bool
    {
        $request ??= Craft::$app->getRequest();

        if (!$request instanceof \yii\web\Request) {
            return self::allow($bucket, $limit, $window, 'unknown');
        }

        $peer = self::_isIp((string) $request->getRemoteIP()) ? (string) $request->getRemoteIP() : 'unknown';

        if (self::_hasProxyConfiguration((array) $request->trustedHosts)) {
            return self::allow($bucket, $limit, $window, self::resolveIp($request));
        }

        $claimed = self::_nearestHop((string) $request->getHeaders()->get('x-forwarded-for', ''));

        if ($claimed === null) {
            return self::allow($bucket, $limit, $window, $peer);
        }

        return self::allow($bucket . ':peer', $limit * self::PEER_MULTIPLIER, $window, $peer)
            && self::allow($bucket, $limit, $window, $peer . '|' . self::bucketAddress($claimed));
    }

    public static function allow(string $bucket, int $limit, int $window = 60, ?string $client = null): bool
    {
        $cache = Craft::$app->getCache();
        $key   = self::_key($bucket, $window, self::bucketAddress($client ?? self::resolveIp()));

        try {
            $count = (int) $cache->get($key);

            if ($count >= $limit) {
                return false;
            }

            // Re-setting the whole window on each hit keeps this to one cache
            // write and no read-modify-write race worth caring about: a lost
            // increment under concurrency lets a request or two extra
            // through, which is immaterial for an abuse guard.
            if (!$cache->set($key, $count + 1, $window)) {
                // Most cache backends report failure by returning false rather
                // than throwing, which left the counter at zero for ever with
                // nothing logged.
                throw new \RuntimeException('the cache refused the write');
            }
        } catch (\Throwable $e) {
            // Deliberately fail-open, and loudly. Failing closed would refuse
            // every consent save while the cache is down, losing the evidence
            // of decisions visitors are making; failing open allows writes
            // that are still CSRF-checked, allow-listed and size-capped per
            // request. Logged as an error — not a warning — because until the
            // cache is back this endpoint is unthrottled, which an
            // administrator needs to know.
            Craft::error(
                "Cookie Consent Flow rate limiting is OFF for '{$bucket}': the cache is unavailable ("
                . $e->getMessage() . '). Requests are being allowed until it recovers.',
                __METHOD__
            );

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
     * The address comes from {@see resolveIp()} unless the caller passes one.
     */
    private static function _key(string $bucket, int $window, string $client): string
    {
        return sprintf(
            'cookie-consent-flow:throttle:%s:%s:%d',
            $bucket,
            substr(hash_hmac('sha256', $client, ConsentHelper::installSecret()), 0, 16),
            (int) floor(time() / max(1, $window))
        );
    }

    /**
     * The address a throttle bucket belongs to — and the address a consent
     * record's IP hash is derived from.
     *
     * ## Why this does not call `getUserIP()`
     *
     * Craft's `getUserIP()` returns the **leftmost** address in
     * `X-Forwarded-For` (or `Client-IP`, …) whenever the request came from a
     * host listed in `trustedHosts` — and Craft's default for `trustedHosts`
     * is `['any']`, which trusts every host. On a default install, then, any
     * caller could send a different `X-Forwarded-For` with every request and
     * get a fresh bucket each time: the throttle did nothing against exactly
     * the caller it exists to stop. The leftmost entry is also the one the
     * *client* wrote, so even behind a genuinely trusted proxy it is the
     * least trustworthy address in the chain.
     *
     * ## What it does instead
     *
     * - With no meaningful proxy configuration — `trustedHosts` empty, or a
     *   range that matches every address (`any`, `*`, `0.0.0.0/0`, `::/0`) —
     *   no forwarded header is believed and the socket address is used.
     * - When the socket peer is not one of the configured proxy ranges, the
     *   socket address is used: headers from an untrusted peer are the
     *   client's own claim.
     * - When it is, the forwarding chain is walked from the **right**, past
     *   every hop inside a trusted range, and the first address outside them
     *   is the client — the standard "first untrusted hop" rule. Only the IP
     *   headers the matching `trustedHosts` entry allows are read.
     *
     * So a site behind a CDN gets per-visitor buckets by listing the CDN's
     * ranges in `trustedHosts`, which is the configuration Craft already
     * documents for that purpose; and without it, it gets per-connection
     * buckets that a spoofed header cannot multiply.
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

        if (!$request instanceof \yii\web\Request) {
            return 'unknown';
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = is_array($values) ? implode(',', $values) : (string) $values;
        }

        return self::clientIp(
            $request->getRemoteIP(),
            $headers,
            (array) $request->trustedHosts,
            (array) $request->ipHeaders
        ) ?? 'unknown';
    }

    /**
     * The pure form of {@see resolveIp()}: the client address given the socket
     * peer, the request headers (lower-cased names), the `trustedHosts`
     * configuration in either of Yii's shapes (`['10.0.0.0/8']` or
     * `['10.0.0.0/8' => ['X-Forwarded-For']]`) and the IP headers to consult.
     *
     * @param array<string, string>                  $headers
     * @param array<int|string, string|string[]>     $trustedHosts
     * @param string[]                               $ipHeaders
     */
    public static function clientIp(?string $remoteIp, array $headers, array $trustedHosts, array $ipHeaders = self::FORWARDED_HEADERS): ?string
    {
        $remoteIp = $remoteIp !== null && self::_isIp($remoteIp) ? $remoteIp : null;

        if ($remoteIp === null) {
            return null;
        }

        // Normalise to [range => allowedHeaders|null], null meaning "any of
        // the forwarding headers".
        $rules = [];
        foreach ($trustedHosts as $key => $value) {
            if (is_array($value)) {
                $rules[] = [(string) $key, array_map('strtolower', $value)];
            } else {
                $rules[] = [(string) $value, null];
            }
        }

        $ranges = array_column($rules, 0);

        if (!self::_hasProxyConfiguration($trustedHosts)) {
            return $remoteIp;
        }

        $allowedHeaders = null;
        $peerTrusted    = false;

        foreach ($rules as [$range, $allowed]) {
            if (self::_inRange($remoteIp, $range)) {
                $peerTrusted    = true;
                $allowedHeaders = $allowed;
                break;
            }
        }

        if (!$peerTrusted) {
            return $remoteIp;
        }

        // Only a header a proxy *rewrites* can be believed. Craft's ipHeaders
        // also list Client-IP, X-Cluster-Client-IP and others, which proxies
        // pass through untouched — reading those let a client behind a real,
        // trusted proxy choose its own address with every request. The
        // intersection with FORWARDED_HEADERS (X-Forwarded-For, which Yii
        // itself treats as a secure header) keeps only what the proxy writes,
        // and only the first one present is read: a header with no untrusted
        // hop must not fall through to a less trustworthy one.
        $candidates = array_values(array_intersect(
            array_map('strtolower', $ipHeaders),
            array_map('strtolower', self::FORWARDED_HEADERS)
        ));

        foreach ($candidates as $header) {
            if ($allowedHeaders !== null && !in_array($header, $allowedHeaders, true)) {
                continue;
            }

            $value = trim($headers[$header] ?? '');

            if ($value === '') {
                continue;
            }

            $hops = array_reverse(preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);

            foreach ($hops as $hop) {
                $hop = self::_stripPort($hop);

                if ($hop === null) {
                    // A malformed entry ends the chain: nothing to its left
                    // can be attributed to a trusted hop.
                    break;
                }

                $trusted = false;
                foreach ($ranges as $range) {
                    if (self::_inRange($hop, $range)) {
                        $trusted = true;
                        break;
                    }
                }

                if (!$trusted) {
                    return $hop;
                }
            }

            break;
        }

        return $remoteIp;
    }

    /** The forwarding headers a proxy rewrites, and so the only ones read. */
    public const FORWARDED_HEADERS = ['X-Forwarded-For'];

    /**
     * The address a bucket is keyed on: IPv6 is reduced to its /64, because a
     * single IPv6 client commonly controls a whole /64 and could otherwise
     * rotate through 2^64 buckets. IPv4 and non-addresses are unchanged.
     */
    public static function bucketAddress(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $address;
        }

        $packed = inet_pton($address);

        return $packed === false ? $address : inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Whether trustedHosts names real proxy ranges rather than "anything". */
    private static function _hasProxyConfiguration(array $trustedHosts): bool
    {
        $ranges = [];
        foreach ($trustedHosts as $key => $value) {
            $ranges[] = is_array($value) ? (string) $key : (string) $value;
        }

        return $ranges !== [] && !self::_trustsEverything($ranges);
    }

    /** The rightmost valid address in an X-Forwarded-For value, or null. */
    private static function _nearestHop(string $value): ?string
    {
        $hops = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $hops === [] ? null : self::_stripPort((string) end($hops));
    }

    /**
     * An address without the port some proxies append (`203.0.113.5:4711`,
     * `[2001:db8::1]:443`), or null when it is not an address at all.
     */
    private static function _stripPort(string $hop): ?string
    {
        $hop = trim($hop);

        if (preg_match('/^\[([0-9a-f:.]+)\](?::\d+)?$/iD', $hop, $m)) {
            $hop = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/D', $hop, $m)) {
            $hop = $m[1];
        }

        return self::_isIp($hop) ? $hop : null;
    }

    /** Whether a configured range list amounts to trusting every address. */
    private static function _trustsEverything(array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (in_array(strtolower(trim($range)), ['any', '*', '0.0.0.0/0', '::/0'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function _isIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private static function _inRange(string $ip, string $range): bool
    {
        $validator = new \yii\validators\IpValidator(['ranges' => [$range]]);

        return $validator->validate($ip);
    }
}
