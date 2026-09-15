<?php

namespace sfsinfotech\craftcookieconsentflow\helpers;

/**
 * Cookie Library — a starter catalog of well-known third-party cookies, so
 * documenting the Cookies CP page doesn't require a developer to already
 * know exact cookie names/durations from memory. Durations reflect each
 * provider's own published defaults at time of writing and drift over time
 * (providers change these); treat entries as a starting point to verify
 * against the provider's current docs and the site's actual DevTools output,
 * not as a guaranteed-accurate source of truth.
 *
 * `suggestedCategory` is only a hint matched against whichever category keys
 * currently exist on this install (see CookiesController) — it is never
 * assumed to exist, since categories are fully admin-configurable.
 */
class CookieLibrary
{
    /**
     * @return array<int, array{id: string, provider: string, name: string, duration: string, purpose: string, suggestedCategory: string}>
     */
    public static function getEntries(): array
    {
        return [
            // Google Analytics (GA4)
            ['id' => 'ga_client_id', 'provider' => 'Google Analytics', 'name' => '_ga', 'duration' => '2 years', 'purpose' => 'Distinguishes unique visitors for analytics reporting.', 'suggestedCategory' => 'analytics'],
            ['id' => 'ga_property', 'provider' => 'Google Analytics', 'name' => '_ga_*', 'duration' => '2 years', 'purpose' => 'Persists session state for a specific GA4 property.', 'suggestedCategory' => 'analytics'],
            ['id' => 'ga_gid', 'provider' => 'Google Analytics', 'name' => '_gid', 'duration' => '24 hours', 'purpose' => 'Distinguishes unique visitors for analytics reporting (Universal Analytics).', 'suggestedCategory' => 'analytics'],

            // Google Ads / conversion tracking
            ['id' => 'gads_gcl_au', 'provider' => 'Google Ads', 'name' => '_gcl_au', 'duration' => '90 days', 'purpose' => 'Stores ad-click information for conversion attribution.', 'suggestedCategory' => 'marketing'],
            ['id' => 'gads_gads', 'provider' => 'Google Ads', 'name' => '_gads', 'duration' => '13 months', 'purpose' => 'Measures ad performance and frequency capping.', 'suggestedCategory' => 'marketing'],

            // Meta / Facebook
            ['id' => 'meta_fbp', 'provider' => 'Meta (Facebook Pixel)', 'name' => '_fbp', 'duration' => '90 days', 'purpose' => 'Delivers a series of advertisement products and measures ad conversions.', 'suggestedCategory' => 'marketing'],
            ['id' => 'meta_fbc', 'provider' => 'Meta (Facebook Pixel)', 'name' => '_fbc', 'duration' => '90 days', 'purpose' => 'Stores the Facebook click identifier for ad conversion attribution.', 'suggestedCategory' => 'marketing'],

            // Microsoft Advertising (Bing UET)
            ['id' => 'ms_uetsid', 'provider' => 'Microsoft Advertising (UET)', 'name' => '_uetsid', 'duration' => '24 hours', 'purpose' => 'Collects visitor behaviour data for ad retargeting and conversion tracking.', 'suggestedCategory' => 'marketing'],
            ['id' => 'ms_uetvid', 'provider' => 'Microsoft Advertising (UET)', 'name' => '_uetvid', 'duration' => '13 months', 'purpose' => 'Identifies returning visitors across sessions for ad retargeting.', 'suggestedCategory' => 'marketing'],

            // LinkedIn Ads
            ['id' => 'li_bcookie', 'provider' => 'LinkedIn Ads', 'name' => 'bcookie', 'duration' => '2 years', 'purpose' => 'Browser identifier used for ad conversion tracking.', 'suggestedCategory' => 'marketing'],
            ['id' => 'li_ads', 'provider' => 'LinkedIn Ads', 'name' => 'li_sugr', 'duration' => '3 months', 'purpose' => 'Identifies browser IDs for ad tracking purposes.', 'suggestedCategory' => 'marketing'],

            // TikTok Ads
            ['id' => 'tiktok_ttp', 'provider' => 'TikTok Ads', 'name' => '_ttp', 'duration' => '13 months', 'purpose' => 'Identifies visitors across sessions for ad conversion attribution.', 'suggestedCategory' => 'marketing'],

            // Reddit Ads
            ['id' => 'reddit_uuid', 'provider' => 'Reddit Ads', 'name' => '_rdt_uuid', 'duration' => '90 days', 'purpose' => 'Identifies a visitor for ad conversion attribution.', 'suggestedCategory' => 'marketing'],

            // Snapchat Ads
            ['id' => 'snap_sctr', 'provider' => 'Snapchat Ads', 'name' => '_sctr', 'duration' => '2 years', 'purpose' => 'Stores click/session data for ad conversion attribution.', 'suggestedCategory' => 'marketing'],

            // Hotjar
            ['id' => 'hotjar_session', 'provider' => 'Hotjar', 'name' => '_hjSessionUser_*', 'duration' => '1 year', 'purpose' => 'Persists the Hotjar user ID, unique to that site, for session recording/heatmaps.', 'suggestedCategory' => 'analytics'],
            ['id' => 'hotjar_first_seen', 'provider' => 'Hotjar', 'name' => '_hjFirstSeen', 'duration' => 'Session', 'purpose' => 'Identifies a new session/visit for Hotjar analytics.', 'suggestedCategory' => 'analytics'],

            // HubSpot
            ['id' => 'hubspot_hstc', 'provider' => 'HubSpot', 'name' => '__hstc', 'duration' => '13 months', 'purpose' => 'Tracks visitor sessions across the site for marketing analytics.', 'suggestedCategory' => 'marketing'],
            ['id' => 'hubspot_hssc', 'provider' => 'HubSpot', 'name' => '__hssc', 'duration' => '30 minutes', 'purpose' => 'Tracks the current session for marketing analytics.', 'suggestedCategory' => 'marketing'],

            // Craft CMS itself (necessary — included for completeness since
            // these are exactly the kind of "already exists, not yet
            // documented" cookies the detection scan will surface)
            ['id' => 'craft_session', 'provider' => 'Craft CMS', 'name' => 'CraftSessionId', 'duration' => 'Session', 'purpose' => 'Maintains the visitor\'s session state; required for the site to function.', 'suggestedCategory' => 'necessary'],
            ['id' => 'craft_csrf', 'provider' => 'Craft CMS', 'name' => 'CRAFT_CSRF_TOKEN', 'duration' => 'Session', 'purpose' => 'Protects forms against cross-site request forgery; required for the site to function.', 'suggestedCategory' => 'necessary'],
        ];
    }
}
