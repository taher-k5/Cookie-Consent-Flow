<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\models\Settings;

/**
 * Category resolution and per-site override merging.
 */
final class SettingsTest extends TestCase
{
    public function testCategoryKeysAreReturnedInOrder(): void
    {
        self::assertSame(
            ['necessary', 'analytics', 'marketing', 'preferences'],
            (new Settings())->getCategoryKeys()
        );
    }

    public function testLockedCategoriesAreIdentified(): void
    {
        self::assertSame(['necessary'], (new Settings())->getLockedCategoryKeys());
    }

    /**
     * Optional categories are off unless an admin explicitly pre-checks them.
     * Anything else would present consent the visitor has not given as though
     * they had.
     */
    public function testOnlyLockedAndExplicitlyDefaultedCategoriesArePreChecked(): void
    {
        self::assertSame(['necessary'], (new Settings())->getDefaultCategoryKeys());
    }

    public function testLockedCategoriesArePreCheckedEvenWithoutDefaultFlag(): void
    {
        $settings = new Settings();
        $settings->categories = [
            ['key' => 'necessary', 'label' => 'Essential', 'default' => false, 'locked' => true],
            ['key' => 'analytics', 'label' => 'Analytics', 'default' => true, 'locked' => false],
        ];

        self::assertSame(['necessary', 'analytics'], $settings->getDefaultCategoryKeys());
    }

    public function testSiteOverridesReplaceGlobalValues(): void
    {
        $settings = new Settings();
        $settings->bannerHeading = 'Global heading';
        $settings->siteOverrides = [2 => ['bannerHeading' => 'German heading']];

        self::assertSame('German heading', $settings->resolveForSite(2)->bannerHeading);
    }

    public function testSiteWithoutOverridesInheritsGlobalValues(): void
    {
        $settings = new Settings();
        $settings->bannerHeading = 'Global heading';
        $settings->siteOverrides = [2 => ['bannerHeading' => 'German heading']];

        self::assertSame('Global heading', $settings->resolveForSite(3)->bannerHeading);
    }

    /** Resolving for one site must not mutate the shared model. */
    public function testResolveForSiteDoesNotMutateTheOriginal(): void
    {
        $settings = new Settings();
        $settings->bannerHeading = 'Global heading';
        $settings->siteOverrides = [2 => ['bannerHeading' => 'German heading']];

        $settings->resolveForSite(2);

        self::assertSame('Global heading', $settings->bannerHeading);
    }

    /**
     * Only fields declared overridable may be overridden. Without this, a
     * crafted override payload could reach a global-only setting such as
     * consent logging.
     */
    public function testNonOverridableFieldsAreIgnored(): void
    {
        $settings = new Settings();
        $settings->logEnabled = true;
        $settings->siteOverrides = [2 => ['logEnabled' => false]];

        self::assertTrue($settings->resolveForSite(2)->logEnabled);
    }

    public function testOverrideDetectionDistinguishesInheritedFromOverridden(): void
    {
        $settings = new Settings();
        $settings->siteOverrides = [2 => ['bannerHeading' => 'Custom']];

        self::assertTrue($settings->isFieldOverridden(2, 'bannerHeading'));
        self::assertFalse($settings->isFieldOverridden(2, 'bannerBgColor'));
        self::assertFalse($settings->isFieldOverridden(3, 'bannerHeading'));
    }

    /**
     * The banner description permits a little HTML and is rendered on every
     * page for every visitor, so the purifier's allow-list is the boundary
     * that matters.
     */
    public function testSafeDescriptionKeepsAllowedMarkup(): void
    {
        $settings = new Settings();
        $settings->bannerDescription = 'Read our <a href="https://example.com/privacy">policy</a> and <strong>choose</strong>.';

        $output = $settings->getSafeDescription();

        self::assertStringContainsString('<strong>', $output);
        self::assertStringContainsString('href="https://example.com/privacy"', $output);
    }

    public function testSafeDescriptionStripsScripts(): void
    {
        $settings = new Settings();
        $settings->bannerDescription = 'Hello<script>alert(1)</script><img src=x onerror=alert(1)>';

        $output = $settings->getSafeDescription();

        self::assertStringNotContainsString('<script', $output);
        self::assertStringNotContainsString('onerror', $output);
        self::assertStringNotContainsString('<img', $output);
    }

    public function testSafeDescriptionStripsJavascriptUrls(): void
    {
        $settings = new Settings();
        $settings->bannerDescription = '<a href="javascript:alert(1)">click</a>';

        self::assertStringNotContainsString('javascript:', $settings->getSafeDescription());
    }

    public function testCssVarsNormaliseBareHexColours(): void
    {
        $settings = new Settings();
        $settings->bannerBgColor = 'ff0000';

        self::assertStringContainsString('--cck-banner-bg: #ff0000;', $settings->getCssVars());
    }

    /** Non-hex values (rgba(), transparent, keywords) must pass through untouched. */
    public function testCssVarsLeaveNonHexColoursAlone(): void
    {
        $settings = new Settings();
        $settings->overlayColor = 'rgba(0,0,0,0.5)';

        self::assertStringContainsString('--cck-overlay: rgba(0,0,0,0.5);', $settings->getCssVars());
    }

    public function testCssVarsRejectStyleBreakingValues(): void
    {
        $settings = new Settings([
            'bannerBgColor' => '</style><script>alert(1)</script>',
            'maxWidth' => '600px;position:fixed',
        ]);

        $css = $settings->getCssVars();

        self::assertStringNotContainsString('</style>', $css);
        self::assertStringNotContainsString('position:fixed', $css);
        self::assertStringContainsString('--cck-banner-bg: #ffffff;', $css);
        self::assertStringContainsString('--cck-max-width: 600px;', $css);
    }

    public function testPrivacyPolicyUrlRejectsExecutableSchemes(): void
    {
        self::assertSame('', (new Settings(['privacyPolicyUrl' => 'javascript:alert(1)']))->getSafePrivacyPolicyUrl());
        self::assertSame('/privacy', (new Settings(['privacyPolicyUrl' => '/privacy']))->getSafePrivacyPolicyUrl());
        self::assertSame('https://example.com/privacy', (new Settings([
            'privacyPolicyUrl' => 'https://example.com/privacy',
        ]))->getSafePrivacyPolicyUrl());
    }

    // -----------------------------------------------------------------
    // Colour bounds
    //
    // Three things have to agree about how long a colour may be: the storage
    // column, the model's validation rule, and the renderer. While validation
    // was unbounded and the column was 30 characters, a legitimate longer
    // `hsla(...)` passed the control panel and then failed at INSERT on strict
    // MySQL and on PostgreSQL — a save that looked accepted and was not.
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function validColorProvider(): array
    {
        return [
            'short hex'        => ['#f00'],
            'six digit hex'    => ['#ff0000'],
            'eight digit hex'  => ['#ff0000cc'],
            'named'            => ['red'],
            'longest named'    => ['lightgoldenrodyellow'],
            'rgb'              => ['rgb(255, 0, 0)'],
            'longest rgba'     => ['rgba(255, 255, 255, 0.875)'],
            'hsl'              => ['hsl(214, 100%, 50%)'],
            'longest hsla'     => ['hsla(214.285, 100.000%, 50.000%, 0.875)'],
        ];
    }

    /**
     * @dataProvider validColorProvider
     */
    public function testSupportedColoursRenderAndFitTheColumn(string $color): void
    {
        $settings = new Settings(['bannerBgColor' => $color]);

        self::assertStringContainsString("--cck-banner-bg: {$color};", $settings->getCssVars());
        self::assertLessThanOrEqual(
            Settings::COLOR_MAX_LENGTH,
            mb_strlen($color),
            'A colour the renderer accepts must fit the storage column'
        );

        self::assertTrue($settings->validate(['bannerBgColor']));
    }

    /**
     * The bound is enforced at both ends: validation refuses an over-long
     * value with a message naming the field, so it never reaches a column it
     * cannot fit, and the renderer falls back rather than emitting it.
     */
    public function testOverLongColourIsRefusedAndFallsBack(): void
    {
        $tooLong  = 'rgba(' . str_repeat('255, ', 20) . '0)';
        $settings = new Settings(['bannerBgColor' => $tooLong]);

        self::assertGreaterThan(Settings::COLOR_MAX_LENGTH, mb_strlen($tooLong));
        self::assertFalse($settings->validate(['bannerBgColor']));
        self::assertArrayHasKey('bannerBgColor', $settings->getErrors());
        self::assertStringContainsString('--cck-banner-bg: #ffffff;', $settings->getCssVars());
    }

    /** Every colour setting shares the one bound, not just the one tested above. */
    public function testEveryColourFieldIsBounded(): void
    {
        $tooLong  = str_repeat('a', Settings::COLOR_MAX_LENGTH + 1);
        $settings = new Settings();

        foreach (Settings::COLOR_FIELDS as $field) {
            $settings->$field = $tooLong;
        }

        self::assertFalse($settings->validate(Settings::COLOR_FIELDS));
        self::assertSame(
            count(Settings::COLOR_FIELDS),
            count($settings->getErrors()),
            'Each colour field should report its own error'
        );
    }

    /** An invalid colour is still rejected regardless of length. */
    public function testInvalidColourSyntaxStillFallsBack(): void
    {
        $settings = new Settings(['bannerBgColor' => 'url(javascript:alert(1))']);

        self::assertStringContainsString('--cck-banner-bg: #ffffff;', $settings->getCssVars());
    }
}
