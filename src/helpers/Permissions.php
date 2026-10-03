<?php

namespace sfsinfotech\craftcookieconsentflow\helpers;

use Craft;
use yii\web\ForbiddenHttpException;

/**
 * Permission names and the "any of these" check the CP controllers share.
 *
 * These permissions match real controller boundaries. Configuration is one
 * permission because the Settings screen is one form; claiming finer-grained
 * grants without separately filtering every posted field would create a false
 * security boundary.
 */
class Permissions
{
    /** Full control over banner content, categories, cookies, geo and integrations. */
    public const MANAGE_SETTINGS = 'cookieConsentFlow:manageSettings';

    /** Read consent records in the CP. */
    public const VIEW_LOGS = 'cookieConsentFlow:viewLogs';

    /** Download consent records. VIEW_LOGS deliberately does not imply this. */
    public const EXPORT_LOGS = 'cookieConsentFlow:exportLogs';

    /**
     * Whether the current user holds any of the given permissions. Admins
     * always pass, per Craft's own convention.
     */
    public static function canAny(string ...$permissions): bool
    {
        $user = Craft::$app->getUser();

        foreach ($permissions as $permission) {
            if ($user->checkPermission($permission)) {
                return true;
            }

        }

        return false;
    }

    /**
     * Throws unless the current user holds at least one of the given
     * permissions.
     *
     * @throws ForbiddenHttpException
     */
    public static function requireAny(string ...$permissions): void
    {
        if (!self::canAny(...$permissions)) {
            throw new ForbiddenHttpException('User is not permitted to perform this action.');
        }
    }

    /**
     * The sites whose consent data and overrides the current user may see.
     *
     * On a multisite install Craft grants access per site (`editSite:<uid>`),
     * and the plugin's per-site data — consent records, exports, site
     * overrides — follows the same boundary: a user who may only work on
     * site A must not be able to read site B's records by changing a `site`
     * parameter. Admins, and every user on a single-site install (where Craft
     * registers no per-site permission), see every site.
     *
     * @return \craft\models\Site[]
     */
    public static function accessibleSites(): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $user  = Craft::$app->getUser();

        if (count($sites) <= 1 || ($user->getIdentity()?->admin ?? false)) {
            return $sites;
        }

        return array_values(array_filter(
            $sites,
            static fn(\craft\models\Site $site): bool => $user->checkPermission('editSite:' . $site->uid)
        ));
    }

    /** @return int[] */
    public static function accessibleSiteIds(): array
    {
        return array_map(static fn(\craft\models\Site $site): int => (int) $site->id, self::accessibleSites());
    }

    /** Whether the current user may see a given site's data. */
    public static function canAccessSite(?int $siteId): bool
    {
        return $siteId !== null && in_array($siteId, self::accessibleSiteIds(), true);
    }

    /**
     * Throws unless the current user may see the given site's data.
     *
     * @throws ForbiddenHttpException
     */
    public static function requireSite(?int $siteId): void
    {
        if (!self::canAccessSite($siteId)) {
            throw new ForbiddenHttpException('User is not permitted to access this site.');
        }
    }

    /**
     * The permission tree registered with Craft, in CP display order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definition(): array
    {
        $t = static fn(string $message): string => Craft::t('cookie-consent-flow', $message);

        return [
            self::MANAGE_SETTINGS => [
                'label' => $t('Manage cookie consent configuration'),
            ],
            self::VIEW_LOGS => [
                'label'  => $t('View consent records'),
                'nested' => [
                    self::EXPORT_LOGS => ['label' => $t('Export consent records')],
                ],
            ],
        ];
    }
}
