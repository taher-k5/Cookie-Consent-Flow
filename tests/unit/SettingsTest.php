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
}
