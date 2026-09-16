<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
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
}
