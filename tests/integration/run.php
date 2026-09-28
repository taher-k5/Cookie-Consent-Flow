<?php

/**
 * Database integration checks, run against a real Craft installation.
 *
 *     php tests/integration/run.php /path/to/craft-project
 *
 * The unit suite covers everything decidable without a database. What it
 * cannot see is how the plugin behaves against the two supported drivers:
 * whether a value the validator accepts actually fits its column on
 * PostgreSQL, whether a case-sensitive filter is case-sensitive on MySQL,
 * whether an upsert holds up, whether the consent event really fires after
 * the row exists. Those are checked here, against the project's own database,
 * through the plugin's own services.
 *
 * Every check runs inside a transaction that is rolled back afterwards, so
 * the database is left exactly as it was found (the one exception, the site
 * lifecycle check, creates and deletes a real site and says so). Use a
 * disposable database anyway: this is a test harness, not a health check for
 * production.
 *
 * Exit status is 0 only when every check passes.
 */

use craft\db\Query;
use craft\events\DeleteSiteEvent;
use craft\helpers\Db;
use craft\models\Site;
use craft\services\Gc;
use craft\services\Sites;
use sfsinfotech\craftcookieconsentflow\helpers\PluginConfig;
use sfsinfotech\craftcookieconsentflow\helpers\Throttle;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\records\ConsentLogRecord;
use sfsinfotech\craftcookieconsentflow\services\ConsentService;
use yii\base\Event;

$root = $argv[1] ?? getenv('CCF_CRAFT_PATH') ?: '';

if ($root === '' || !is_file($root . '/bootstrap.php')) {
    fwrite(STDERR, "Usage: php tests/integration/run.php /path/to/craft-project\n");
    exit(2);
}

require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$plugin = Plugin::getInstance();

if ($plugin === null) {
    fwrite(STDERR, "Cookie Consent Flow is not installed in {$root}.\n");
    exit(2);
}

$db     = Craft::$app->getDb();
$driver = $db->getDriverName();

echo "Cookie Consent Flow integration checks — {$driver} " . $db->getServerVersion() . ", schema "
    . Craft::$app->getPlugins()->getStoredPluginInfo('cookie-consent-flow')['schemaVersion'] . "\n\n";

// ---------------------------------------------------------------------------
// A very small harness
// ---------------------------------------------------------------------------

$checks = [];
$check  = static function (string $name, callable $fn, bool $transactional = true) use (&$checks): void {
    $checks[] = [$name, $fn, $transactional];
};

final class CheckFailed extends RuntimeException {}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new CheckFailed($message);
    }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    expect($expected === $actual, $message . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** Resets the plugin's request caches, as a new request would. */
function fresh(): void
{
    Plugin::getInstance()->cookieSettings->clearCache();
}

function insertLog(array $attributes): int
{
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%cookieconsent_log}}', array_merge([
        'visitorUuid'   => craft\helpers\StringHelper::UUID(),
        'ipHash'        => str_repeat('0', 64),
        'siteId'        => Craft::$app->getSites()->getPrimarySite()->id,
        'categories'    => '["necessary"]',
        'action'        => 'reject_all',
        'policyVersion' => '1',
        'source'        => 'banner',
        'userAgent'     => '',
        'dateCreated'   => $now,
        'dateUpdated'   => $now,
        'uid'           => craft\helpers\StringHelper::UUID(),
    ], $attributes))->execute();

    return (int) Craft::$app->getDb()->getLastInsertID();
}

function logCount(array $where = []): int
{
    return (int) (new Query())->from('{{%cookieconsent_log}}')->where($where)->count();
}

$primary = Craft::$app->getSites()->getPrimarySite();

// ---------------------------------------------------------------------------
// Schema (M9, L10, L16, L22)
// ---------------------------------------------------------------------------

$check('schema: every table exists', function () use ($db) {
    foreach (['cookieconsent_settings', 'cookieconsent_category', 'cookieconsent_cookie', 'cookieconsent_detected_cookie', 'cookieconsent_log'] as $table) {
        expect($db->tableExists("{{%{$table}}}"), "{$table} is missing");
    }
});

$check('schema: detected cookies have lastSeen; the retired fixedPosition column is gone', function () use ($db) {
    expect($db->columnExists('{{%cookieconsent_detected_cookie}}', 'lastSeen'), 'lastSeen missing');
    expect(!$db->columnExists('{{%cookieconsent_settings}}', 'fixedPosition'), 'fixedPosition still present');
});

$check('schema: both cascading foreign keys exist', function () use ($db) {
    foreach (['cookieconsent_category', 'cookieconsent_cookie'] as $table) {
        $fks = $db->getSchema()->getTableForeignKeys("{{%{$table}}}", true);
        expect(count(array_filter($fks, fn($fk) => $fk->columnNames === ['settingsId'])) === 1, "{$table}.settingsId FK");
    }
});

$check('schema: installed schema version matches the code', function () use ($plugin) {
    same($plugin->schemaVersion, Craft::$app->getPlugins()->getStoredPluginInfo('cookie-consent-flow')['schemaVersion'], 'stored schema version');
    expect(!Craft::$app->getPlugins()->isPluginUpdatePending($plugin), 'no migration pending');
});

$check('schema: project config schema versions are compatible', function () {
    $issues = [];
    expect(Craft::$app->getProjectConfig()->getAreConfigSchemaVersionsCompatible($issues), 'incompatible: ' . json_encode($issues));
});

// ---------------------------------------------------------------------------
// M3 — the consent event follows a committed record
// ---------------------------------------------------------------------------

$check('M3: the event fires after the record exists, carrying its id', function () use ($plugin) {
    $seen    = null;
    $handler = function ($event) use (&$seen) {
        $seen = ['recordId' => $event->recordId, 'rowsAtEvent' => logCount(['id' => $event->recordId])];
    };

    Event::on(Plugin::class, Plugin::EVENT_AFTER_CONSENT_SAVE, $handler);

    try {
        $result = $plugin->consent->saveConsent('accept_all', ['necessary', 'analytics', 'marketing', 'preferences']);
    } finally {
        Event::off(Plugin::class, Plugin::EVENT_AFTER_CONSENT_SAVE, $handler);
    }

    expect($seen !== null, 'event did not fire');
    same($result['recordId'], $seen['recordId'], 'event record id');
    same(1, $seen['rowsAtEvent'], 'the record was already in the table when listeners ran');
    same(true, $result['recorded'], 'recorded flag');
});

$check('M3: a throwing listener cannot lose the record or fail the save', function () use ($plugin) {
    $before  = logCount();
    $handler = function () {
        throw new RuntimeException('listener exploded');
    };

    Event::on(Plugin::class, Plugin::EVENT_AFTER_CONSENT_SAVE, $handler);

    try {
        $result = $plugin->consent->saveConsent('reject_all', []);
    } finally {
        Event::off(Plugin::class, Plugin::EVENT_AFTER_CONSENT_SAVE, $handler);
    }

    same(true, $result['recorded'], 'still recorded');
    same($before + 1, logCount(), 'the record exists');
});

$check('M3: a failed save throws and announces nothing', function () use ($plugin) {
    $fired    = false;
    $listener = function () use (&$fired) { $fired = true; };
    $veto     = function ($event) { $event->isValid = false; };

    Event::on(Plugin::class, Plugin::EVENT_AFTER_CONSENT_SAVE, $listener);
    Event::on(ConsentLogRecord::class, ConsentLogRecord::EVENT_BEFORE_INSERT, $veto);

    $threw = false;
    try {
        $plugin->consent->saveConsent('custom', ['analytics']);
    } catch (RuntimeException) {
        $threw = true;
    } finally {
        Event::off(Plugin::class, Plugin::EVENT_AFTER_CONSENT_SAVE, $listener);
        Event::off(ConsentLogRecord::class, ConsentLogRecord::EVENT_BEFORE_INSERT, $veto);
    }

    expect($threw, 'a save that wrote nothing must not report success');
    expect(!$fired, 'no event for a save that failed');
});

$check('M3 / L15: with logging disabled nothing is written, announced or identified', function () use ($plugin) {
    expect($plugin->cookieSettings->saveGlobalSettings(['logEnabled' => false]), 'could not disable logging');
    fresh();

    $fired    = false;
    $listener = function () use (&$fired) { $fired = true; };
    Event::on(Plugin::class, Plugin::EVENT_AFTER_CONSENT_SAVE, $listener);

    $before = logCount();
    try {
        $result = $plugin->consent->saveConsent('accept_all', ['necessary', 'analytics', 'marketing', 'preferences']);
    } finally {
        Event::off(Plugin::class, Plugin::EVENT_AFTER_CONSENT_SAVE, $listener);
    }

    same($before, logCount(), 'no record');
    same(false, $result['recorded'], 'recorded flag');
    same(null, $result['visitorUuid'], 'no visitor identifier minted');
    expect(!$fired, 'no event without a record');
});

$check('M3: statistics are invalidated by the save', function () use ($plugin) {
    $before = $plugin->statistics->getActionCounts($GLOBALS['primary']->id)['total'];
    $plugin->consent->saveConsent('reject_all', []);

    same($before + 1, $plugin->statistics->getActionCounts($GLOBALS['primary']->id)['total'], 'fresh count after save');
});

// ---------------------------------------------------------------------------
// L11 / L13 — what a record contains
// ---------------------------------------------------------------------------

$check('L11: the stored record is normalised against the site configuration', function () use ($plugin) {
    $result = $plugin->consent->saveConsent('accept_all', ['necessary', 'ghost']);
    $row    = (new Query())->from('{{%cookieconsent_log}}')->where(['id' => $result['recordId']])->one();

    same('custom', $row['action'], 'accept_all without the optional categories is not stored as accept_all');
    same('["necessary"]', $row['categories'], 'unknown keys dropped');
});

$check('L13: IP hashes are keyed, 64 hex characters, and never the address', function () use ($plugin) {
    $result = $plugin->consent->saveConsent('reject_all', []);
    $hash   = (new Query())->select('ipHash')->from('{{%cookieconsent_log}}')->where(['id' => $result['recordId']])->scalar();

    expect((bool) preg_match('/^[0-9a-f]{64}$/', (string) $hash), "unexpected hash {$hash}");
});

// ---------------------------------------------------------------------------
// M8 — values the validator accepts fit their columns on this driver
// ---------------------------------------------------------------------------

$check('M8: 255-character and 255-emoji values save and round-trip', function () use ($plugin) {
    foreach ([str_repeat('h', 255), str_repeat('🍪', 255), str_repeat('中', 255)] as $value) {
        expect($plugin->cookieSettings->saveGlobalSettings(['bannerHeading' => $value, 'acceptButtonText' => $value]), 'save refused: ' . implode(' ', $plugin->cookieSettings->getValidationErrors()));
        fresh();
        same($value, $plugin->cookieSettings->loadSettings()->bannerHeading, 'heading round-trip');
    }
});

$check('M8: 256 characters are refused by validation, naming the field', function () use ($plugin) {
    expect(!$plugin->cookieSettings->saveGlobalSettings(['privacyPolicyUrl' => str_repeat('u', 256)]), 'accepted 256');
    expect(str_contains(implode(' ', $plugin->cookieSettings->getValidationErrors()), 'privacyPolicyUrl'), 'field not named');
});

$check('M8: a 16,000-emoji description fits the TEXT column', function () use ($plugin) {
    $value = str_repeat('🍪', 16000);
    expect($plugin->cookieSettings->saveGlobalSettings(['bannerDescription' => $value]), 'refused');
    fresh();
    same($value, $plugin->cookieSettings->loadSettings()->bannerDescription, 'round-trip');
});

$check('M8: category and cookie values at their limits save; beyond them are refused with messages', function () use ($plugin) {
    $categories = [
        ['key' => str_repeat('k', 100), 'label' => str_repeat('🍪', 255), 'description' => 'x', 'locked' => '1'],
    ];
    expect($plugin->cookieSettings->saveGlobalSettings(['categories' => $categories]), 'category at limit refused');

    expect(!$plugin->cookieSettings->saveGlobalSettings(['categories' => [['key' => 'a', 'label' => str_repeat('l', 256)]]]), 'category label 256 accepted');

    $settingsId = $plugin->cookieSettings->getGlobalSettingsId();
    expect($plugin->cookieDefinitions->saveAll($settingsId, [['name' => str_repeat('n', 255), 'provider' => str_repeat('p', 255), 'duration' => str_repeat('d', 100), 'categoryKey' => 'analytics']]), 'cookie at limit refused');
    expect(!$plugin->cookieDefinitions->saveAll($settingsId, [['name' => str_repeat('n', 256)]]), 'cookie name 256 accepted');
    expect($plugin->cookieDefinitions->getValidationErrors() !== [], 'no message');
});

$check('M4 / M8: a blank button label is refused rather than stored', function () use ($plugin) {
    expect(!$plugin->cookieSettings->saveGlobalSettings(['acceptButtonText' => '']), 'blank accepted');
    expect(!$plugin->cookieSettings->saveGlobalSettings(['rejectButtonText' => '   ']), 'whitespace accepted');
});

// ---------------------------------------------------------------------------
// L16 — case-sensitivity is the same on both drivers
// ---------------------------------------------------------------------------

$check('L16: category filtering is exact and case-sensitive', function () use ($plugin) {
    // A site id no real record uses, so existing data cannot affect the counts.
    $site = 876543;
    insertLog(['categories' => '["necessary","analytics"]', 'siteId' => $site]);
    insertLog(['categories' => '["necessary","Analytics"]', 'siteId' => $site]);
    insertLog(['categories' => '["necessary","adXstorage"]', 'siteId' => $site]);
    insertLog(['categories' => '["necessary","ad_storage"]', 'siteId' => $site]);

    same(1, (int) $plugin->consent->buildQuery($site, ['category' => 'analytics'])->count(), 'analytics');
    same(1, (int) $plugin->consent->buildQuery($site, ['category' => 'Analytics'])->count(), 'Analytics');
    same(1, (int) $plugin->consent->buildQuery($site, ['category' => 'ad_storage'])->count(), '`_` is not a wildcard');
    same(0, (int) $plugin->consent->buildQuery($site, ['category' => 'analytic'])->count(), 'no substring match');
    same(0, (int) $plugin->consent->buildQuery($site, ['category' => 'bad key'])->count(), 'impossible key');
    same(1, $plugin->consent->getStats($site, ['category' => 'analytics'])['total'], 'stats agree');
});

$check('L16: category keys differing only in case are refused on this driver too', function () use ($plugin) {
    expect(!$plugin->cookieSettings->saveGlobalSettings(['categories' => [
        ['key' => 'analytics', 'label' => 'A', 'locked' => '1'],
        ['key' => 'Analytics', 'label' => 'B'],
    ]]), 'accepted a case-only duplicate');
});

$check('L16 / L10: detected cookie names are case-sensitive', function () use ($plugin) {
    $site = $GLOBALS['primary']->id;
    $plugin->cookieDefinitions->recordDetected(['_ccfCase', '_CCFCASE'], $site);

    same(2, (int) (new Query())->from('{{%cookieconsent_detected_cookie}}')->where(['siteId' => $site])->andWhere(['in', 'name', ['_ccfCase', '_CCFCASE']])->count(), 'two distinct cookies');
});

// ---------------------------------------------------------------------------
// L10 — first seen / last seen
// ---------------------------------------------------------------------------

$check('L10: a re-sighted cookie keeps its first sighting and moves its last', function () use ($plugin) {
    $site  = $GLOBALS['primary']->id;
    $table = '{{%cookieconsent_detected_cookie}}';

    $plugin->cookieDefinitions->recordDetected(['_ccfSeen'], $site);
    // Age the row, as though first seen yesterday.
    $yesterday = Db::prepareDateForDb(new DateTime('-1 day'));
    Craft::$app->getDb()->createCommand()->update($table, ['dateCreated' => $yesterday, 'lastSeen' => $yesterday], ['name' => '_ccfSeen'])->execute();
    Craft::$app->getDb()->createCommand()->update($table, ['isDismissed' => true], ['name' => '_ccfSeen'])->execute();

    $plugin->cookieDefinitions->recordDetected(['_ccfSeen'], $site);
    $row = (new Query())->from($table)->where(['name' => '_ccfSeen', 'siteId' => $site])->one();

    same(1, (int) (new Query())->from($table)->where(['name' => '_ccfSeen', 'siteId' => $site])->count(), 'one row');
    same(substr($yesterday, 0, 16), substr((string) $row['dateCreated'], 0, 16), 'first seen unchanged');
    expect(strtotime((string) $row['lastSeen']) > strtotime($yesterday) + 3600, 'last seen moved to now');
    expect((bool) $row['isDismissed'], 'a dismissal survives re-sighting');
});

$check('L10: reporting a name that already exists (a concurrent first report) does not fail the batch', function () use ($plugin) {
    $site = $GLOBALS['primary']->id;
    Craft::$app->getDb()->createCommand()->insert('{{%cookieconsent_detected_cookie}}', [
        'siteId' => $site, 'name' => '_ccfRace', 'isDismissed' => false,
        'dateCreated' => Db::prepareDateForDb(new DateTime()), 'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        'uid' => craft\helpers\StringHelper::UUID(),
    ])->execute();

    $plugin->cookieDefinitions->recordDetected(['_ccfRace', '_ccfAfterRace'], $site);

    same(1, (int) (new Query())->from('{{%cookieconsent_detected_cookie}}')->where(['name' => '_ccfAfterRace'])->count(), 'the rest of the batch was recorded');
});

// ---------------------------------------------------------------------------
// L19 / L20 — retention
// ---------------------------------------------------------------------------

$check('L19: retention purges in batches, keeping recent records', function () use ($plugin) {
    PluginConfig::override(['retentionBatchSize' => 700]);

    try {
        $old = Db::prepareDateForDb(new DateTime('-400 days'));
        $new = Db::prepareDateForDb(new DateTime('-364 days'));

        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = [craft\helpers\StringHelper::UUID(), str_repeat('0', 64), $GLOBALS['primary']->id, '["necessary"]', 'reject_all', '1', 'banner', '', $old, $old, craft\helpers\StringHelper::UUID()];
        }
        Craft::$app->getDb()->createCommand()->batchInsert('{{%cookieconsent_log}}',
            ['visitorUuid', 'ipHash', 'siteId', 'categories', 'action', 'policyVersion', 'source', 'userAgent', 'dateCreated', 'dateUpdated', 'uid'],
            $rows
        )->execute();
        $keep = insertLog(['dateCreated' => $new, 'dateUpdated' => $new]);

        $before  = logCount();
        $deleted = $plugin->consent->purgeOldLogs(365);

        expect($deleted >= 2500, "deleted {$deleted}");
        same($before - $deleted, logCount(), 'count consistent');
        same(1, logCount(['id' => $keep]), 'the 364-day record survives');
        same(0, (int) (new Query())->from('{{%cookieconsent_log}}')->where(['<', 'dateCreated', ConsentService::retentionCutoff(365)])->count(), 'nothing past retention remains');
    } finally {
        PluginConfig::override(null);
    }
});

$check('L20: garbage collection purges only when automaticRetention is on', function () use ($plugin) {
    expect($plugin->cookieSettings->saveGlobalSettings(['logRetentionDays' => 30]), 'set retention');
    fresh();

    $old = Db::prepareDateForDb(new DateTime('-60 days'));
    $id  = insertLog(['dateCreated' => $old, 'dateUpdated' => $old]);

    try {
        PluginConfig::override(['automaticRetention' => false]);
        Craft::$app->getGc()->trigger(Gc::EVENT_RUN);
        same(1, logCount(['id' => $id]), 'kept while off');

        PluginConfig::override(['automaticRetention' => true]);
        Craft::$app->getGc()->trigger(Gc::EVENT_RUN);
        same(0, logCount(['id' => $id]), 'purged when on');
    } finally {
        PluginConfig::override(null);
    }
});

// ---------------------------------------------------------------------------
// M4 / multisite
// ---------------------------------------------------------------------------

$second = null;
foreach (Craft::$app->getSites()->getAllSites() as $site) {
    if (!$site->primary) {
        $second = $site;
        break;
    }
}

if ($second !== null) {
    $check('multisite: a site inherits, overrides, and resets', function () use ($plugin, $second) {
        $service = $plugin->cookieSettings;
        $global  = $service->loadSettings()->acceptButtonText;

        expect($service->saveSiteOverrides($second->id, ['acceptButtonText' => 'Site accept'], []), 'override');
        fresh();
        same('Site accept', $service->getEffectiveSettings($second->id)->acceptButtonText, 'override applies to its site');
        same($global, $service->getEffectiveSettings($GLOBALS['primary']->id)->acceptButtonText, 'and not to the other');

        expect($service->saveSiteOverrides($second->id, ['acceptButtonText' => 'ignored'], ['acceptButtonText' => '1']), 'use global');
        fresh();
        same($global, $service->getEffectiveSettings($second->id)->acceptButtonText, 'use global restores inheritance');

        expect($service->saveSiteOverrides($second->id, ['bannerHeading' => 'Site heading'], []), 'override 2');
        expect($service->resetSiteOverrides($second->id), 'reset');
        fresh();
        same(null, $service->findSiteSettingsId($second->id), 'reset leaves no row');
    });

    $check('M4: an emptied number override inherits instead of saving 0', function () use ($plugin, $second) {
        $service = $plugin->cookieSettings;
        expect($service->saveGlobalSettings(['consentModeWaitForUpdate' => 750]), 'global');
        expect($service->saveSiteOverrides($second->id, ['consentModeWaitForUpdate' => ''], []), 'site');
        fresh();

        same(750, $service->getEffectiveSettings($second->id)->consentModeWaitForUpdate, 'inherits the global value');
    });

    $check('M4: an override saved from the prefilled (inherited) values changes nothing visible', function () use ($plugin, $second) {
        $service  = $plugin->cookieSettings;
        $global   = $service->loadSettings();
        $posted   = [];
        foreach (['acceptButtonText', 'rejectButtonText', 'customizeButtonText', 'savePreferencesText', 'closeButtonText', 'bannerHeading'] as $field) {
            $posted[$field] = $global->getFieldDisplayValue($field);
        }

        expect($service->saveSiteOverrides($second->id, $posted, []), 'save: ' . implode(' ', $service->getValidationErrors()));
        fresh();

        foreach ($posted as $field => $value) {
            same($value, $service->getEffectiveSettings($second->id)->$field, $field);
        }
    });

    $check('M4: a blank label override is refused', function () use ($plugin, $second) {
        expect(!$plugin->cookieSettings->saveSiteOverrides($second->id, ['acceptButtonText' => ''], []), 'blank accepted');
    });

    $check('multisite: copy from another site replaces the target', function () use ($plugin, $second) {
        $service = $plugin->cookieSettings;
        expect($service->saveSiteOverrides($GLOBALS['primary']->id, ['bannerHeading' => 'Primary heading'], []), 'source');
        expect($service->copySiteOverrides($GLOBALS['primary']->id, $second->id), 'copy');
        fresh();
        same('Primary heading', $service->getEffectiveSettings($second->id)->bannerHeading, 'copied');
    });

    $check('multisite: consent records and categories are per site', function () use ($plugin, $second) {
        $service = $plugin->cookieSettings;
        $cats    = [
            ['key' => 'necessary', 'label' => 'Necessary', 'locked' => '1'],
            ['key' => 'uk_only', 'label' => 'UK only'],
        ];
        expect($service->saveSiteOverrides($second->id, ['categories' => $cats], []), 'site categories');
        fresh();

        $sites   = Craft::$app->getSites();
        $current = $sites->getCurrentSite();
        $sites->setCurrentSite($second);

        try {
            $result = $plugin->consent->saveConsent('accept_all', ['necessary', 'uk_only']);
        } finally {
            $sites->setCurrentSite($current);
        }

        $row = (new Query())->from('{{%cookieconsent_log}}')->where(['id' => $result['recordId']])->one();
        same((int) $second->id, (int) $row['siteId'], 'recorded against the second site');
        same('["necessary","uk_only"]', $row['categories'], "the second site's categories");
        expect(!in_array('uk_only', $service->getEffectiveSettings($GLOBALS['primary']->id)->getCategoryKeys(), true), 'not leaked to the primary site');
    });
}

// ---------------------------------------------------------------------------
// L17 — deleted-site cleanup
// ---------------------------------------------------------------------------

$check('L17: deleting a site removes its configuration and inventory, keeps its records', function () use ($plugin) {
    $fakeSiteId = 987654;
    $now        = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%cookieconsent_settings}}', [
        'siteId' => $fakeSiteId, 'isGlobal' => false, 'bannerHeading' => 'Doomed',
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => craft\helpers\StringHelper::UUID(),
    ])->execute();
    $settingsId = (int) Craft::$app->getDb()->getLastInsertID();

    Craft::$app->getDb()->createCommand()->insert('{{%cookieconsent_category}}', [
        'settingsId' => $settingsId, 'key' => 'x', 'label' => 'X', 'isDefault' => false, 'isLocked' => false,
        'sortOrder' => 0, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => craft\helpers\StringHelper::UUID(),
    ])->execute();
    Craft::$app->getDb()->createCommand()->insert('{{%cookieconsent_cookie}}', [
        'settingsId' => $settingsId, 'categoryKey' => 'x', 'name' => '_x', 'sortOrder' => 0,
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => craft\helpers\StringHelper::UUID(),
    ])->execute();
    $plugin->cookieDefinitions->recordDetected(['_doomed'], $fakeSiteId);
    $logId = insertLog(['siteId' => $fakeSiteId]);

    $site     = new Site(['id' => $fakeSiteId, 'name' => 'Doomed', 'handle' => 'doomed', 'language' => 'en-US']);
    Craft::$app->getSites()->trigger(Sites::EVENT_AFTER_DELETE_SITE, new DeleteSiteEvent(['site' => $site]));

    same(0, (int) (new Query())->from('{{%cookieconsent_settings}}')->where(['siteId' => $fakeSiteId])->count(), 'settings row');
    same(0, (int) (new Query())->from('{{%cookieconsent_category}}')->where(['settingsId' => $settingsId])->count(), 'categories');
    same(0, (int) (new Query())->from('{{%cookieconsent_cookie}}')->where(['settingsId' => $settingsId])->count(), 'cookies');
    same(0, (int) (new Query())->from('{{%cookieconsent_detected_cookie}}')->where(['siteId' => $fakeSiteId])->count(), 'detected cookies');
    same(1, logCount(['id' => $logId]), 'consent evidence is kept');
});

$check('L17: a real site deletion through Craft runs the cleanup (creates and deletes a site)', function () use ($plugin) {
    $sites = Craft::$app->getSites();
    $site  = new Site([
        'groupId'  => $sites->getPrimarySite()->groupId,
        'name'     => 'CCF temporary',
        'handle'   => 'ccfTemporary' . random_int(1000, 9999),
        'language' => 'en-US',
        'hasUrls'  => false,
    ]);

    expect($sites->saveSite($site), 'could not create the temporary site: ' . json_encode($site->getErrors()));

    try {
        expect($plugin->cookieSettings->saveSiteOverrides($site->id, ['bannerHeading' => 'Temporary'], []), 'override');
        $plugin->cookieDefinitions->recordDetected(['_temporary'], $site->id);
        $logId = insertLog(['siteId' => $site->id]);

        expect($sites->deleteSite($site), 'could not delete the temporary site');
        fresh();

        same(null, $plugin->cookieSettings->findSiteSettingsId($site->id), 'settings row removed');
        same(0, (int) (new Query())->from('{{%cookieconsent_detected_cookie}}')->where(['siteId' => $site->id])->count(), 'inventory removed');
        same(1, logCount(['id' => $logId]), 'evidence kept');

        Craft::$app->getDb()->createCommand()->delete('{{%cookieconsent_log}}', ['id' => $logId])->execute();
    } finally {
        if ($sites->getSiteById($site->id, true) !== null) {
            $sites->deleteSite($site);
        }
    }
}, false);

// ---------------------------------------------------------------------------
// Final audit
// ---------------------------------------------------------------------------

$check('final: detected cookie names are capped per site; known names still update', function () use ($plugin) {
    $site  = 765432;
    $now   = Db::prepareDateForDb(new DateTime('-2 days'));
    $rows  = [];
    for ($i = 0; $i < sfsinfotech\craftcookieconsentflow\services\CookieDefinitionService::MAX_DETECTED_PER_SITE; $i++) {
        $rows[] = [$site, "_cap{$i}", false, $now, $now, $now, craft\helpers\StringHelper::UUID()];
    }
    Craft::$app->getDb()->createCommand()->batchInsert('{{%cookieconsent_detected_cookie}}', ['siteId', 'name', 'isDismissed', 'lastSeen', 'dateCreated', 'dateUpdated', 'uid'], $rows)->execute();

    $plugin->cookieDefinitions->recordDetected(['_brandNew', '_cap5'], $site);

    same(0, (int) (new Query())->from('{{%cookieconsent_detected_cookie}}')->where(['siteId' => $site, 'name' => '_brandNew'])->count(), 'no new names past the cap');
    $seen = (new Query())->select('lastSeen')->from('{{%cookieconsent_detected_cookie}}')->where(['siteId' => $site, 'name' => '_cap5'])->scalar();
    expect(strtotime((string) $seen) > strtotime($now) + 3600, 'a known name still moves its lastSeen');
});

$check('final: category statistics are counted in SQL, exactly, on this driver', function () use ($plugin) {
    $site = 654321;
    insertLog(['siteId' => $site, 'categories' => '["necessary","analytics"]']);
    insertLog(['siteId' => $site, 'categories' => '["necessary","Analytics"]']);
    insertLog(['siteId' => $site, 'categories' => '["necessary","analytics_extra"]']);
    insertLog(['siteId' => $site, 'categories' => '["necessary"]']);

    $stats = $plugin->consent->getCategoryStats($site, ['necessary', 'analytics'], []);
    same(4, $stats['necessary']['count'], 'necessary');
    same(1, $stats['analytics']['count'], 'analytics — not Analytics, not analytics_extra');
    same(25.0, (float) $stats['analytics']['percent'], 'percent');

    same(1, $plugin->consent->getCategoryStats($site, ['analytics'], ['action' => 'reject_all'])['analytics']['count'], 'filters apply');
});

if ($second !== null) {
    $check('final: a site can override the logo with "no logo"', function () use ($plugin, $second) {
        $service = $plugin->cookieSettings;
        expect($service->saveGlobalSettings(['logoAssetId' => ['987']]), 'global logo');
        expect($service->saveSiteOverrides($second->id, ['logoAssetId' => ''], []), 'site no-logo');
        fresh();

        same(987, $service->getEffectiveSettings($GLOBALS['primary']->id)->logoAssetId, 'global keeps its logo');
        same(0, $service->getEffectiveSettings($second->id)->logoAssetId, 'the site has none, not the inherited one');
        expect($service->loadSettings()->isFieldOverridden($second->id, 'logoAssetId'), 'shown as overridden');
    });

    $check('release: an emptied site cookie or category list is removed, not kept', function () use ($plugin, $second) {
        $service  = $plugin->cookieSettings;
        $cookies  = $plugin->cookieDefinitions;
        $primary  = $GLOBALS['primary'];
        $cats     = [['key' => 'necessary', 'label' => 'Necessary', 'locked' => '1'], ['key' => 'site_only', 'label' => 'Site only']];
        $rows     = [
            ['categoryKey' => 'necessary', 'name' => 'ccf_one', 'provider' => 'Test', 'purpose' => 'One', 'duration' => '1 day'],
            ['categoryKey' => 'necessary', 'name' => 'ccf_two', 'provider' => 'Test', 'purpose' => 'Two', 'duration' => '1 day'],
        ];

        // Both sites override both lists.
        foreach ([$primary, $second] as $site) {
            expect($service->saveSiteOverrides($site->id, ['categories' => $cats, 'cookies' => $rows], []), 'seed ' . $site->handle . ': ' . implode(' ', $service->getValidationErrors()));
        }
        fresh();
        $settingsId = static fn(int $siteId): int => (int) $service->findSiteSettingsId($siteId);
        $count      = static fn(string $table, int $siteId): int => (int) (new Query())->from($table)->where(['settingsId' => $settingsId($siteId)])->count();
        same(2, $count('{{%cookieconsent_cookie}}', $second->id), 'seeded cookies');

        // Remove one: what the form posts is the sentinel, then the rows left.
        expect($service->saveSiteOverrides($second->id, ['categories' => $cats, 'cookies' => [$rows[0]]], []), 'remove one');
        fresh();
        same(1, $count('{{%cookieconsent_cookie}}', $second->id), 'one cookie removed');

        // Remove all: only the empty sentinels are posted.
        expect($service->saveSiteOverrides($second->id, ['categories' => '', 'cookies' => ''], []), 'remove all: ' . implode(' ', $service->getValidationErrors()));
        fresh();
        $secondId = $service->findSiteSettingsId($second->id);
        same(0, $secondId === null ? 0 : $count('{{%cookieconsent_cookie}}', $second->id), 'every cookie row removed');
        same(0, $secondId === null ? 0 : $count('{{%cookieconsent_category}}', $second->id), 'every category row removed');

        // Reloaded, the site inherits instead of showing the removed rows.
        $global = $service->loadSettings();
        same($global->getCategoryKeys(), $service->getEffectiveSettings($second->id)->getCategoryKeys(), 'categories inherit after reload');
        same($service->getGlobalSettingsId(), $cookies->getEffectiveSettingsId($second->id), 'cookies inherit after reload');

        // The other site is untouched.
        same(2, $count('{{%cookieconsent_cookie}}', $primary->id), "the other site's cookies");
        same(['necessary', 'site_only'], $service->getEffectiveSettings($primary->id)->getCategoryKeys(), "the other site's categories");

        // A list that is neither rows nor the sentinel is refused, not guessed at.
        expect(!$service->saveSiteOverrides($second->id, ['cookies' => 'nonsense'], []), 'a scalar cookie list was accepted');
    });
}

$check('final: reconcile() prunes the rows of a soft-deleted site', function () use ($plugin) {
    $primary = Craft::$app->getSites()->getPrimarySite();
    $now     = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert('{{%sites}}', [
        'groupId' => $primary->groupId, 'primary' => false, 'enabled' => true, 'name' => 'Soft deleted', 'handle' => 'ccfSoftDeleted',
        'language' => 'en-US', 'hasUrls' => false, 'sortOrder' => 99, 'dateCreated' => $now, 'dateUpdated' => $now,
        'dateDeleted' => $now, 'uid' => craft\helpers\StringHelper::UUID(),
    ])->execute();
    $softId = (int) Craft::$app->getDb()->getLastInsertID();

    Craft::$app->getDb()->createCommand()->insert('{{%cookieconsent_settings}}', [
        'siteId' => $softId, 'isGlobal' => false, 'bannerHeading' => 'Orphan', 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => craft\helpers\StringHelper::UUID(),
    ])->execute();

    $plugin->cookieSettings->getGlobalSettingsId(); // make sure the global row exists
    $keep = (int) (new Query())->from('{{%cookieconsent_settings}}')->where(['not in', 'siteId', [$softId]])->count();

    (new sfsinfotech\craftcookieconsentflow\migrations\Install())->reconcile();

    same(0, (int) (new Query())->from('{{%cookieconsent_settings}}')->where(['siteId' => $softId])->count(), 'orphan row removed');
    same($keep, (int) (new Query())->from('{{%cookieconsent_settings}}')->count(), 'every other row, the global one included, untouched');
});

// ---------------------------------------------------------------------------
// H2 — the throttle, against the real cache
// ---------------------------------------------------------------------------

$check('H2: one client is limited however many forwarded headers it invents', function () {
    Craft::$app->getCache()->flush();
    $allowed = 0;

    for ($i = 0; $i < 30; $i++) {
        $client = Throttle::clientIp('203.0.113.9', ['x-forwarded-for' => "198.51.100.{$i}"], ['any'], ['X-Forwarded-For']);
        if (Throttle::allow('integration-spoof', 20, 60, $client)) {
            $allowed++;
        }
    }

    same(20, $allowed, 'allowed requests');
});

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

$failed = 0;

foreach ($checks as [$name, $fn, $transactional]) {
    fresh();
    $transaction = $transactional ? $db->beginTransaction() : null;

    try {
        $fn();
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL {$name}\n       " . get_class($e) . ': ' . $e->getMessage() . "\n";
        if (!$e instanceof CheckFailed) {
            echo '       at ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    } finally {
        if ($transaction !== null && $transaction->getIsActive()) {
            $transaction->rollBack();
        }
    }
}

fresh();

echo "\n" . (count($checks) - $failed) . '/' . count($checks) . " integration checks passed on {$driver}\n";

exit($failed === 0 ? 0 : 1);
