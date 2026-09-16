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
}
