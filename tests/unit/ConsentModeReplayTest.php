<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\services\ConsentModeService;

/**
 * The `<head>` replay must accept exactly the stored decisions the runtime
 * accepts.
 *
 * It runs before any Google tag and grants signals from the visitor's stored
 * decision; the runtime, later, decides whether that decision exists. When
 * the two disagreed, tags ran under a grant while the banner was asking the
 * visitor. This executes the rendered snippet itself (in Node, as the JS
 * harness does) rather than inspecting its source.
 */
final class ConsentModeReplayTest extends TestCase
{
    private static function node(): ?string
    {
        $path = trim((string) shell_exec('command -v node 2>/dev/null'));

        return $path !== '' ? $path : null;
    }

    /**
     * Runs the replay against one stored value and returns the consent
     * updates it sent.
     *
     * @return array<int, array<string, string>>
     */
    private function replay(?array $stored, ?string $cookie = null): array
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node.js is required to execute the replay snippet (the same requirement as composer test-js).');
        }

        $settings                     = new Settings();
        $settings->consentModeEnabled = true;
        $settings->consentModeType    = 'basic';
        $settings->policyVersion      = '2';
        $settings->consentExpiryDays  = 180;

        $html   = (new ConsentModeService())->renderScript($settings, false, null, 1);
        $script = preg_replace('#^<script[^>]*>|</script>$#', '', trim($html));

        $harness = 'var updates=[];var store=' . json_encode($stored === null ? null : json_encode($stored)) . ';'
            . 'var localStorage={getItem:function(){return store;}};'
            . 'var document={cookie:' . json_encode($cookie ?? '') . '};'
            . 'var window={};var dataLayer=[];'
            . 'var gtag=function(){if(arguments[0]==="consent"&&arguments[1]==="update")updates.push(arguments[2]);};'
            . 'eval(' . json_encode(str_replace('function gtag(){dataLayer.push(arguments);}', '', $script)) . ');'
            . 'process.stdout.write(JSON.stringify(updates));';

        $out = shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($harness) . ' 2>&1');

        $decoded = json_decode((string) $out, true);
        self::assertIsArray($decoded, 'replay did not run: ' . $out);

        return $decoded;
    }

    private static function decision(array $overrides = []): array
    {
        return $overrides + [
            'v'             => 2,
            'action'        => 'accept_all',
            'categories'    => ['necessary', 'analytics', 'marketing', 'preferences'],
            'timestamp'     => (int) (microtime(true) * 1000),
            'policyVersion' => '2',
            'source'        => 'banner',
        ];
    }

    public function testAValidDecisionIsReplayed(): void
    {
        $updates = $this->replay(self::decision());

        self::assertCount(1, $updates);
        self::assertSame('granted', $updates[0]['analytics_storage']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function rejectedByTheRuntimeProvider(): array
    {
        $now = (int) (microtime(true) * 1000);

        return [
            'stale policy version'     => [['policyVersion' => '1']],
            'expired'                  => [['timestamp' => $now - 200 * 864e5]],
            'timestamp in the future'  => [['timestamp' => $now + 30 * 864e5]],
            'no timestamp'             => [['timestamp' => null]],
            'zero timestamp'           => [['timestamp' => 0]],
            'string timestamp'         => [['timestamp' => (string) $now]],
            'newer envelope version'   => [['v' => 3]],
            'no action'                => [['action' => null]],
            'non-string action'        => [['action' => ['accept_all']]],
            'categories not an array'  => [['categories' => 'analytics']],
        ];
    }

    /**
     * @dataProvider rejectedByTheRuntimeProvider
     */
    public function testStateTheRuntimeRejectsGrantsNothing(array $override): void
    {
        $stored = self::decision();
        foreach ($override as $key => $value) {
            if ($value === null) {
                unset($stored[$key]);
            } else {
                $stored[$key] = $value;
            }
        }

        self::assertSame([], $this->replay($stored));
    }

    public function testNothingStoredGrantsNothing(): void
    {
        self::assertSame([], $this->replay(null));
    }

    public function testCorruptStorageGrantsNothingAndDoesNotThrow(): void
    {
        $node = self::node();
        if ($node === null) {
            self::markTestSkipped('Node.js is required.');
        }

        self::assertSame([], $this->replay(null, 'cck_consent_1=%7Bnot-json'));
    }

    public function testTheCookieFallbackIsReadLikeTheRuntimeReadsIt(): void
    {
        $updates = $this->replay(null, 'cck_consent_1=' . rawurlencode(json_encode(self::decision())));

        self::assertCount(1, $updates);
    }
}
