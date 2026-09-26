<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use sfsinfotech\craftcookieconsentflow\services\SettingsService;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\migrations\Install;
use sfsinfotech\craftcookieconsentflow\models\Settings;

/**
 * Agreement between the settings model and the canonical schema.
 *
 * `Install.php` is the single source of truth for the schema, which means it
 * carries its own copy of several field lists. Those copies and the model's
 * have to stay aligned, and when they drift the failure is quiet and one-sided:
 * the control panel accepts a value the column cannot hold, and the save fails
 * at INSERT on strict MySQL or PostgreSQL rather than in validation. That is
 * precisely how the colour columns ended up 30 characters wide while the
 * accepted syntax was unbounded.
 *
 * Reflection is used deliberately: these lists are private implementation
 * detail and should stay that way, but a test may still check they agree.
 */
final class SchemaConsistencyTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function migrationConstants(): array
    {
        return (new ReflectionClass(Install::class))->getConstants();
    }

    /**
     * The regression this file exists for: the migration's colour list and the
     * model's must name the same columns, or one of them is applying a bound
     * the other does not know about.
     */
    public function testColourFieldListsAgree(): void
    {
        $migration = self::migrationConstants()['COLOR_FIELDS'];

        self::assertSame(
            Settings::COLOR_FIELDS,
            $migration,
            'Install.php and Settings must name the same colour columns, in the same order'
        );
    }

    /** Every colour is a real, typed property on the model. */
    public function testEveryColourFieldExistsOnTheModel(): void
    {
        $settings = new Settings();

        foreach (Settings::COLOR_FIELDS as $field) {
            self::assertTrue($settings->hasProperty($field), "{$field} is not a settings property");
        }
    }

    /** And every colour is overridable per site, like the rest of the banner. */
    public function testEveryColourFieldIsSiteOverridable(): void
    {
        self::assertSame(
            [],
            array_diff(Settings::COLOR_FIELDS, Settings::OVERRIDABLE_FIELDS),
            'A colour that cannot be overridden per site would be an inconsistency, not a policy'
        );
    }

    /**
     * Every column the migration creates has a matching model property, so a
     * column cannot be added without the setting that fills it. `siteId` and
     * `isGlobal` are row bookkeeping rather than settings, and are excluded.
     */
    public function testEverySettingsColumnHasAModelProperty(): void
    {
        $constants = self::migrationConstants();
        $columns = array_merge(
            $constants['BOOL_FIELDS'],
            $constants['STRING_FIELDS'],
            $constants['COLOR_FIELDS'],
            $constants['TEXT_FIELDS'],
            $constants['JSON_FIELDS'],
            $constants['INT_FIELDS']
        );

        $settings = new Settings();
        $orphans = array_values(array_filter(
            $columns,
            static fn(string $column): bool => !$settings->hasProperty($column)
        ));

        self::assertSame([], $orphans, 'Schema columns with no corresponding setting');
    }

    /**
     * The one place a bound is written down. If this changes, the column width
     * in Install.php, the validation rule, and safeCssColor()'s cap all move
     * together — which is the point of there being a constant at all.
     */
    public function testColourBoundIsLargeEnoughForEverySupportedSyntax(): void
    {
        // The longest values the renderer accepts, by category.
        $longest = [
            'hsla(214.285, 100.000%, 50.000%, 0.875)',
            'rgba(255, 255, 255, 0.875)',
            'lightgoldenrodyellow',
            '#ff0000cc',
        ];

        foreach ($longest as $value) {
            self::assertLessThanOrEqual(
                Settings::COLOR_MAX_LENGTH,
                mb_strlen($value),
                "{$value} must fit within the stored colour length"
            );
        }
    }

    // -------------------------------------------------------------------------
    // M8 — validation matches every fixed-width column
    // -------------------------------------------------------------------------

    /**
     * Every 255-character settings column is either an enum (validated by
     * `in`), the policy version (bounded tighter, to fit the consent log's
     * 50-character column), or bounded to 255 by the model — so nothing an
     * admin can type reaches the database longer than its column.
     */
    public function testEveryShortStringColumnIsBoundedByTheModel(): void
    {
        $enums    = ['bannerLayout', 'cornerPosition', 'consentModeType'];
        $bounded  = array_merge(Settings::SHORT_TEXT_FIELDS, $enums, ['policyVersion']);
        $columns  = self::migrationConstants()['STRING_FIELDS'];

        self::assertSame([], array_values(array_diff($columns, $bounded)), 'unbounded 255-character columns');
        self::assertSame([], array_values(array_diff(Settings::SHORT_TEXT_FIELDS, $columns)), 'bounded fields without a 255 column');
    }

    public function testPolicyVersionFitsTheConsentLogColumn(): void
    {
        $model                = new Settings();
        $model->policyVersion = str_repeat('v', 51);

        self::assertFalse($model->validate(['policyVersion']));
    }

    // -------------------------------------------------------------------------
    // M9 — schema versioning
    // -------------------------------------------------------------------------

    /**
     * Craft only runs migrations when the code's schema version is *higher*
     * than the installed one, and refuses to apply project config whose
     * recorded version differs. Builds were installed at 1.6.0 (`main`) and
     * 1.7.0, so anything at or below those would leave those installs
     * un-migrated and their project config unappliable.
     */
    public function testSchemaVersionIsAboveEveryVersionEverInstalled(): void
    {
        $version = (new ReflectionClass(Plugin::class))->getProperty('schemaVersion')->getDefaultValue();

        foreach (['1.0.0', '1.6.0', '1.7.0'] as $installed) {
            self::assertTrue(version_compare($version, $installed, '>'), "{$version} must be above {$installed}");
        }
    }

    /**
     * Build phase: Install.php is the only migration. Any incremental one
     * added later must at least be a loadable Craft migration.
     */
    public function testInstallIsTheCanonicalMigration(): void
    {
        self::assertTrue(is_subclass_of(Install::class, \craft\db\Migration::class));
        self::assertTrue(method_exists(Install::class, 'reconcile'), 'the idempotent schema entry point');

        foreach (glob(dirname(__DIR__, 2) . '/src/migrations/m*_*.php') ?: [] as $file) {
            $class = 'sfsinfotech\\craftcookieconsentflow\\migrations\\' . basename($file, '.php');

            self::assertTrue(class_exists($class), "{$class} must autoload");
            self::assertTrue(is_subclass_of($class, \craft\db\Migration::class));
            self::assertMatchesRegularExpression('/^m\d{6}_\d{6}_[a-z0-9_]+$/', basename($file, '.php'), 'Craft migration naming');
        }
    }

    /** The retired Fixed / Sticky column is neither created nor read. */
    public function testRetiredColumnsAreGoneEverywhere(): void
    {
        self::assertNotContains('fixedPosition', self::migrationConstants()['BOOL_FIELDS']);
        self::assertNotContains('fixedPosition', Settings::OVERRIDABLE_FIELDS);

        $serviceBools = (new ReflectionClass(SettingsService::class))->getConstant('BOOL_FIELDS');
        self::assertNotContains('fixedPosition', $serviceBools);

        // getOverrideFieldGroups() needs Craft's address service for its
        // country list, so the field definitions are checked in the source.
        $source = file_get_contents((new ReflectionClass(Settings::class))->getFileName());
        self::assertStringNotContainsString("'key' => 'fixedPosition'", $source, 'no Multisite field for it');
    }

    /** The service and the migration have to agree about which columns are booleans. */
    public function testBooleanColumnListsAgree(): void
    {
        $serviceBools = (new ReflectionClass(SettingsService::class))->getConstant('BOOL_FIELDS');

        self::assertSame(self::migrationConstants()['BOOL_FIELDS'], $serviceBools);
    }
}
