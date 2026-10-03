<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\services\ConsentModeService;

/**
 * Google Consent Mode v2 signal mapping.
 *
 * These assertions are about outcomes a visitor cannot see and an admin cannot
 * easily check: if a category grants the wrong signal, or a withdrawal fails to
 * return one to denied, nothing breaks and nothing is logged — Google simply
 * receives the wrong answer. That makes this the part of the plugin most worth
 * pinning down here.
 */
final class ConsentModeServiceTest extends TestCase
{
    private ConsentModeService $service;
    private Settings $settings;

    protected function setUp(): void
    {
        $this->service  = new ConsentModeService();
        $this->settings = new Settings();
    }

    /**
     * security_storage is granted and every other signal denied. That one
     * exception is deliberate: denying it breaks Google's own security
     * features, and it does not depend on consent under the "strictly
     * necessary" carve-out.
     *
     * Asserted as an exhaustive partition rather than signal by signal, so a
     * signal added to the default state without a decision about it fails here
     * instead of defaulting to something nobody chose.
     */
    public function testDefaultStateGrantsOnlySecurityStorage(): void
    {
        $state = $this->service->buildDefaultState();

        $granted = array_keys(array_filter($state, static fn(string $v): bool => $v === 'granted'));
        $denied = array_keys(array_filter($state, static fn(string $v): bool => $v === 'denied'));

        self::assertSame(['security_storage'], $granted);
        self::assertSame([
            'ad_storage',
            'ad_user_data',
            'ad_personalization',
            'analytics_storage',
            'functionality_storage',
            'personalization_storage',
        ], $denied);
    }

    public function testDefaultStateCoversEveryV2Signal(): void
    {
        self::assertSame(
            ConsentModeService::SIGNALS,
            array_keys($this->service->buildDefaultState())
        );

        // The two signals Consent Mode v2 added. Their absence is exactly the
        // kind of regression that would go unnoticed.
        self::assertContains('ad_user_data', ConsentModeService::SIGNALS);
        self::assertContains('ad_personalization', ConsentModeService::SIGNALS);
    }

    public function testAcceptingAnalyticsGrantsOnlyAnalyticsStorage(): void
    {
        $state = $this->service->buildStateForCategories($this->settings, ['necessary', 'analytics']);

        self::assertSame('granted', $state['analytics_storage']);
        self::assertSame('denied', $state['ad_storage']);
        self::assertSame('denied', $state['ad_user_data']);
        self::assertSame('denied', $state['ad_personalization']);
    }

    public function testAcceptingMarketingGrantsAllThreeAdSignals(): void
    {
        $state = $this->service->buildStateForCategories($this->settings, ['necessary', 'marketing']);

        self::assertSame('granted', $state['ad_storage']);
        self::assertSame('granted', $state['ad_user_data']);
        self::assertSame('granted', $state['ad_personalization']);
        self::assertSame('denied', $state['analytics_storage']);
    }

    public function testAcceptingEverythingGrantsEverySignal(): void
    {
        $state = $this->service->buildStateForCategories(
            $this->settings,
            $this->settings->getCategoryKeys()
        );

        self::assertSame(
            [],
            array_keys(array_filter($state, static fn(string $v): bool => $v !== 'granted'))
        );
    }

    /**
     * Withdrawal has to actually return signals to denied. Recomputing from
     * the default state (rather than diffing against the previous one) is what
     * guarantees a signal cannot stay granted after the category that granted
     * it is switched off.
     */
    public function testWithdrawalReturnsSignalsToDenied(): void
    {
        $granted = $this->service->buildStateForCategories($this->settings, ['necessary', 'marketing']);
        self::assertSame('granted', $granted['ad_storage']);

        $withdrawn = $this->service->buildStateForCategories($this->settings, ['necessary']);

        self::assertSame('denied', $withdrawn['ad_storage']);
        self::assertSame('denied', $withdrawn['ad_user_data']);
        self::assertSame('denied', $withdrawn['ad_personalization']);
    }

    /**
     * A category mapped to nothing must grant nothing — the safe reading of
     * "the admin has not said what this corresponds to".
     */
    public function testUnmappedCategoryGrantsNothing(): void
    {
        $this->settings->categories = [
            ['key' => 'necessary', 'label' => 'Essential', 'locked' => true, 'gcmSignals' => ['security_storage']],
            ['key' => 'something', 'label' => 'Something', 'locked' => false, 'gcmSignals' => []],
        ];

        $state = $this->service->buildStateForCategories($this->settings, ['necessary', 'something']);

        self::assertSame($this->service->buildDefaultState(), $state);
    }

    /**
     * The mapping reaches gtag() verbatim, so it is an allow-list: anything
     * that is not a real v2 signal must be dropped rather than forwarded.
     */
    public function testInvalidSignalsAreDiscardedFromTheMapping(): void
    {
        $this->settings->categories = [
            ['key' => 'analytics', 'label' => 'Analytics', 'gcmSignals' => ['analytics_storage', 'not_a_signal']],
        ];

        self::assertSame(
            ['analytics' => ['analytics_storage']],
            $this->settings->getCategoryGcmSignals()
        );
    }

    /** Category keys are free-form, so no signal may be inferred from a name. */
    public function testCustomCategoryKeysAreHonoured(): void
    {
        $this->settings->categories = [
            ['key' => 'werbung', 'label' => 'Werbung', 'gcmSignals' => ['ad_storage', 'ad_user_data']],
        ];

        $state = $this->service->buildStateForCategories($this->settings, ['werbung']);

        self::assertSame('granted', $state['ad_storage']);
        self::assertSame('granted', $state['ad_user_data']);
        self::assertSame('denied', $state['ad_personalization']);
    }

    public function testDisabledConsentModeRendersNothing(): void
    {
        $this->settings->consentModeEnabled = false;

        self::assertSame('', $this->service->renderScript($this->settings));
    }
}
