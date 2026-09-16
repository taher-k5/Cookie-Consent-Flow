<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use craft\base\Component;
use craft\helpers\Json;
use sfsinfotech\craftcookieconsentflow\models\Settings;

/**
 * Consent Mode Service — builds the Google Consent Mode v2 snippet.
 *
 * Two implementations are supported, and they are genuinely different things:
 *
 * - **Advanced.** A conservative `gtag('consent', 'default', …)` is emitted
 *   before any Google tag runs, with every optional signal `denied`. Google
 *   tags may then load and send cookieless pings; once the visitor decides,
 *   the front-end runtime sends `gtag('consent', 'update', …)`. Nothing is
 *   stored on the visitor's device until the matching signal is granted.
 *
 * - **Basic.** No default command is emitted and no Google tag is expected to
 *   load before consent — Google tags must themselves carry
 *   `data-cck-category`, so the existing blocking convention is what enforces
 *   consent. A `consent update` is still sent after a decision, for tags that
 *   load afterwards.
 *
 * Consent Mode is *not* a substitute for blocking. In advanced mode the
 * Google tag still executes; it is Google's SDK that is being told not to use
 * storage. Anything that is not a Google tag is unaffected either way.
 *
 * ## Cache safety
 *
 * The emitted snippet contains no visitor-specific data whatsoever — it is
 * identical for every visitor and therefore safe in fully cached HTML. The
 * visitor's actual decision is read from browser storage by the snippet
 * itself, at runtime, on the visitor's own device.
 */
class ConsentModeService extends Component
{
    /**
     * Every Google Consent Mode v2 signal. `ad_user_data` and
     * `ad_personalization` are the two v2 added to the original four.
     */
    public const SIGNALS = [
        'ad_storage',
        'ad_user_data',
        'ad_personalization',
        'analytics_storage',
        'functionality_storage',
        'personalization_storage',
        'security_storage',
    ];

    /**
     * Signals that are always granted by default, because denying them would
     * break security features Google treats as non-optional (and which do not
     * depend on consent under the ePrivacy "strictly necessary" carve-out).
     * Everything else defaults to `denied` — see buildDefaultState().
     */
    public const ALWAYS_GRANTED = ['security_storage'];

    /**
     * Whether a `<script>` has already been emitted for this request, so the
     * auto-injection hook and an explicit `craft.cookieConsent.consentModeScript()`
     * call can coexist without emitting the default command twice (a second
     * `default` after a tag has loaded is ignored by Google, but it is noise
     * in the data layer and confusing to debug).
     */
    private bool $_emitted = false;

    /**
     * Builds the conservative default consent state: every optional signal
     * `denied`, regardless of how categories are mapped. Locked categories do
     * *not* pre-grant their signals — a locked category means the visitor
     * cannot turn it off, not that Google may use storage before the page has
     * told it the visitor's state.
     *
     * @return array<string, string>
     */
    public function buildDefaultState(): array
    {
        $state = [];

        foreach (self::SIGNALS as $signal) {
            $state[$signal] = in_array($signal, self::ALWAYS_GRANTED, true) ? 'granted' : 'denied';
        }

        return $state;
    }

    /**
     * Resolves a set of accepted category keys into a full signal state.
     * A signal is granted when *any* accepted category maps to it; every
     * signal the configuration doesn't mention stays denied.
     *
     * This is the server-side mirror of what the front-end runtime computes
     * from the same mapping, and exists so the mapping can be unit-tested and
     * inspected from PHP without a browser.
     *
     * @param  string[] $acceptedCategories
     * @return array<string, string>
     */
    public function buildStateForCategories(Settings $settings, array $acceptedCategories): array
    {
        $state = $this->buildDefaultState();

        foreach ($settings->getCategoryGcmSignals() as $categoryKey => $signals) {
            if (!in_array($categoryKey, $acceptedCategories, true)) {
                continue;
            }

            foreach ($signals as $signal) {
                $state[$signal] = 'granted';
            }
        }

        return $state;
    }

    /**
     * Renders the snippet for `<head>`, ahead of any Google tag.
     *
     * Returns an empty string when Consent Mode is disabled, when basic mode
     * is selected (which deliberately emits no default command), or when a
     * snippet has already been emitted for this request.
     *
     * @param bool $markEmitted Pass false to render without claiming the
     *                          once-per-request slot (used by the CP preview).
     */
    public function renderScript(Settings $settings, bool $markEmitted = true): string
    {
        if (!$settings->consentModeEnabled || $this->_emitted) {
            return '';
        }

        if ($markEmitted) {
            $this->_emitted = true;
        }

        $lines = [
            'window.dataLayer = window.dataLayer || [];',
            'function gtag(){dataLayer.push(arguments);}',
        ];

        if ($settings->consentModeType === 'advanced') {
            $default = $this->buildDefaultState();

            if ($settings->consentModeWaitForUpdate > 0) {
                $default['wait_for_update'] = $settings->consentModeWaitForUpdate;
            }

            $lines[] = 'gtag(' . Json::encode('consent') . ',' . Json::encode('default') . ','
                . Json::encode($default) . ');';
        }

        if ($settings->consentModeAdsDataRedaction) {
            $lines[] = "gtag('set','ads_data_redaction',true);";
        }

        if ($settings->consentModeUrlPassthrough) {
            $lines[] = "gtag('set','url_passthrough',true);";
        }

        // Replay a decision the visitor already made, before any Google tag
        // gets a chance to act on the denied default. Read from the visitor's
        // own browser storage rather than rendered server-side, so this stays
        // identical in cached HTML for every visitor.
        $lines[] = $this->_storageReplayJs($settings);

        return "<script>\n" . implode("\n", $lines) . "\n</script>";
    }

    /**
     * The inline replay routine: reads the stored decision for this site,
     * validates it against the current policy version and expiry exactly the
     * way the main runtime does, maps its categories onto signals, and sends
     * a single `consent update`.
     *
     * Deliberately duplicated here rather than deferred to cookie-banner.js:
     * this must run in `<head>` before Google tags, whereas cookie-banner.js
     * is deferred to after the document parses. Keeping it to one self-
     * contained function (a few hundred bytes) is cheaper than blocking the
     * parser on the full runtime.
     *
     * It reads localStorage **and** the cookie the runtime falls back to when
     * localStorage is unavailable (private browsing, blocked site data), for
     * the same reason the runtime has that fallback: otherwise a visitor who
     * has granted consent is replayed as though they had not, and every
     * Google tag on the page acts on the denied default until the deferred
     * runtime catches up. Reading the visitor's own cookie here is the
     * browser doing it at runtime — nothing visitor-specific is rendered into
     * this snippet, so it stays identical for every visitor and safe to cache.
     */
    private function _storageReplayJs(Settings $settings): string
    {
        $config = Json::encode([
            'key'           => 'cck_consent_' . \Craft::$app->getSites()->getCurrentSite()->id,
            'signals'       => $settings->getCategoryGcmSignals(),
            'policyVersion' => $settings->policyVersion,
            'expiryDays'    => $settings->consentExpiryDays,
        ]);

        return <<<JS
(function(c){try{
var raw=null;try{raw=localStorage.getItem(c.key);}catch(e){}
if(!raw){var m=document.cookie.match(new RegExp('(?:^|; )'+c.key.replace(/[.*+?^\${}()|[\]\\\\]/g,'\\\\\$&')+'=([^;]*)'));
if(m)raw=decodeURIComponent(m[1]);}
if(!raw)return;var s=JSON.parse(raw);
if(!s||!Array.isArray(s.categories))return;
if(c.policyVersion&&s.policyVersion!==c.policyVersion)return;
if(c.expiryDays&&s.timestamp&&Date.now()-s.timestamp>c.expiryDays*864e5)return;
var u={};for(var k in c.signals){if(s.categories.indexOf(k)===-1)continue;
c.signals[k].forEach(function(g){u[g]='granted';});}
if(Object.keys(u).length)gtag('consent','update',u);
}catch(e){}}({$config}));
JS;
    }
}
