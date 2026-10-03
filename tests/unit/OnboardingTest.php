<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\helpers\Onboarding;
use sfsinfotech\craftcookieconsentflow\Plugin;

/**
 * The welcome tour: where it is offered, and that its steps follow the
 * user's permissions exactly as the CP navigation does.
 */
final class OnboardingTest extends TestCase
{
    public function testOfferedOnlyOnThePluginsOwnPages(): void
    {
        self::assertTrue(Onboarding::isPluginPage('cookie-consent-flow'));
        self::assertTrue(Onboarding::isPluginPage('cookie-consent-flow/settings'));
        self::assertTrue(Onboarding::isPluginPage('cookie-consent-flow/logs/view/12'));

        self::assertFalse(Onboarding::isPluginPage(''));
        self::assertFalse(Onboarding::isPluginPage('dashboard'));
        self::assertFalse(Onboarding::isPluginPage('settings/plugins'));
        self::assertFalse(Onboarding::isPluginPage('cookie-consent-flow-other'));
    }

    public function testEveryNavigationItemHasAStep(): void
    {
        $subnav = Plugin::subnavFor(true, true, true);
        $keys   = array_column(Onboarding::steps($subnav), 'key');

        // A subnav item added without tour copy would silently be left out.
        self::assertSame(
            array_merge(['section'], array_keys($subnav), ['placeholders']),
            $keys
        );
    }

    public function testStepsFollowPermissions(): void
    {
        self::assertSame([], Onboarding::steps(Plugin::subnavFor(false, false, true)));

        // Records only: no configuration screens, and no template step.
        self::assertSame(
            ['section', 'dashboard', 'logs'],
            array_column(Onboarding::steps(Plugin::subnavFor(false, true, true)), 'key')
        );

        // Single site: no Multisite step.
        self::assertNotContains(
            'multi-site-override',
            array_column(Onboarding::steps(Plugin::subnavFor(true, true, false)), 'key')
        );
    }

    public function testStepsPointAtTheSidebarLinks(): void
    {
        $subnav = Plugin::subnavFor(true, true, false);
        $steps  = array_column(Onboarding::steps($subnav), null, 'key');

        self::assertSame(['section', 'cookie-consent-flow'], [$steps['section']['nav'], $steps['section']['path']]);
        self::assertSame(['sub', 'cookie-consent-flow/banner'], [$steps['banner']['nav'], $steps['banner']['path']]);
        self::assertSame($subnav['banner']['label'], $steps['banner']['title']);

        // The template step has nothing to highlight.
        self::assertNull($steps['placeholders']['path']);
        self::assertStringContainsString('data-cck-category="analytics"', $steps['placeholders']['code']);
    }

    public function testStartsOnItsOwnOnlyUntilSeen(): void
    {
        $subnav = Plugin::subnavFor(true, true, false);

        self::assertTrue(Onboarding::config(false, $subnav)['autoStart']);
        self::assertFalse(Onboarding::config(true, $subnav)['autoStart']);

        // Steps are still there once seen, so Settings can replay the tour.
        self::assertNotSame([], Onboarding::config(true, $subnav)['steps']);
    }

    public function testReplayIsMentionedOnlyToUsersWhoCanOpenSettings(): void
    {
        $manager = Onboarding::config(false, Plugin::subnavFor(true, false, false));
        $viewer  = Onboarding::config(false, Plugin::subnavFor(false, true, false));

        self::assertTrue($manager['canReplay']);
        self::assertStringContainsString('Settings', $manager['strings']['welcomeBody']);

        self::assertFalse($viewer['canReplay']);
        self::assertStringNotContainsString('Settings', $viewer['strings']['welcomeBody']);
    }
}
