<?php

namespace sfsinfotech\craftcookieconsentflow\helpers;

use Craft;
use craft\elements\User;

/**
 * The control-panel welcome tour: who sees it, and what it says.
 *
 * Craft has no onboarding API, and no single moment when a plugin is "first
 * installed" in a browser — it is installed from the Plugin Store, from
 * Settings → Plugins, by `php craft plugin/install`, by
 * `project-config/apply` on deploy, or along with Craft itself, and only the
 * first of those leaves room for a redirect. So the tour is not tied to
 * installation at all. It is decided when a plugin page is rendered: a user
 * who has never finished or dismissed it sees it there, once.
 *
 * "Seen" is a Craft user preference, so it is per user and per environment
 * (preferences are not project config), and every admin — including one
 * added later — gets the tour exactly once. Uninstalling the plugin leaves
 * the preference in place; a reinstall is not a first visit.
 */
final class Onboarding
{
    /** The user-preference key recording that the tour was finished or dismissed. */
    public const PREFERENCE = 'cookieConsentFlowTourSeen';

    /** The CP action that records it. */
    public const COMPLETE_ACTION = 'cookie-consent-flow/onboarding/complete';

    /** Whether a CP path (Craft's path without the CP trigger) is one of this plugin's pages. */
    public static function isPluginPage(string $path): bool
    {
        return $path === 'cookie-consent-flow' || str_starts_with($path, 'cookie-consent-flow/');
    }

    /** Whether this user has finished or dismissed the tour. */
    public static function hasSeen(User $user): bool
    {
        return filter_var($user->getPreference(self::PREFERENCE, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Records that this user has finished or dismissed the tour. */
    public static function markSeen(User $user): void
    {
        Craft::$app->getUsers()->saveUserPreferences($user, [self::PREFERENCE => true]);
    }

    /**
     * The tour's steps for a user who can open the given subnav items (see
     * Plugin::subnavFor()), so nobody is walked to a screen they cannot open.
     *
     * Each step points at a sidebar link by its CP path (`path`) and whether
     * it is the section link or a subnav link (`nav`); the script finds the
     * link whose URL ends in that path. With no `path` the step is shown
     * without a highlight. With configuration access, a last step without a target
     * shows how to gate a script, since that is the one setup step that
     * happens in templates rather than in the control panel.
     *
     * @param array<string, array{label: string, url: string}> $subnav
     * @return array<int, array{key: string, title: string, body: string, nav: ?string, path: ?string, code?: string}>
     */
    public static function steps(array $subnav): array
    {
        if ($subnav === []) {
            return [];
        }

        $t = static fn(string $message): string => Craft::t('cookie-consent-flow', $message);

        $copy = [
            'dashboard' => $t('Consent totals for each site, and a preview of your banner with its current settings.'),
            'banner'    => $t('The banner’s layout, wording and colours, and geo-targeting: which countries see it.'),
            'cookies'   => $t('The cookies listed for each category, and cookies found on your site that are not documented yet.'),
            'logs'      => $t('Every visitor decision, with its categories and policy version. Filter records here; users with the export permission can download them as CSV or JSON.'),
            'multi-site-override' => $t('Override the global settings for one site, copy settings between sites, or go back to the global settings.'),
            'settings'  => $t('Categories, consent logging and retention, Google Consent Mode, privacy signals and consent expiry. You can replay this tour from the bottom of this page.'),
        ];

        $steps = [[
            'key'   => 'section',
            'title' => $t('Everything is under Cookie Consent'),
            'body'  => $t('Each screen this plugin adds is in this menu. You only see the screens your permissions allow.'),
            'nav'   => 'section',
            'path'  => reset($subnav)['url'],
        ]];

        foreach ($subnav as $key => $item) {
            if (!isset($copy[$key])) {
                continue;
            }

            $steps[] = [
                'key'   => $key,
                'title' => $item['label'],
                'body'  => $copy[$key],
                'nav'   => 'sub',
                'path'  => $item['url'],
            ];
        }

        if (isset($subnav['settings'])) {
            $steps[] = [
                'key'   => 'placeholders',
                'title' => $t('Load optional scripts only after consent'),
                'body'  => $t('In your templates, replace a tracking script or embed with a placeholder like this. It stays inactive until the visitor accepts its category.'),
                'nav'   => null,
                'path'  => null,
                'code'  => '<script type="text/plain" data-cck-category="analytics"' . "\n"
                    . '        data-cck-src="https://example.com/script.js"></script>',
            ];
        }

        return $steps;
    }

    /**
     * Everything the tour script needs, for a page this user can open.
     *
     * @param array<string, array{label: string, url: string}> $subnav
     * @return array<string, mixed>
     */
    public static function config(bool $seen, array $subnav): array
    {
        $t = static fn(string $message): string => Craft::t('cookie-consent-flow', $message);

        return [
            'autoStart'    => !$seen,
            'saveAction'   => self::COMPLETE_ACTION,
            'canReplay'    => isset($subnav['settings']),
            'steps'        => self::steps($subnav),
            'strings'      => [
                'welcomeEyebrow' => $t('Cookie Consent Flow'),
                'welcomeTitle'   => $t('Welcome to Cookie Consent Flow'),
                'welcomeBody'    => isset($subnav['settings'])
                    ? $t('A one-minute tour of where everything is. You can replay it from the bottom of the Settings page.')
                    : $t('A one-minute tour of where everything is.'),
                'start'   => $t('Take the tour'),
                'later'   => $t('Later'),
                'never'   => $t('Don’t show again'),
                'next'    => $t('Next'),
                'back'    => $t('Back'),
                'skip'    => $t('Skip'),
                'finish'  => $t('Finish'),
                'stepOf'  => $t('{current} of {total}'),
            ],
        ];
    }
}
