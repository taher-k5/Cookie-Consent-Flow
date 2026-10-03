<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\helpers\Throttle;
use yii\console\Request as ConsoleRequest;
use yii\web\Request as WebRequest;

/**
 * Which address a rate-limit bucket belongs to.
 *
 * This is the difference between a throttle that limits a visitor and one that
 * limits a CDN edge node. Getting it wrong is invisible in development — where
 * there is no proxy and both answers agree — and shows up in production as
 * legitimate visitors receiving 429s and their consent never being recorded.
 * So the proxy cases are stated here rather than left to the deployment.
 */
final class ThrottleTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;

        $_SERVER['HTTP_HOST']      = 'example.test';
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /**
     * Builds a request as it would arrive, optionally through a proxy the
     * install has been configured to trust.
     *
     * @param string[] $trustedHosts
     */
    private function request(string $remoteAddr, ?string $forwardedFor = null, array $trustedHosts = []): WebRequest
    {
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;

        if ($forwardedFor !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        } else {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        }

        return new WebRequest(['trustedHosts' => $trustedHosts]);
    }

    /** No proxy involved: the socket address is the visitor. */
    public function testDirectRequestUsesTheSocketAddress(): void
    {
        self::assertSame('203.0.113.9', Throttle::resolveIp($this->request('203.0.113.9')));
    }

    /** The same visitor keeps hitting the same bucket across requests. */
    public function testSameVisitorResolvesConsistently(): void
    {
        self::assertSame(
            Throttle::resolveIp($this->request('203.0.113.9')),
            Throttle::resolveIp($this->request('203.0.113.9'))
        );
    }

    /**
     * The regression this exists for: two visitors arriving through one
     * trusted proxy are two visitors, not one bucket.
     */
    public function testVisitorsBehindOneTrustedProxyGetSeparateBuckets(): void
    {
        $edge = '203.0.113.9';

        $first  = Throttle::resolveIp($this->request($edge, '198.51.100.7', [$edge]));
        $second = Throttle::resolveIp($this->request($edge, '198.51.100.8', [$edge]));

        self::assertSame('198.51.100.7', $first);
        self::assertSame('198.51.100.8', $second);
        self::assertNotSame($first, $second);
    }

    /**
     * And the security half of the same decision: a forwarded header from a
     * host the install has *not* declared trusted is ignored, so it cannot be
     * used to mint a fresh bucket per request and step around the limit.
     */
    public function testForwardedHeaderIsIgnoredFromAnUntrustedHost(): void
    {
        self::assertSame(
            '203.0.113.9',
            Throttle::resolveIp($this->request('203.0.113.9', '198.51.100.7'))
        );
    }

    /**
     * A console command has no address. It must get a constant rather than an
     * error, since saveConsent() is documented as callable from one.
     */
    public function testNonWebRequestHasNoAddress(): void
    {
        self::assertSame('unknown', Throttle::resolveIp(new ConsoleRequest()));
    }

    // -------------------------------------------------------------------------
    // H2 — the rate-limit bypass on Craft's default configuration
    // -------------------------------------------------------------------------

    /**
     * The regression itself. Craft's default `trustedHosts` is `['any']`,
     * which trusts every peer, and `getUserIP()` then returns whatever the
     * client wrote into `X-Forwarded-For` — one fresh bucket per request.
     * The tests above used an empty trust list, which is not what Craft
     * ships, and so never saw it.
     */
    public function testSpoofedForwardedForOnCraftsDefaultConfigurationDoesNotChangeTheBucket(): void
    {
        $craftDefault = ['any'];

        $buckets = [];
        foreach (['198.51.100.1', '198.51.100.2', '10.0.0.1', '1.2.3.4'] as $spoofed) {
            $buckets[] = Throttle::resolveIp($this->request('203.0.113.9', $spoofed, $craftDefault));
        }

        self::assertSame(['203.0.113.9'], array_values(array_unique($buckets)));
    }

    /** `Client-IP` is one of Craft's IP headers too, and gets the same treatment. */
    public function testSpoofedClientIpIsIgnored(): void
    {
        $_SERVER['REMOTE_ADDR']    = '203.0.113.9';
        $_SERVER['HTTP_CLIENT_IP'] = '198.51.100.44';

        $request = new WebRequest([
            'trustedHosts' => ['any'],
            'ipHeaders'    => ['Client-IP', 'X-Forwarded-For'],
        ]);

        try {
            self::assertSame('203.0.113.9', Throttle::resolveIp($request));
        } finally {
            unset($_SERVER['HTTP_CLIENT_IP']);
        }
    }

    /**
     * The same attacker repeating requests with a new header each time lands
     * in the same bucket, so the limit is reached.
     */
    public function testRepeatedSpoofedRequestsAreThrottledTogether(): void
    {
        \Craft::$app->set('cache', new \yii\caching\ArrayCache());

        $allowed = 0;
        for ($i = 0; $i < 30; $i++) {
            $_SERVER['REMOTE_ADDR']          = '203.0.113.9';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.' . $i;

            $ip = Throttle::resolveIp(new WebRequest(['trustedHosts' => ['any']]));

            if (Throttle::allow('spoof-test', 20, 60, $ip)) {
                $allowed++;
            }
        }

        self::assertSame(20, $allowed, 'the 21st request onwards is refused, whatever header it carries');
    }

    /** A trusted proxy's own hop is skipped; the client is the first untrusted hop from the right. */
    public function testMultipleProxyHopsResolveToTheFirstUntrustedHop(): void
    {
        $trusted = ['10.0.0.0/8', '203.0.113.0/24'];

        // client → CDN edge (203.0.113.5) → internal LB (10.0.0.2) → app
        self::assertSame(
            '198.51.100.7',
            Throttle::resolveIp($this->request('10.0.0.2', '198.51.100.7, 203.0.113.5', $trusted))
        );
    }

    /**
     * Behind a real proxy, a client can still prepend a value of its own to
     * `X-Forwarded-For`; the proxy appends the address it actually saw. That
     * prepended value is the leftmost entry — the one `getUserIP()` returned.
     */
    public function testClientPrependedValueBehindATrustedProxyIsIgnored(): void
    {
        $edge = '203.0.113.9';

        self::assertSame(
            '198.51.100.7',
            Throttle::resolveIp($this->request($edge, '6.6.6.6, 198.51.100.7', [$edge]))
        );
    }

    /** A proxy entry can restrict which headers it is trusted for. */
    public function testTrustedHostHeaderRestrictionsAreHonoured(): void
    {
        self::assertSame(
            '203.0.113.9',
            Throttle::clientIp(
                '203.0.113.9',
                ['x-forwarded-for' => '198.51.100.7'],
                ['203.0.113.0/24' => ['X-Real-IP']],
                ['X-Forwarded-For']
            ),
            'X-Forwarded-For is not one of the headers this proxy is trusted for'
        );

        self::assertSame(
            '198.51.100.7',
            Throttle::clientIp(
                '203.0.113.9',
                ['x-forwarded-for' => '198.51.100.7'],
                ['203.0.113.0/24' => ['X-Forwarded-For']],
                ['X-Forwarded-For']
            )
        );
    }

    /** Ranges that match everything are not a proxy configuration. */
    public function testEveryCatchAllRangeMeansSocketAddress(): void
    {
        foreach ([['*'], ['0.0.0.0/0'], ['::/0'], ['ANY'], []] as $trusted) {
            self::assertSame(
                '203.0.113.9',
                Throttle::clientIp('203.0.113.9', ['x-forwarded-for' => '198.51.100.7'], $trusted, ['X-Forwarded-For']),
                'trustedHosts ' . json_encode($trusted)
            );
        }
    }

    /** Garbage in the chain stops the walk rather than being returned. */
    public function testMalformedForwardedEntriesAreNeverReturned(): void
    {
        self::assertSame(
            '10.0.0.2',
            Throttle::clientIp('10.0.0.2', ['x-forwarded-for' => 'not-an-ip'], ['10.0.0.0/8'], ['X-Forwarded-For'])
        );
    }

    /** IPv6 peers and chains work the same way. */
    public function testIpv6(): void
    {
        self::assertSame(
            '2001:db8::1',
            Throttle::clientIp('2001:db8::1', ['x-forwarded-for' => '2001:db8::99'], ['any'], ['X-Forwarded-For'])
        );
        self::assertSame(
            '2001:db8:1::5',
            Throttle::clientIp('fd00::1', ['x-forwarded-for' => '2001:db8:1::5'], ['fd00::/8'], ['X-Forwarded-For'])
        );
    }

    // -------------------------------------------------------------------------
    // Cache failure: fail-open, but never silently
    // -------------------------------------------------------------------------

    /** A cache that fails the way most backends do: by returning false. */
    private function failingCache(bool $throw): \yii\caching\CacheInterface
    {
        return new class($throw) extends \yii\caching\ArrayCache {
            public function __construct(private bool $throw) { parent::__construct(); }
            protected function setValue($key, $value, $duration)
            {
                if ($this->throw) {
                    throw new \RuntimeException('connection refused');
                }
                return false;
            }
        };
    }

    /**
     * Both ways a cache backend fails — throwing, and quietly returning false
     * (which is what most do) — keep consent saves working and log an error
     * naming the bucket, so an unthrottled endpoint is never silent.
     */
    public function testACacheFailureFailsOpenAndLogsAnError(): void
    {
        foreach (['throws' => true, 'returns false' => false] as $mode => $throws) {
            \Craft::$app->set('cache', $this->failingCache($throws));
            \Yii::getLogger()->messages = [];

            self::assertTrue(Throttle::allow('fail-test', 1, 60, '203.0.113.9'), "{$mode}: first request allowed");
            self::assertTrue(Throttle::allow('fail-test', 1, 60, '203.0.113.9'), "{$mode}: fail-open, not fail-closed");

            $errors = array_filter(
                \Yii::getLogger()->messages,
                static fn(array $m): bool => $m[1] === \yii\log\Logger::LEVEL_ERROR
                    && str_contains((string) $m[0], "rate limiting is OFF for 'fail-test'")
            );

            self::assertCount(2, $errors, "{$mode}: each unthrottled request is logged as an error");
        }

        \Craft::$app->set('cache', new \yii\caching\ArrayCache());
    }

    public function testAWorkingCacheLogsNothing(): void
    {
        \Craft::$app->set('cache', new \yii\caching\ArrayCache());
        \Yii::getLogger()->messages = [];

        Throttle::allow('quiet-test', 5, 60, '203.0.113.9');

        self::assertSame([], \Yii::getLogger()->messages);
    }

    // -------------------------------------------------------------------------
    // Final audit: headers a proxy does not rewrite, ports, IPv6, CDN default
    // -------------------------------------------------------------------------

    /**
     * Behind a real, trusted proxy, `Client-IP` is passed through untouched,
     * so a client could still pick its own address with it — and Craft lists
     * it first among its IP headers. Only X-Forwarded-For is read.
     */
    public function testClientIpIsIgnoredEvenBehindATrustedProxy(): void
    {
        self::assertSame(
            '198.51.100.7',
            Throttle::clientIp(
                '10.0.0.2',
                ['client-ip' => '6.6.6.6', 'x-forwarded-for' => '198.51.100.7'],
                ['10.0.0.0/8'],
                ['Client-IP', 'X-Forwarded-For', 'X-Cluster-Client-IP']
            )
        );

        self::assertSame(
            '10.0.0.2',
            Throttle::clientIp('10.0.0.2', ['client-ip' => '6.6.6.6', 'x-cluster-client-ip' => '7.7.7.7'], ['10.0.0.0/8'], ['Client-IP', 'X-Cluster-Client-IP']),
            'no rewritten header present: the peer, never a pass-through header'
        );
    }

    public function testRealWebRequestBehindTrustedProxyIgnoresClientIp(): void
    {
        $_SERVER['REMOTE_ADDR']          = '10.0.0.2';
        $_SERVER['HTTP_CLIENT_IP']       = '6.6.6.6';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

        try {
            $request = new WebRequest(['trustedHosts' => ['10.0.0.0/8'], 'ipHeaders' => ['Client-IP', 'X-Forwarded-For']]);
            self::assertSame('198.51.100.7', Throttle::resolveIp($request));
        } finally {
            unset($_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        }
    }

    public function testPortsOnForwardedHopsAreStripped(): void
    {
        self::assertSame('198.51.100.7', Throttle::clientIp('10.0.0.2', ['x-forwarded-for' => '198.51.100.7:51234'], ['10.0.0.0/8']));
        self::assertSame('2001:db8::7', Throttle::clientIp('10.0.0.2', ['x-forwarded-for' => '[2001:db8::7]:443'], ['10.0.0.0/8']));
    }

    public function testIpv6AddressesShareTheirSlash64Bucket(): void
    {
        self::assertSame(Throttle::bucketAddress('2001:db8:1:2::1'), Throttle::bucketAddress('2001:db8:1:2:ffff:ffff:ffff:ffff'));
        self::assertNotSame(Throttle::bucketAddress('2001:db8:1:2::1'), Throttle::bucketAddress('2001:db8:1:3::1'));
        self::assertSame('203.0.113.9', Throttle::bucketAddress('203.0.113.9'));

        \Craft::$app->set('cache', new \yii\caching\ArrayCache());
        $allowed = 0;
        for ($i = 0; $i < 30; $i++) {
            if (Throttle::allow('v6', 20, 60, '2001:db8:1:2::' . dechex($i + 1))) {
                $allowed++;
            }
        }
        self::assertSame(20, $allowed, 'rotating through one /64 does not escape the limit');
    }

    /** Runs check() for a request from $peer carrying $xff, on Craft's default configuration. */
    private function checkDefault(string $peer, ?string $xff, int $limit): bool
    {
        return Throttle::check('tier', $limit, 60, $this->request($peer, $xff, ['any']));
    }

    /**
     * On Craft's default configuration a forger inventing X-Forwarded-For is
     * capped by the per-peer allowance, not given unlimited buckets.
     */
    public function testForgedForwardedForIsCappedPerPeerOnDefaultConfiguration(): void
    {
        \Craft::$app->set('cache', new \yii\caching\ArrayCache());
        $allowed = 0;

        for ($i = 0; $i < 500; $i++) {
            if ($this->checkDefault('203.0.113.9', '198.51.' . intdiv($i, 250) . '.' . ($i % 250), 20)) {
                $allowed++;
            }
        }

        self::assertSame(20 * Throttle::PEER_MULTIPLIER, $allowed);
    }

    /**
     * …while real visitors behind one CDN edge are not collapsed into a
     * single 20-per-minute bucket, which silently dropped consent records on
     * busy sites.
     */
    public function testVisitorsBehindACdnKeepSeparateAllowancesOnDefaultConfiguration(): void
    {
        \Craft::$app->set('cache', new \yii\caching\ArrayCache());

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $visitor) {
            for ($i = 0; $i < 20; $i++) {
                self::assertTrue($this->checkDefault('203.0.113.9', $visitor, 20), "{$visitor} request " . ($i + 1));
            }
            self::assertFalse($this->checkDefault('203.0.113.9', $visitor, 20), "{$visitor} is limited individually");
        }
    }

    public function testWithoutForwardingHeadersTheSocketAddressIsTheBucket(): void
    {
        \Craft::$app->set('cache', new \yii\caching\ArrayCache());
        $allowed = 0;
        for ($i = 0; $i < 25; $i++) {
            if ($this->checkDefault('203.0.113.50', null, 20)) {
                $allowed++;
            }
        }
        self::assertSame(20, $allowed);
    }

    public function testWithAProxyConfigurationEachVisitorHasOneBucket(): void
    {
        \Craft::$app->set('cache', new \yii\caching\ArrayCache());
        $allowed = 0;
        for ($i = 0; $i < 25; $i++) {
            if (Throttle::check('trusted', 20, 60, $this->request('10.0.0.2', '198.51.100.9', ['10.0.0.0/8']))) {
                $allowed++;
            }
        }
        self::assertSame(20, $allowed);
    }
}
