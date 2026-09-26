<?php

/**
 * Control-panel checks: every page renders, and every save, reset, copy,
 * filter and export flow works, as an admin and as a restricted user.
 *
 *     php tests/integration/cp.php http://127.0.0.1:8611 /path/to/craft-project admin Passw0rd-Test
 *
 * Needs a disposable, multi-site Craft install. A restricted user
 * (`ccf-viewer`, records only) is created if it does not exist.
 */

[$script, $base, $root, $username, $password] = array_pad($argv, 5, '');
$base = rtrim($base, '/');

if ($base === '' || !is_file($root . '/bootstrap.php') || $username === '') {
    fwrite(STDERR, "Usage: php tests/integration/cp.php http://127.0.0.1:8611 /path/to/craft-project admin password\n");
    exit(2);
}

require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

// A records-only user, for the permission checks.
$viewer = craft\elements\User::find()->username('ccf-viewer')->one();
if ($viewer === null) {
    $viewer           = new craft\elements\User();
    $viewer->username = 'ccf-viewer';
    $viewer->email    = 'ccf-viewer@example.test';
    $viewer->newPassword = 'Viewer-Passw0rd';
    Craft::$app->getElements()->saveElement($viewer, false);
}
if (!$viewer->active) {
    Craft::$app->getUsers()->activateUser($viewer);
}
Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accesscp', 'accessplugin-cookie-consent-flow', 'cookieconsentflow:viewlogs']);

$sites  = Craft::$app->getSites()->getAllSites();
$second = null;
foreach ($sites as $site) {
    if (!$site->primary) {
        $second = $site;
    }
}

// ---------------------------------------------------------------------------

final class Browser
{
    private string $jar;
    public string $token = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'ccfcp');
    }

    public function request(string $method, string $path, array $fields = [], array $headers = []): array
    {
        $url = $this->base . '/index.php?p=' . ltrim($path, '/');
        $ch  = curl_init($url);
        $out = [];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_HTTPHEADER     => $headers,
            // Craft ties a logged-in session to the user agent
            // (requireUserAgentAndIpForSession) and refuses a login without one.
            CURLOPT_USERAGENT      => 'ccf-cp-checks',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$out) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $out[strtolower(trim($k))][] = trim($v);
                }
                return strlen($line);
            },
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields + ['CRAFT_CSRF_TOKEN' => $this->token]));
        }

        $body = (string) curl_exec($ch);

        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $body, 'headers' => $out];
    }

    public function login(string $username, string $password): bool
    {
        $info        = json_decode($this->request('GET', 'actions/users/session-info', [], ['Accept: application/json'])['body'], true);
        $this->token = $info['csrfTokenValue'] ?? '';

        $response = $this->request('POST', 'actions/users/login', ['loginName' => $username, 'password' => $password], ['Accept: application/json']);
        $info        = json_decode($this->request('GET', 'actions/users/session-info', [], ['Accept: application/json'])['body'], true);
        $this->token = $info['csrfTokenValue'] ?? $this->token;

        return $response['status'] === 200 && !empty($info['isGuest']) === false;
    }
}

$checks = [];
$check  = function (string $name, callable $fn) use (&$checks) { $checks[] = [$name, $fn]; };

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** A CP page rendered without a Twig or PHP error. */
function page(Browser $b, string $path): string
{
    $response = $b->request('GET', 'admin/' . $path);
    expect($response['status'] === 200, "{$path}: status {$response['status']}");
    expect(!preg_match('/Twig\\\\Error|Twig Runtime Error|Internal Server Error|Stack trace|Unknown column/i', $response['body']), "{$path}: error in page");

    return $response['body'];
}

function notice(array $response): string
{
    foreach ($response['headers']['x-craft-notice'] ?? [] as $n) return $n;
    return '';
}

function reload(): sfsinfotech\craftcookieconsentflow\models\Settings
{
    $service = sfsinfotech\craftcookieconsentflow\Plugin::getInstance()->cookieSettings;
    $service->clearCache();

    return $service->loadSettings();
}

$admin = new Browser($base);
expect($admin->login($username, $password), 'admin login failed');

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------

$check('every CP page renders for an admin', function () use ($admin, $second) {
    $pages = ['cookie-consent-flow', 'cookie-consent-flow/banner', 'cookie-consent-flow/settings', 'cookie-consent-flow/cookies', 'cookie-consent-flow/logs'];
    if ($second !== null) {
        $pages[] = 'cookie-consent-flow/settings/multi-site-override';
    }
    foreach ($pages as $path) {
        page($admin, $path);
    }
});

$check('L22: the Fixed / Sticky switch is no longer offered', function () use ($admin, $second) {
    expect(!str_contains(page($admin, 'cookie-consent-flow/banner'), 'fixedPosition'), 'banner page');
    if ($second !== null) {
        expect(!str_contains(page($admin, 'cookie-consent-flow/settings/multi-site-override'), 'fixedPosition'), 'multisite page');
    }
});

$check('L27: no CP page of the plugin uses inline event handlers', function () use ($admin) {
    foreach (['cookie-consent-flow', 'cookie-consent-flow/logs'] as $path) {
        $html = page($admin, $path);
        preg_match_all('/<(select|button|a|input|form)\b[^>]*\son(change|click|input|submit)=/i', $html, $m);
        expect($m[0] === [], "{$path}: " . implode(' ', $m[0]));
    }
});

$check('M4: inherited multisite fields show the inherited value, not a blank', function () use ($admin, $second) {
    if ($second === null) return;
    $global = reload();
    $html   = page($admin, 'cookie-consent-flow/settings/multi-site-override');

    expect(preg_match('/name="sites\[' . $second->id . '\]\[acceptButtonText\]"[^>]*value="' . preg_quote(htmlspecialchars($global->acceptButtonText, ENT_QUOTES), '/') . '"/', $html)
        || preg_match('/value="' . preg_quote(htmlspecialchars($global->acceptButtonText, ENT_QUOTES), '/') . '"[^>]*name="sites\[' . $second->id . '\]\[acceptButtonText\]"/', $html), 'accept label not prefilled');
});

// ---------------------------------------------------------------------------
// Saves
// ---------------------------------------------------------------------------

$check('settings save, and a refused value names the field', function () use ($admin) {
    $ok = $admin->request('POST', 'admin/actions/cookie-consent-flow/settings/save', [
        'settings' => ['logRetentionDays' => '400', 'consentExpiryDays' => '200'],
    ]);
    expect(in_array($ok['status'], [200, 302], true), 'status ' . $ok['status']);
    expect(reload()->logRetentionDays === 400, 'not saved');

    $bad = $admin->request('POST', 'admin/actions/cookie-consent-flow/settings/save', [
        'settings' => ['bannerHeading' => str_repeat('x', 300)],
    ]);
    expect($bad['status'] === 200 && str_contains($bad['body'], 'bannerHeading'), 'no field-named error for an over-long value');
    expect(reload()->bannerHeading !== str_repeat('x', 300), 'over-long value stored');
});

$check('banner save round-trips', function () use ($admin) {
    $response = $admin->request('POST', 'admin/actions/cookie-consent-flow/settings/save-banner', [
        'settings' => ['bannerHeading' => 'CP heading ✓', 'acceptButtonText' => 'Accept', 'bannerLayout' => 'corner-popup'],
    ]);
    expect(in_array($response['status'], [200, 302], true), 'status ' . $response['status']);
    $s = reload();
    expect($s->bannerHeading === 'CP heading ✓' && $s->bannerLayout === 'corner-popup', 'not saved');

    $admin->request('POST', 'admin/actions/cookie-consent-flow/settings/save-banner', ['settings' => ['bannerLayout' => 'bottom-bar']]);
});

$check('cookies save; an over-long row is refused with its row named', function () use ($admin) {
    $ok = $admin->request('POST', 'admin/actions/cookie-consent-flow/cookies/save', [
        'cookies'  => [['categoryKey' => 'analytics', 'name' => '_ga', 'provider' => 'Google', 'purpose' => 'Stats', 'duration' => '2 years']],
    ]);
    expect(in_array($ok['status'], [200, 302], true), 'status ' . $ok['status']);

    $bad = $admin->request('POST', 'admin/actions/cookie-consent-flow/cookies/save', [
        'cookies' => [['categoryKey' => 'analytics', 'name' => str_repeat('n', 300)]],
    ]);
    expect($bad['status'] === 200 && str_contains($bad['body'], 'Cookie 1'), 'no row-named error');
});

$check('L9: invalidate consent moves to a new, timestamped policy version', function () use ($admin) {
    $before   = reload()->policyVersion;
    $response = $admin->request('POST', 'admin/actions/cookie-consent-flow/settings/invalidate-consent', [], ['Accept: application/json']);
    $json     = json_decode($response['body'], true);

    expect(($json['success'] ?? false) === true, $response['body']);
    expect($json['policyVersion'] !== $before && preg_match('/^\d{4}-\d{2}-\d{2}\.\d{6}(\.\d+)?$/', $json['policyVersion']), 'version ' . $json['policyVersion']);

    $again = json_decode($admin->request('POST', 'admin/actions/cookie-consent-flow/settings/invalidate-consent', [], ['Accept: application/json'])['body'], true);
    expect($again['policyVersion'] !== $json['policyVersion'], 'two invalidations produced the same version');
});

if ($second !== null) {
    $check('multisite: save, copy and reset through the CP', function () use ($admin, $second, $sites) {
        $primary = Craft::$app->getSites()->getPrimarySite();

        $save = $admin->request('POST', 'admin/actions/cookie-consent-flow/settings/save-multi-site-override', [
            'sites'    => [$second->id => ['bannerHeading' => 'Second heading', '__useGlobal' => ['bannerHeading' => '']]],
        ]);
        expect(in_array($save['status'], [200, 302], true), 'save status ' . $save['status']);
        expect(sfsinfotech\craftcookieconsentflow\Plugin::getInstance()->cookieSettings->getEffectiveSettings($second->id)->bannerHeading === 'Second heading' || (reload() && sfsinfotech\craftcookieconsentflow\Plugin::getInstance()->cookieSettings->getEffectiveSettings($second->id)->bannerHeading === 'Second heading'), 'override not saved');

        $copy = $admin->request('POST', 'admin/actions/cookie-consent-flow/settings/copy-multi-site-override', ['fromSiteId' => $second->id, 'toSiteId' => $primary->id], ['Accept: application/json']);
        expect(($json = json_decode($copy['body'], true)) && ($json['success'] ?? false), 'copy: ' . $copy['body']);

        $reset = $admin->request('POST', 'admin/actions/cookie-consent-flow/settings/reset-multi-site-override', ['siteId' => $second->id], ['Accept: application/json']);
        expect(($json = json_decode($reset['body'], true)) && ($json['success'] ?? false), 'reset: ' . $reset['body']);
        $admin->request('POST', 'admin/actions/cookie-consent-flow/settings/reset-multi-site-override', ['siteId' => $primary->id], ['Accept: application/json']);

        reload();
        expect(sfsinfotech\craftcookieconsentflow\Plugin::getInstance()->cookieSettings->findSiteSettingsId($second->id) === null, 'reset left a row');
    });
}

// ---------------------------------------------------------------------------
// Records: filters, detail view, export
// ---------------------------------------------------------------------------

$check('records: filters, detail view (L23) and back link', function () use ($admin) {
    $plugin = sfsinfotech\craftcookieconsentflow\Plugin::getInstance();
    $result = $plugin->consent->saveConsent('custom', ['necessary', 'analytics']);

    $list = page($admin, 'cookie-consent-flow/logs&filter=custom&category=analytics&site=all');
    expect(str_contains($list, (string) $result['visitorUuid']), 'filtered list missing the record');

    $view = page($admin, 'cookie-consent-flow/logs/view/' . $result['recordId'] . '&filter=custom&category=analytics&site=all');
    expect(str_contains($view, 'ccf-action-badge'), 'outcome not shown');
    foreach (['Additional Information', '>Back to Logs<'] as $old) {
        expect(!str_contains($view, $old), "old untranslated string: {$old}");
    }
    expect((bool) preg_match('/href="[^"]*logs[^"]*filter=custom[^"]*"/', html_entity_decode($view)), 'back link lost the filters');
    expect(str_contains($view, 'Analytics'), 'category label, not key');
});

$check('records: CSV and JSON export of the filtered set, behind the export permission', function () use ($admin) {
    $csv = $admin->request('GET', 'admin/cookie-consent-flow/logs/export&format=csv&filter=custom&site=all');
    expect($csv['status'] === 200 && str_contains($csv['headers']['content-type'][0] ?? '', 'csv'), 'csv: ' . $csv['status']);
    expect(!str_contains($csv['body'], 'ipHash') && !str_contains(strtolower($csv['body']), 'useragent'), 'export includes ip hash or user agent');

    $json = $admin->request('GET', 'admin/cookie-consent-flow/logs/export&format=json&filter=custom&site=all');
    $data = json_decode($json['body'], true);
    expect(is_array($data['records'] ?? null) && $data['records'] !== [], 'json export empty');
    foreach ($data['records'] as $record) {
        expect($record['action'] === 'custom', 'export ignored the filter');
    }
});

// ---------------------------------------------------------------------------
// L28 — permissions
// ---------------------------------------------------------------------------

$check('L28: a records-only user sees only Dashboard and Consent Records, and is refused the rest', function () use ($base) {
    $viewer = new Browser($base);
    expect($viewer->login('ccf-viewer', 'Viewer-Passw0rd'), 'viewer login failed');

    $dash = page($viewer, 'cookie-consent-flow/logs');
    preg_match_all('#href="[^"]*(cookie-consent-flow[^"&?]*)"#', $dash, $m);
    $links = array_unique($m[1]);

    foreach (['cookie-consent-flow/banner', 'cookie-consent-flow/cookies', 'cookie-consent-flow/settings'] as $forbidden) {
        expect(!in_array($forbidden, $links, true), "nav shows {$forbidden}");
        $response = $viewer->request('GET', 'admin/' . $forbidden);
        expect($response['status'] === 403, "{$forbidden}: status {$response['status']}");
    }

    expect($viewer->request('GET', 'admin/cookie-consent-flow/logs/export&format=csv')['status'] === 403, 'viewing implied exporting');
});

// ---------------------------------------------------------------------------
// Final audit — per-site access, script URLs, statistics
// ---------------------------------------------------------------------------

if ($second !== null) {
    $primarySite = Craft::$app->getSites()->getPrimarySite();

    // A user with every plugin permission, but Craft access to the primary site only.
    $siteUser = craft\elements\User::find()->username('ccf-site-one')->one();
    if ($siteUser === null) {
        $siteUser              = new craft\elements\User();
        $siteUser->username    = 'ccf-site-one';
        $siteUser->email       = 'ccf-site-one@example.test';
        $siteUser->newPassword = 'SiteOne-Passw0rd';
        Craft::$app->getElements()->saveElement($siteUser, false);
    }
    if (!$siteUser->active) {
        Craft::$app->getUsers()->activateUser($siteUser);
    }
    Craft::$app->getUserPermissions()->saveUserPermissions($siteUser->id, [
        'accesscp', 'accessplugin-cookie-consent-flow', 'editsite:' . $primarySite->uid,
        'cookieconsentflow:managesettings', 'cookieconsentflow:viewlogs', 'cookieconsentflow:exportlogs',
    ]);

    $check('final: a user limited to one site cannot read, export or edit another site', function () use ($base, $second, $primarySite) {
        $plugin = sfsinfotech\craftcookieconsentflow\Plugin::getInstance();
        $sites  = Craft::$app->getSites();
        $sites->setCurrentSite($second);
        $other = $plugin->consent->saveConsent('reject_all', []);
        $sites->setCurrentSite($primarySite);
        $own   = $plugin->consent->saveConsent('reject_all', []);

        $user = new Browser($base);
        expect($user->login('ccf-site-one', 'SiteOne-Passw0rd'), 'login failed');

        expect($user->request('GET', 'admin/cookie-consent-flow/logs&site=' . $second->handle)['status'] === 403, 'other site records visible');
        expect($user->request('GET', 'admin/cookie-consent-flow/logs/view/' . $other['recordId'])['status'] === 403, 'other site record viewable');
        expect($user->request('GET', 'admin/cookie-consent-flow/logs/view/' . $own['recordId'])['status'] === 200, 'own record not viewable');
        expect($user->request('GET', 'admin/cookie-consent-flow/logs/export&format=json&site=' . $second->handle)['status'] === 403, 'other site export allowed');
        expect($user->request('GET', 'admin/cookie-consent-flow&site=' . $second->handle)['status'] === 403, 'other site dashboard allowed');

        $all  = page($user, 'cookie-consent-flow/logs&site=all');
        expect(!str_contains($all, (string) $other['visitorUuid']), '"All sites" listed another site\'s record');
        expect(str_contains($all, (string) $own['visitorUuid']), '"All sites" missed the user\'s own site');

        $export = json_decode($user->request('GET', 'admin/cookie-consent-flow/logs/export&format=json&site=all')['body'], true);
        foreach ($export['records'] ?? [] as $record) {
            expect((int) $record['siteId'] === (int) $primarySite->id, 'export included site ' . $record['siteId']);
        }

        $multisite = page($user, 'cookie-consent-flow/settings/multi-site-override');
        expect(!str_contains($multisite, 'sites[' . $second->id . ']'), 'multisite page offers the other site');

        $save = $user->request('POST', 'admin/actions/cookie-consent-flow/settings/save-multi-site-override', [
            'sites' => [$second->id => ['bannerHeading' => 'Not yours']],
        ]);
        expect($save['status'] === 403, 'saving another site\'s overrides: ' . $save['status']);
        expect($plugin->cookieSettings->findSiteSettingsId($second->id) === null || reload() && $plugin->cookieSettings->getEffectiveSettings($second->id)->bannerHeading !== 'Not yours', 'the override was written');

        $reset = $user->request('POST', 'admin/actions/cookie-consent-flow/settings/reset-multi-site-override', ['siteId' => $second->id], ['Accept: application/json']);
        expect($reset['status'] === 403, 'resetting another site: ' . $reset['status']);
    });
}

$check('final: a script: privacy URL is refused on save, naming the field', function () use ($admin) {
    $response = $admin->request('POST', 'admin/actions/cookie-consent-flow/settings/save-banner', [
        'settings' => ['privacyPolicyUrl' => 'javascript:1/alert(document.cookie)'],
    ]);

    expect($response['status'] === 200 && str_contains($response['body'], 'privacyPolicyUrl'), 'not refused with a field-named error');
    expect(reload()->privacyPolicyUrl !== 'javascript:1/alert(document.cookie)', 'stored');
});

$check('final: Consent Records shows acceptance by category, and no daily trend', function () use ($admin) {
    sfsinfotech\craftcookieconsentflow\Plugin::getInstance()->consent->saveConsent('custom', ['necessary', 'analytics']);
    $html = page($admin, 'cookie-consent-flow/logs&site=all');

    expect(str_contains($html, 'Acceptance by category'), 'category rates missing');
    expect(!str_contains($html, 'Daily records') && !str_contains($html, 'ccf-trend-table'), 'daily trend still shown');
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

echo "\n" . (count($checks) - $failed) . '/' . count($checks) . " control-panel checks passed\n";
exit($failed === 0 ? 0 : 1);
