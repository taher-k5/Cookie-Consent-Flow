<?php

namespace sfsinfotech\craftcookieconsentflow\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\console\ExitCode;

/**
 * Acts on the consent records of one visitor — the erasure side of a data
 * subject request, for when it is handled from the command line.
 *
 * Usage:
 *
 * ```
 * php craft cookie-consent-flow/records/delete --visitor=2a5c0cff-91b3-4e2d-a7f0-3c1d9e8b4a21 --dry-run
 * php craft cookie-consent-flow/records/delete --visitor=2a5c0cff-91b3-4e2d-a7f0-3c1d9e8b4a21
 * php craft cookie-consent-flow/records/delete --visitor=… --site=de
 * php craft cookie-consent-flow/records/delete --user=anna@example.com --dry-run
 * php craft cookie-consent-flow/records/delete --user=42 --include-browsers
 * ```
 *
 * Whether to honour an erasure request for proof-of-consent records is the
 * site's legal decision; export the records first if they may be needed.
 */
class RecordsController extends Controller
{
    public $defaultAction = 'delete';

    /** The visitor ID (consent ID) whose records are deleted. This or --user is required. */
    public ?string $visitor = null;

    /**
     * A Craft user (ID, username or email) whose records are deleted: those
     * made while they were logged in.
     */
    public ?string $user = null;

    /**
     * With --user, also the logged-out records of browsers only that user
     * logged in on. Shared browsers are never included.
     */
    public bool $includeBrowsers = false;

    /** Restrict to one site, by handle or numeric ID. Omit for every site. */
    public ?string $site = null;

    /** Report what would be deleted without deleting anything. */
    public bool $dryRun = false;

    /** Skip the confirmation prompt. Implied when not running interactively. */
    public bool $force = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['visitor', 'user', 'includeBrowsers', 'site', 'dryRun', 'force']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['v' => 'visitor', 'u' => 'user', 's' => 'site', 'f' => 'force']);
    }

    /**
     * Deletes every consent record of one visitor ID.
     */
    public function actionDelete(): int
    {
        if ($this->user !== null && $this->user !== '') {
            return $this->_deleteForUser();
        }

        $visitorId = ConsentHelper::normalizeVisitorId($this->visitor);

        if ($visitorId === null) {
            $this->stderr("Pass the visitor ID to delete with --visitor=<id> (a UUID such as 2a5c0cff-91b3-4e2d-a7f0-3c1d9e8b4a21), or a user with --user=<id, username or email>.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $siteIds = null;
        if ($this->site !== null && $this->site !== '') {
            $sites = Craft::$app->getSites();
            $site  = ctype_digit($this->site) ? $sites->getSiteById((int) $this->site) : $sites->getSiteByHandle($this->site);

            if ($site === null) {
                $this->stderr("No site matches \"{$this->site}\".\n", Console::FG_RED);

                return ExitCode::USAGE;
            }

            $siteIds = [(int) $site->id];
        }

        $consent  = Plugin::getInstance()->consent;
        $scope    = $siteIds === null ? 'all sites' : "site #{$siteIds[0]}";
        $affected = $consent->deleteVisitorRecords($visitorId, $siteIds, dryRun: true);

        if ($affected === 0) {
            $this->stdout("No consent records for visitor {$visitorId} in {$scope}.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout("Dry run: {$affected} consent record(s) for visitor {$visitorId} in {$scope} would be deleted.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if (!$this->force && $this->interactive && !$this->confirm(
            "Delete {$affected} consent record(s) for visitor {$visitorId} in {$scope}? This cannot be undone."
        )) {
            $this->stdout("Aborted.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $deleted = $consent->deleteVisitorRecords($visitorId, $siteIds);

        Craft::warning("Cookie consent records deleted for visitor {$visitorId} from the console: {$deleted} record(s) in {$scope}.", __METHOD__);

        $this->stdout("Deleted {$deleted} consent record(s) for visitor {$visitorId} in {$scope}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /** Deletes one Craft user's consent records. */
    private function _deleteForUser(): int
    {
        $found = \sfsinfotech\craftcookieconsentflow\services\UserLinkService::findUser((string) $this->user);

        if ($found === null) {
            $this->stderr("No user matches \"{$this->user}\".\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $siteIds = $this->_siteIds();
        if ($siteIds === false) {
            $this->stderr("No site matches \"{$this->site}\".\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $consent  = Plugin::getInstance()->consent;
        $userId   = (int) $found->id;
        $what     = "user #{$userId} ({$found->email})" . ($this->includeBrowsers ? ' with their browsers\' logged-out records' : '');
        $scope    = $siteIds === null ? 'all sites' : "site #{$siteIds[0]}";
        $affected = $consent->deleteUserRecords($userId, $this->includeBrowsers, $siteIds, dryRun: true);

        if ($affected === 0) {
            $this->stdout("No consent records for {$what} in {$scope}.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout("Dry run: {$affected} consent record(s) for {$what} in {$scope} would be deleted.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if (!$this->force && $this->interactive && !$this->confirm("Delete {$affected} consent record(s) for {$what} in {$scope}? This cannot be undone.")) {
            $this->stdout("Aborted.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $deleted = $consent->deleteUserRecords($userId, $this->includeBrowsers, $siteIds);

        Craft::warning("Cookie consent records deleted for {$what} from the console: {$deleted} record(s) in {$scope}.", __METHOD__);
        $this->stdout("Deleted {$deleted} consent record(s) for {$what} in {$scope}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * `--site` as site IDs: null for every site, false when it matches none.
     *
     * @return int[]|null|false
     */
    private function _siteIds(): array|null|false
    {
        if ($this->site === null || $this->site === '') {
            return null;
        }

        $sites = Craft::$app->getSites();
        $site  = ctype_digit($this->site) ? $sites->getSiteById((int) $this->site) : $sites->getSiteByHandle($this->site);

        return $site !== null ? [(int) $site->id] : false;
    }
}
