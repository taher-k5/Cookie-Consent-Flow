<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\web\Response;

/**
 * Settings Controller — renders and persists the plugin's CP settings.
 */
class SettingsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Editing these settings means editing HTML rendered on every
        // front-end page for every visitor — restrict beyond "logged into
        // the control panel" to a specific, grantable permission.
        $this->requirePermission('cookieConsentFlow:manageSettings');

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('cookie-consent-flow/settings/index', [
            'settings' => $settings,
            'plugin'   => Plugin::getInstance(),
        ]);
    }

    /**
     * Render the consolidated Banner settings page.
     */
    public function actionBanner(): Response
    {
        $this->requireCpRequest();

        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('cookie-consent-flow/settings/banner', [
            'settings' => $settings,
            'plugin'   => Plugin::getInstance(),
        ]);
    }

    /**
     * Save the banner settings (POST from the Banner tab).
     */
    public function actionSaveBanner(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin  = Plugin::getInstance();

        $raw = $request->getBodyParam('settings', []);

        // Normalise boolean lightswitch fields (only when they are present
        // in the submitted form). This avoids overwriting existing saved
        // values when the form doesn't contain those controls.
        $boolFields = ['bannerEnabled', 'geoEnabled', 'fullWidth', 'shadow', 'fixedPosition'];
        foreach ($boolFields as $field) {
            if (array_key_exists($field, $raw)) {
                $raw[$field] = !empty($raw[$field]);
            }
        }

        // Normalise geoTargetCountries (comma-separated string → array)
        if (isset($raw['geoTargetCountries']) && is_string($raw['geoTargetCountries'])) {
            $raw['geoTargetCountries'] = array_values(array_filter(
                array_map('trim', explode(',', strtoupper($raw['geoTargetCountries'])))
            ));
        }

        // Merge only banner fields with existing saved settings so other
        // settings (categories, logging, etc.) are never overwritten.
        $current = $plugin->getSettings()->toArray();
        $merged  = array_merge($current, $raw);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $merged)) {
            Craft::$app->getSession()->setError(Craft::t('cookie-consent-flow', 'Couldn\'t save banner settings.'));

            return $this->renderTemplate('cookie-consent-flow/settings/banner', [
                'settings' => $plugin->getSettings(),
                'plugin'   => $plugin,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('cookie-consent-flow', 'Banner settings saved.'));

        return $this->redirectToPostedUrl();
    }

    public function actionSave(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin  = Plugin::getInstance();

        $raw = $request->getBodyParam('settings', []);

        // Normalise categories array (posted as settings[categories][n][...])
        if (isset($raw['categories']) && is_array($raw['categories'])) {
            $normalised = [];
            foreach ($raw['categories'] as $cat) {
                if (!empty($cat['key'])) {
                    $normalised[] = [
                        'key'         => preg_replace('/[^a-z0-9_\-]/i', '', (string) ($cat['key'] ?? '')),
                        'label'       => (string) ($cat['label'] ?? ''),
                        'description' => (string) ($cat['description'] ?? ''),
                        'default'     => !empty($cat['default']),
                        'locked'      => !empty($cat['locked']),
                    ];
                }
            }
            $raw['categories'] = $normalised;
        }

        // Normalise boolean lightswitch fields (only when present in the
        // posted data). Craft sends '1' or '' for on/off.
        $boolFields = ['bannerEnabled', 'geoEnabled', 'logEnabled', 'fullWidth', 'shadow', 'fixedPosition'];
        foreach ($boolFields as $field) {
            if (array_key_exists($field, $raw)) {
                $raw[$field] = !empty($raw[$field]);
            }
        }

        // Normalise geoTargetCountries (comma-separated string → array)
        if (isset($raw['geoTargetCountries']) && is_string($raw['geoTargetCountries'])) {
            $raw['geoTargetCountries'] = array_filter(
                array_map('trim', explode(',', strtoupper($raw['geoTargetCountries'])))
            );
        }

        // Merge posted values with current settings so fields not present
        // in the submitted form (for example banner colours from the
        // Banner tab) are preserved.
        $current = $plugin->getSettings()->toArray();
        $merged  = array_merge($current, $raw);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $merged)) {
            Craft::$app->getSession()->setError(Craft::t('cookie-consent-flow', 'Couldn\'t save settings.'));

            $redirect = $request->getBodyParam('redirect');
            $template = strpos((string)$redirect, '/banner') !== false
                ? 'cookie-consent-flow/settings/banner'
                : 'cookie-consent-flow/settings/index';

            return $this->renderTemplate($template, [
                'settings' => $plugin->getSettings(),
                'plugin'   => $plugin,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('cookie-consent-flow', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}

