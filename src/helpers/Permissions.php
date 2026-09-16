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
     * Whether the current user holds any of the given permissions, taking the
     * Admins always pass, per Craft's own convention.
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
