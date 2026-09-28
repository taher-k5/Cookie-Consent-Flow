<?php

/**
 * HTTP checks against a running Craft site with the fixture templates from
 * tests/integration/templates/ copied into its `templates/` folder.
 *
 *     php -S 127.0.0.1:8611 -t web path/to/tests/integration/router.php   # in the project
 *     php tests/integration/http.php http://127.0.0.1:8611 /path/to/craft-project
 *
 * These are the behaviours only a real request shows: what the response hook
 * writes into each kind of response, what the anonymous endpoints accept and
 * return, and how the rate limit treats forged forwarding headers. The
 * project path is used to toggle settings between checks through the
 * plugin's services; the site must be a disposable one.
 */

$base = rtrim($argv[1] ?? '', '/');
$root = $argv[2] ?? '';

if ($base === '' || !is_file($root . '/bootstrap.php')) {
    fwrite(STDERR, "Usage: php tests/integration/http.php http://127.0.0.1:8611 /path/to/craft-project\n");
    exit(2);
}

require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$plugin = sfsinfotech\craftcookieconsentflow\Plugin::getInstance();

// ---------------------------------------------------------------------------

/**
 * @param array<string, string> $headers
 * @return array{status: int, headers: array<string, string[]>, body: string}
 */
function http(string $method, string $url, array $headers = [], ?string $body = null, ?string $jar = null): array
{
    $ch = curl_init($url);
    $responseHeaders = [];

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => array_map(fn($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))][] = trim($value);
            }
            return strlen($line);
        },
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }

    $content = curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $content];
}

function action(string $route): string
{
    return $GLOBALS['base'] . '/index.php?p=actions/' . $route;
}

/** A fresh anonymous session: cookie jar plus CSRF token. */
/**
 * Empties the rate-limit buckets and, when the current one-minute window is
 * nearly over, waits for the next. Throttle counts in fixed windows, so a
 * loop that straddled a boundary split its requests across two buckets and
 * never reached the limit it was checking.
 */
function freshWindow(): void
{
    if (time() % 60 > 40) {
        sleep(61 - time() % 60);
    }

    Craft::$app->getCache()->flush();
}

function session(): array
{
    $jar  = tempnam(sys_get_temp_dir(), 'ccfjar');
    $info = http('GET', action('users/session-info'), ['Accept' => 'application/json'], null, $jar);

    return [$jar, json_decode($info['body'], true)['csrfTokenValue'] ?? ''];
}

function postJson(string $route, array $payload, array $session, array $headers = []): array
{
    [$jar, $token] = $session;

    return http('POST', action($route), array_merge([
        'Accept'       => 'application/json',
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
    ], $headers), json_encode($payload + ['CRAFT_CSRF_TOKEN' => $token]), $jar);
}

$checks = [];
$check  = function (string $name, callable $fn) use (&$checks) { $checks[] = [$name, $fn]; };

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function settings(array $values): void
{
    $service = sfsinfotech\craftcookieconsentflow\Plugin::getInstance()->cookieSettings;
    $service->clearCache();
    expect($service->saveGlobalSettings($values), 'settings save failed: ' . implode(' ', $service->getValidationErrors()));
}

// ---------------------------------------------------------------------------
// H1 / M7 / L8 — what the response hook writes
// ---------------------------------------------------------------------------

$check('H1: a page with the preferences and reset buttons loads exactly one runtime', function () use ($base) {
    $page = http('GET', $base . '/')['body'];

    expect(substr_count($page, 'cookie-banner.js') === 1, 'runtime script tags: ' . substr_count($page, 'cookie-banner.js'));
    expect(substr_count($page, 'cookie-banner.css') === 1, 'stylesheet links');
    expect(substr_count($page, 'id="cck-config"') === 1, 'configuration blocks');
    expect(str_contains($page, 'data-cck-action="open-preferences"') && str_contains($page, 'data-cck-action="reset-consent"'), 'buttons rendered');
    expect(!str_contains($page, 'window.cckConfig'), 'no executable configuration global');
});

$check('H1: a page placing the banner by hand also loads exactly one runtime', function () use ($base) {
    $page = http('GET', $base . '/manual')['body'];

    expect(substr_count($page, 'cookie-banner.js') === 1, 'runtime script tags: ' . substr_count($page, 'cookie-banner.js'));
    expect(substr_count($page, 'id="cck-config"') === 1, 'configuration blocks');
    expect(substr_count($page, 'id="cck-banner"') === 1, 'one banner');
});

$check('M7: JSON, XML and RSS responses containing </body> are returned untouched', function () use ($base) {
    foreach (['/feed.json' => 'application/json', '/sitemap.xml' => 'application/xml', '/feed.rss' => 'application/rss+xml'] as $path => $type) {
        $response = http('GET', $base . $path);
        expect($response['status'] === 200, "{$path} status {$response['status']}");
        expect(str_starts_with($response['headers']['content-type'][0] ?? '', $type), "{$path} type " . ($response['headers']['content-type'][0] ?? '?'));
        expect(!str_contains($response['body'], 'cck-banner') && !str_contains($response['body'], 'cookie-banner.js'), "{$path} was injected into");
    }

    $json = json_decode(http('GET', $base . '/feed.json')['body'], true);
    expect(($json['html'] ?? null) === '<p>Article</p></body>', 'JSON still parses to the same value');
});

$check('M7: an action returning JSON is not injected into', function () {
    $response = http('GET', action('users/session-info'), ['Accept' => 'application/json']);
    expect(json_decode($response['body'], true) !== null, 'JSON broken');
    expect(!str_contains($response['body'], 'cck-banner'), 'injected');
});

$check('L8: a hostile policy version cannot break the configuration element', function () use ($base) {
    // Validation now refuses this value at save; it is written directly, as
    // a compromised or hand-edited database would, to check the encoding
    // still holds on its own.
    $refused = !sfsinfotech\craftcookieconsentflow\Plugin::getInstance()->cookieSettings->saveGlobalSettings(['policyVersion' => '<!--<script></script>']);
    expect($refused, 'the settings screen accepted a hostile policy version');
    sfsinfotech\craftcookieconsentflow\Plugin::getInstance()->cookieSettings->getGlobalSettingsId();
    Craft::$app->getDb()->createCommand()->update('{{%cookieconsent_settings}}', ['policyVersion' => '<!--<script></script>'], ['siteId' => 0])->execute();

    try {
        $page = http('GET', $base . '/')['body'];
        preg_match('#<script type="application/json" id="cck-config">(.*?)</script>#s', $page, $m);

        expect(isset($m[1]), 'configuration block not found intact');
        expect(!str_contains($m[1], '<'), 'raw < inside the block');
        expect(json_decode($m[1], true)['policyVersion'] === '<!--<script></script>', 'value does not round-trip');
        expect(substr_count($page, 'cookie-banner.js') === 1, 'runtime tag swallowed');
    } finally {
        settings(['policyVersion' => '1']);
    }
});

$check('cache safety: the HTML is identical for every visitor, with no token or country in it', function () use ($base) {
    $a = http('GET', $base . '/', ['CF-IPCountry' => 'DE', 'X-Forwarded-For' => '198.51.100.1'])['body'];
    $b = http('GET', $base . '/', ['CF-IPCountry' => 'US', 'Cookie' => 'cck_visitor_1=11111111-1111-4111-8111-111111111111'])['body'];

    expect($a === $b, 'two visitors were served different HTML');
    expect(!preg_match('/csrfTokenValue|CRAFT_CSRF_TOKEN"\s*:\s*"[^"]+"|name="CRAFT_CSRF_TOKEN"/', $a), 'a CSRF token is in the page');
    expect(!str_contains($a, '"DE"') && !str_contains($a, '"US"'), 'a country is in the page');
});

// ---------------------------------------------------------------------------
// Consent endpoint — L11 / L12 / L14 / L15
// ---------------------------------------------------------------------------

$check('consent: a CSRF-protected save succeeds and returns no visitor identifier', function () {
    $session  = session();
    $response = postJson('cookie-consent-flow/consent/save', ['action' => 'accept_all', 'categories' => ['necessary', 'analytics', 'marketing', 'preferences']], $session);
    $json     = json_decode($response['body'], true);

    expect($response['status'] === 200, 'status ' . $response['status'] . ' ' . $response['body']);
    expect(($json['recorded'] ?? null) === true, 'not recorded');
    expect(!array_key_exists('visitorUuid', $json), 'visitor id exposed to page scripts');

    $cookie = implode("\n", $response['headers']['set-cookie'] ?? []);
    expect(str_contains($cookie, 'cck_visitor_1='), 'no visitor cookie');
    expect(stripos($cookie, 'httponly') !== false, 'visitor cookie not httpOnly');
});

$check('consent: a missing token is refused with invalid_csrf', function () {
    [$jar] = session();
    $response = http('POST', action('cookie-consent-flow/consent/save'), ['Accept' => 'application/json', 'Content-Type' => 'application/json'], '{"action":"accept_all"}', $jar);

    expect($response['status'] === 400 && str_contains($response['body'], 'invalid_csrf'), $response['status'] . ' ' . $response['body']);
});

$check('L12: malformed payloads are 400s with a code, never 500s', function () {
    $session = session();

    $cases = [
        [['action' => ['accept_all']], 'invalid_action'],
        [['action' => 'custom', 'categories' => 'analytics'], 'invalid_categories'],
        [['action' => 'custom', 'categories' => [['nested']]], 'invalid_categories'],
        [['action' => 'custom', 'categories' => [], 'source' => ['x']], 'invalid_source'],
    ];

    foreach ($cases as [$payload, $code]) {
        $response = postJson('cookie-consent-flow/consent/save', $payload, $session);
        expect($response['status'] === 400 && str_contains($response['body'], $code), json_encode($payload) . ' → ' . $response['status'] . ' ' . $response['body']);
        expect(!str_contains($response['body'], 'Array to string'), 'PHP error leaked');
    }

    $names = postJson('cookie-consent-flow/cookie-detection/report', ['names' => [['_ga']]], $session);
    expect($names['status'] === 400 && str_contains($names['body'], 'invalid_names'), 'detection: ' . $names['status'] . ' ' . $names['body']);
});

/*
 * A body that is not JSON at all is parsed by Craft, not by the plugin — and
 * on some requests Craft parses it while the application is still being
 * constructed (`debugBootstrap()` → `User::renewAuthStatus()` reads the
 * `dontExtendSession` body param), before its error handler exists, which
 * answers with a generic 500. Craft's own actions (`users/login`) behave the
 * same way. The plugin cannot intercept that, so what is checked here is the
 * part that matters for an anonymous endpoint: nothing internal is exposed
 * (run with devMode off, as production is).
 */
$check('L12: an unparseable JSON body exposes nothing internal (parsed by Craft core)', function () {
    [$jar, $token] = session();
    $response = http('POST', action('cookie-consent-flow/consent/save'), [
        'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-CSRF-Token' => $token,
    ], '{"action": ', $jar);

    expect(in_array($response['status'], [400, 500], true), 'status ' . $response['status']);
    expect(!str_contains($response['body'], '.php') && !str_contains($response['body'], 'Stack trace'), 'internals in the response');
    echo "       (status {$response['status']})\n";
});

$check('L11: an inconsistent decision is recorded as what was actually sent', function () {
    $response = postJson('cookie-consent-flow/consent/save', ['action' => 'accept_all', 'categories' => []], session());
    $json     = json_decode($response['body'], true);

    expect(($json['action'] ?? null) === 'custom', 'recorded as ' . ($json['action'] ?? '?'));
});

$check('L15: with logging off no visitor cookie is set', function () {
    settings(['logEnabled' => false]);

    try {
        $response = postJson('cookie-consent-flow/consent/save', ['action' => 'reject_all', 'categories' => []], session());
        expect($response['status'] === 200, 'status ' . $response['status']);
        expect(!str_contains(implode("\n", $response['headers']['set-cookie'] ?? []), 'cck_visitor_'), 'visitor cookie set');
        expect(json_decode($response['body'], true)['recorded'] === false, 'reported as recorded');
    } finally {
        settings(['logEnabled' => true]);
    }
});

// ---------------------------------------------------------------------------
// H2 / L21 — rate limits and forged headers
// ---------------------------------------------------------------------------

$check('H2: on Craft\'s default trustedHosts, forged X-Forwarded-For values are capped per proxy address', function () {
    freshWindow();
    $session = session();
    $codes   = [];

    // 210 requests from one socket address, each claiming a new client.
    for ($i = 1; $i <= 210; $i++) {
        $codes[] = postJson('cookie-consent-flow/consent/save', ['action' => 'reject_all'], $session, [
            'X-Forwarded-For' => '198.51.' . intdiv($i, 250) . '.' . ($i % 250),
            'Client-IP'       => "192.0.2.{$i}",
        ])['status'];
    }

    $counts = array_count_values($codes);
    $cap    = 20 * sfsinfotech\craftcookieconsentflow\helpers\Throttle::PEER_MULTIPLIER;
    expect(($counts[200] ?? 0) === $cap && ($counts[429] ?? 0) === 210 - $cap, 'status codes ' . json_encode($counts));
});

$check('H2: one claimed client is still limited to 20 per minute', function () {
    freshWindow();
    $session = session();
    $codes   = [];

    for ($i = 1; $i <= 25; $i++) {
        $codes[] = postJson('cookie-consent-flow/consent/save', ['action' => 'reject_all'], $session, ['X-Forwarded-For' => '198.51.100.77'])['status'];
    }

    $counts = array_count_values($codes);
    expect(($counts[200] ?? 0) === 20 && ($counts[429] ?? 0) === 5, 'status codes ' . json_encode($counts));
});

$check('H2 (final audit): behind a trusted proxy, a spoofed Client-IP does not mint buckets', function () use ($root) {
    $flag = $root . '/trusted-hosts.flag';
    touch($flag);
    freshWindow();

    try {
        $session = session();
        $codes   = [];

        for ($i = 1; $i <= 25; $i++) {
            $codes[] = postJson('cookie-consent-flow/consent/save', ['action' => 'reject_all'], $session, [
                'Client-IP'           => "192.0.2.{$i}",
                'X-Cluster-Client-IP' => "192.0.2.{$i}",
            ])['status'];
        }

        $counts = array_count_values($codes);
        expect(($counts[200] ?? 0) === 20 && ($counts[429] ?? 0) === 5, 'status codes ' . json_encode($counts));

        // …while X-Forwarded-For from the trusted proxy identifies each visitor.
        freshWindow();
        $ok = 0;
        for ($i = 1; $i <= 30; $i++) {
            $ok += postJson('cookie-consent-flow/consent/save', ['action' => 'reject_all'], $session, ['X-Forwarded-For' => "198.51.100.{$i}"])['status'] === 200 ? 1 : 0;
        }
        expect($ok === 30, "real visitors behind the proxy were limited together ({$ok}/30)");
    } finally {
        unlink($flag);
        freshWindow();
    }
});

$check('final: the record carries the policy version the visitor was shown', function () {
    $response = postJson('cookie-consent-flow/consent/save', ['action' => 'reject_all', 'policyVersion' => 'shown-v1'], session());
    expect($response['status'] === 200, 'status ' . $response['status'] . ' ' . $response['body']);

    $version = (new craft\db\Query())->select('policyVersion')->from('{{%cookieconsent_log}}')->orderBy(['id' => SORT_DESC])->scalar();
    expect($version === 'shown-v1', "recorded {$version}");

    $bad = postJson('cookie-consent-flow/consent/save', ['action' => 'reject_all', 'policyVersion' => "<script>"], session());
    expect($bad['status'] === 400 && str_contains($bad['body'], 'invalid_policy_version'), 'malformed version: ' . $bad['status']);
});

$check('final: a geo provider configured through pluginConfigs is actually used', function () use ($root) {
    freshWindow();
    $file = $root . '/config/app.php';
    file_put_contents($file, "<?php\nreturn ['components' => ['plugins' => ['pluginConfigs' => ['cookie-consent-flow' => ['components' => ['geo' => ['providers' => [['class' => sfsinfotech\\craftcookieconsentflow\\geo\\HeaderGeoProvider::class, 'headers' => ['X-Test-Country']]]]]]]]]];\n");
    settings(['geoEnabled' => true, 'geoTargetCountries' => ['DE']]);

    try {
        $json = json_decode(http('GET', action('cookie-consent-flow/consent/geo'), ['Accept' => 'application/json', 'X-Test-Country' => 'US'])['body'], true);
        expect(($json['country'] ?? null) === 'US' && $json['show'] === false, 'override ignored: ' . json_encode($json));
    } finally {
        unlink($file);
        settings(['geoEnabled' => false, 'geoTargetCountries' => []]);
    }
});

$check('final: a script: privacy URL stored directly in the database is never rendered', function () use ($base) {
    $plugin = sfsinfotech\craftcookieconsentflow\Plugin::getInstance();
    Craft::$app->getDb()->createCommand()->update('{{%cookieconsent_settings}}', ['privacyPolicyUrl' => 'javascript:1/alert(document.cookie)'], ['siteId' => 0])->execute();

    try {
        $page = http('GET', $base . '/')['body'];
        expect(!preg_match('/href="\s*javascript:/i', $page), 'javascript: link rendered');
    } finally {
        Craft::$app->getDb()->createCommand()->update('{{%cookieconsent_settings}}', ['privacyPolicyUrl' => ''], ['siteId' => 0])->execute();
        $plugin->cookieSettings->clearCache();
    }
});

$check('L21: the geo endpoint is rate limited and fails open when limited', function () {
    freshWindow();
    $last = null;

    for ($i = 0; $i < 31; $i++) {
        $last = http('GET', action('cookie-consent-flow/consent/geo'), ['Accept' => 'application/json']);
    }

    expect($last['status'] === 429, 'status ' . $last['status']);
    expect(json_decode($last['body'], true)['show'] === true, 'a limited visitor must be shown the banner');
    expect(str_contains(implode(',', $last['headers']['cache-control'] ?? []), 'no-store'), 'cacheable');
    freshWindow();
});

// ---------------------------------------------------------------------------
// M2 — geo trusts only the configured header
// ---------------------------------------------------------------------------

$check('M2: with no trusted header configured, a forged country header is ignored', function () {
    freshWindow();
    settings(['geoEnabled' => true, 'geoTargetCountries' => ['DE']]);

    try {
        foreach (['X-Country-Code', 'CF-IPCountry', 'CloudFront-Viewer-Country'] as $header) {
            $json = json_decode(http('GET', action('cookie-consent-flow/consent/geo'), ['Accept' => 'application/json', $header => 'US'])['body'], true);
            expect($json['show'] === true && $json['country'] === null, "{$header}: " . json_encode($json));
        }
    } finally {
        settings(['geoEnabled' => false, 'geoTargetCountries' => []]);
    }
});

$check('M2 / L29: a header named in config/cookie-consent-flow.php is the only one believed', function () use ($root) {
    freshWindow();
    $file = $root . '/config/cookie-consent-flow.php';
    file_put_contents($file, "<?php\nreturn ['geoCountryHeader' => 'CF-IPCountry', 'bannerHeading' => 'not here'];\n");
    settings(['geoEnabled' => true, 'geoTargetCountries' => ['DE']]);

    try {
        $geo = fn(array $headers) => json_decode(http('GET', action('cookie-consent-flow/consent/geo'), ['Accept' => 'application/json'] + $headers)['body'], true);

        $us = $geo(['CF-IPCountry' => 'US']);
        expect($us['show'] === false && $us['country'] === 'US', 'configured header, untargeted country: ' . json_encode($us));

        $de = $geo(['CF-IPCountry' => 'DE']);
        expect($de['show'] === true && $de['country'] === 'DE', 'configured header, targeted country: ' . json_encode($de));

        $forged = $geo(['X-Country-Code' => 'US']);
        expect($forged['show'] === true && $forged['country'] === null, 'unconfigured header believed: ' . json_encode($forged));

        $both = $geo(['CF-IPCountry' => 'DE', 'X-Country-Code' => 'US']);
        expect($both['show'] === true && $both['country'] === 'DE', 'forged header overrode the real one: ' . json_encode($both));

        // Craft names log files by the install's own timezone, not the system's.
        $log = implode('', array_map('file_get_contents', glob($root . '/storage/logs/web-*.log') ?: []));
        expect(str_contains($log, 'unsupported key(s) bannerHeading'), 'an unsupported config key was not reported');
    } finally {
        unlink($file);
        settings(['geoEnabled' => false, 'geoTargetCountries' => []]);
    }
});

// ---------------------------------------------------------------------------

$failed = 0;

foreach ($checks as [$name, $fn]) {
    try {
        $fn();
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL {$name}\n       " . $e->getMessage() . "\n";
    }
}

echo "\n" . (count($checks) - $failed) . '/' . count($checks) . " HTTP checks passed\n";

exit($failed === 0 ? 0 : 1);
