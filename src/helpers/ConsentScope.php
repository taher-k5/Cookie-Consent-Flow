<?php

namespace sfsinfotech\craftcookieconsentflow\helpers;

use Craft;
use craft\models\Site;
use sfsinfotech\craftcookieconsentflow\events\ResolveConsentScopeEvent;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\Plugin;

/**
 * The set of sites that share one consent decision.
 *
 * Every site belongs to exactly one scope, named by its key. Everything a
 * browser keeps about a decision is namespaced by that key — the stored
 * decision (`cck_consent_<key>`), the visitor cookie (`cck_visitor_<key>`),
 * and the `<head>` Consent Mode replay that reads the stored decision — so
 * sites with the same key share a decision and sites with different keys
 * never see each other's.
 *
 * - **Per site** (the default): the key is the site ID, so each site asks
 *   on its own. This is the behaviour before the setting existed, with the
 *   same key names, so stored decisions survive the upgrade.
 * - **Per site group**: the key is `g<groupId>`, so the language sites of
 *   one multi-language website ask once.
 *
 * {@see ResolveConsentScopeEvent} lets a site group sites some other way.
 *
 * Consent records always keep the real site ID. Only what the browser holds
 * is shared; records, exports, statistics and per-site permissions are
 * unchanged.
 *
 * Resolution never takes a page down: if anything fails, the site gets its
 * own scope — the per-site behaviour — and the error is logged.
 */
final class ConsentScope
{
    /**
     * What a key may contain. It becomes part of a cookie name and a storage
     * key, and is matched by a regular expression in the `<head>` replay.
     */
    public const KEY_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** @var array<int, self> Per-request cache, keyed by site ID. */
    private static array $_cache = [];

    /**
     * @param string   $key       The scope key.
     * @param int[]    $siteIds   Every site in the scope, including the one it was resolved for.
     * @param string[] $staleKeys Keys the site's decision was stored under by the other Consent
     *                            Scope setting, and that no site uses now; see {@see staleKeysFor()}.
     */
    public function __construct(
        public readonly string $key,
        public readonly array $siteIds,
        public readonly array $staleKeys = [],
    ) {
    }

    /** Whether more than one site shares this scope's decision. */
    public function isShared(): bool
    {
        return count($this->siteIds) > 1;
    }

    /** The browser storage key holding the visitor's decision. */
    public function storageKey(): string
    {
        return 'cck_consent_' . $this->key;
    }

    /** The httpOnly cookie holding the visitor's anonymous identifier. */
    public function visitorCookieName(): string
    {
        return ConsentHelper::visitorCookieName($this->key);
    }

    /**
     * The scope a site belongs to.
     *
     * Every site's key is resolved together and cached for the request,
     * because "which sites share this key" needs all of them anyway.
     */
    public static function forSite(int $siteId): self
    {
        if (!isset(self::$_cache[$siteId])) {
            self::$_cache += self::_resolveAll();
            self::$_cache[$siteId] ??= new self((string) $siteId, [$siteId]);
        }

        return self::$_cache[$siteId];
    }

    /**
     * The key the Consent Scope setting gives a site, before any listener
     * changes it. A site with no group (which Craft does not allow, but a
     * half-built site model may have) keeps its own scope.
     */
    public static function defaultKey(string $scope, int $siteId, ?int $groupId): string
    {
        if ($scope === Settings::CONSENT_SCOPE_GROUP && $groupId !== null && $groupId > 0) {
            return 'g' . $groupId;
        }

        return (string) $siteId;
    }

    /**
     * Groups resolved keys into scopes.
     *
     * @param array<int, string>   $keys     Site ID => key.
     * @param array<int, int|null> $groupIds Site ID => site group ID, for the stale keys.
     * @return array<int, self> Site ID => its scope.
     */
    public static function fromKeys(array $keys, array $groupIds = []): array
    {
        $members = [];
        foreach ($keys as $siteId => $key) {
            $members[$key][] = (int) $siteId;
        }

        $active = array_map('strval', array_keys($members));

        $scopes = [];
        foreach ($keys as $siteId => $key) {
            $scopes[(int) $siteId] = new self(
                $key,
                $members[$key],
                self::staleKeysFor((int) $siteId, $groupIds[$siteId] ?? null, $active)
            );
        }

        return $scopes;
    }

    /**
     * The keys a site's decision would be stored under by either Consent
     * Scope setting — its site ID and `g<groupId>` — less every key a site
     * uses now.
     *
     * A decision left under one of them is from before the setting last
     * changed. Left in place it would count again if the setting were
     * changed back, even when the visitor has since made a different choice
     * under the other key, so the runtime removes it.
     *
     * @param string[] $activeKeys
     * @return string[]
     */
    public static function staleKeysFor(int $siteId, ?int $groupId, array $activeKeys): array
    {
        $candidates = [(string) $siteId];

        if ($groupId !== null && $groupId > 0) {
            $candidates[] = 'g' . $groupId;
        }

        return array_values(array_diff($candidates, $activeKeys));
    }

    /** Forgets resolved scopes, after the Consent Scope setting changes. */
    public static function clearCache(): void
    {
        self::$_cache = [];
    }

    /**
     * Names of the site groups whose sites are served from more than one
     * origin. Browser storage is per origin, so with "Per site group" those
     * sites still ask separately — worth telling the admin who picked it.
     *
     * Sites whose base URL has no host (a relative or unset base URL) are
     * served from the request's own origin and are not counted.
     *
     * @return string[]
     */
    public static function groupsSpanningOrigins(): array
    {
        $names = [];

        try {
            foreach (Craft::$app->getSites()->getAllGroups() as $group) {
                $origins = [];

                foreach ($group->getSites() as $site) {
                    $origin = self::origin($site->getBaseUrl());

                    if ($origin !== null) {
                        $origins[$origin] = true;
                    }
                }

                if (count($origins) > 1) {
                    $names[] = (string) $group->getName();
                }
            }
        } catch (\Throwable $e) {
            Craft::warning('Cookie Consent Flow could not compare site origins: ' . $e->getMessage(), __METHOD__);
        }

        return $names;
    }

    /**
     * The origin (`scheme://host[:port]`) of an absolute URL, lower-cased with
     * default ports dropped, or null for a URL without a host.
     */
    public static function origin(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port   = $parts['port'] ?? null;

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme . '://' . strtolower($parts['host']) . ($port !== null ? ':' . $port : '');
    }

    /**
     * Resolves every site's key, falling back to per-site scopes on failure.
     *
     * @return array<int, self>
     */
    private static function _resolveAll(): array
    {
        try {
            $plugin = Plugin::getInstance();
            $scope  = $plugin?->getSettings()->consentScope ?? Settings::CONSENT_SCOPE_SITE;
            $keys     = [];
            $groupIds = [];

            foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
                $keys[(int) $site->id]     = self::_resolveKey($site, $scope, $plugin);
                $groupIds[(int) $site->id] = $site->groupId;
            }

            return self::fromKeys($keys, $groupIds);
        } catch (\Throwable $e) {
            Craft::error('Cookie Consent Flow could not resolve consent scopes; using per-site scopes: ' . $e->getMessage(), __METHOD__);

            return [];
        }
    }

    private static function _resolveKey(Site $site, string $scope, ?Plugin $plugin): string
    {
        $default = self::defaultKey($scope, (int) $site->id, $site->groupId);

        if ($plugin === null || !$plugin->hasEventHandlers(Plugin::EVENT_RESOLVE_CONSENT_SCOPE)) {
            return $default;
        }

        $event = new ResolveConsentScopeEvent([
            'site'     => $site,
            'scope'    => $scope,
            'scopeKey' => $default,
        ]);
        $plugin->trigger(Plugin::EVENT_RESOLVE_CONSENT_SCOPE, $event);

        if (!preg_match(self::KEY_PATTERN, $event->scopeKey)) {
            Craft::warning(
                "Cookie Consent Flow ignored consent scope key '{$event->scopeKey}' for site #{$site->id}: use 1–64 letters, numbers, dashes or underscores.",
                __METHOD__
            );

            return $default;
        }

        return $event->scopeKey;
    }
}
