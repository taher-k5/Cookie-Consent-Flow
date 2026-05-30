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

        // Normalise boolean lightswitch fields
        $boolFields = ['bannerEnabled', 'geoEnabled', 'fullWidth', 'shadow', 'fixedPosition'];
        foreach ($boolFields as $field) {
            $raw[$field] = !empty($raw[$field]);
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

        // Normalise boolean lightswitch fields (Craft sends '1' or '')
        $boolFields = ['bannerEnabled', 'geoEnabled', 'logEnabled', 'fullWidth', 'shadow', 'fixedPosition'];
        foreach ($boolFields as $field) {
            $raw[$field] = !empty($raw[$field]);
        }

        // Normalise geoTargetCountries (comma-separated string → array)
        if (isset($raw['geoTargetCountries']) && is_string($raw['geoTargetCountries'])) {
            $raw['geoTargetCountries'] = array_filter(
                array_map('trim', explode(',', strtoupper($raw['geoTargetCountries'])))
            );
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $raw)) {
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

