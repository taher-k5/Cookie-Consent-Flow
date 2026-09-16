<?php

namespace sfsinfotech\craftcookieconsentflow\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\console\ExitCode;

/**
 * Applies the consent-record retention policy.
 *
 * `Plugin::init()` has always pointed `controllerNamespace` at this namespace
 * for console requests, but no controller lived here — so the documented
 * cleanup had no way to run. This is that controller.
 *
 * Usage:
 *
 * ```
 * php craft cookie-consent-flow/retention/clear
 * php craft cookie-consent-flow/retention/clear --days=90
 * php craft cookie-consent-flow/retention/clear --site=de
 * php craft cookie-consent-flow/retention/clear --dry-run
 * ```
 *
 * Schedule it daily, e.g. `0 3 * * * cd /path/to/project && php craft
 * cookie-consent-flow/retention/clear`.
 */
class RetentionController extends Controller
{
    public $defaultAction = 'clear';

    /**
     * Delete records older than this many days. Defaults to the plugin's
     * configured "Log Retention (days)". 0 means keep indefinitely, in which
     * case nothing is deleted.
     */
    public ?int $days = null;

    /** Restrict to one site, by handle or numeric ID. Omit for every site. */
    public ?string $site = null;

    /** Report what would be deleted without deleting anything. */
    public bool $dryRun = false;

    /** Skip the confirmation prompt. Implied when not running interactively. */
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['days', 'site', 'dryRun', 'force']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['d' => 'days', 's' => 'site', 'f' => 'force']);
    }

    /**
     * Deletes consent records past the retention period.
     */
    public function actionClear(): int
    {
        $plugin = Plugin::getInstance();
        $days   = $this->days ?? $plugin->getSettings()->logRetentionDays;

        if ($days < 0) {
            $this->stderr("A negative retention period is not meaningful.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if ($days === 0) {
            $this->stdout(
                "Retention is set to 0 (keep indefinitely) — nothing to do.\n"
                . "Pass --days=N to purge with an explicit period instead.\n",
                Console::FG_YELLOW
            );

            return ExitCode::OK;
        }

        $siteId = $this->_resolveSiteId();
        if ($siteId === false) {
            $this->stderr("No site matches \"{$this->site}\".\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $scope     = $siteId === null ? 'all sites' : "site #{$siteId}";
        $affected  = $plugin->consent->purgeOldLogs($days, $siteId, dryRun: true);

        if ($affected === 0) {
            $this->stdout("No consent records older than {$days} days in {$scope}.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout(
                "Dry run: {$affected} consent record(s) older than {$days} days in {$scope} would be deleted.\n",
                Console::FG_YELLOW
            );

            return ExitCode::OK;
        }

        // Deleting consent records destroys the evidence of consent they
        // exist to provide, so confirm interactively unless told not to.
        if (!$this->force && $this->interactive && !$this->confirm(
            "Delete {$affected} consent record(s) older than {$days} days in {$scope}?"
        )) {
            $this->stdout("Aborted.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $deleted = $plugin->consent->purgeOldLogs($days, $siteId);

        $this->stdout("Deleted {$deleted} consent record(s) older than {$days} days in {$scope}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Resolves `--site` to a site ID, `null` for "every site", or `false`
     * when the value matches no site (an unresolvable site must be an error,
     * not a silent fall-through to purging every site).
     */
    private function _resolveSiteId(): int|null|false
    {
        if ($this->site === null || $this->site === '') {
            return null;
        }

        $sites = Craft::$app->getSites();
        $site  = ctype_digit($this->site)
            ? $sites->getSiteById((int) $this->site)
            : $sites->getSiteByHandle($this->site);

        return $site?->id ?? false;
    }
}
