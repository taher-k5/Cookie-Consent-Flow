<?php

namespace sfsinfotech\craftcookieconsentflow\web\assets\onboarding;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The control-panel welcome tour (see helpers/Onboarding). Registered only on
 * the plugin's own pages, with its configuration in `window.cckTour`.
 */
class OnboardingAsset extends AssetBundle
{
    public function init(): void
    {
        // Development convenience only — see BannerAsset for the reasoning.
        $this->publishOptions = ['forceCopy' => \Craft::$app->getConfig()->getGeneral()->devMode];

        $this->sourcePath = __DIR__;

        // Craft.sendActionRequest() posts the "seen" preference.
        $this->depends = [
            CraftCpAsset::class,
        ];

        $this->js = [
            'onboarding.js',
        ];

        $this->css = [
            'onboarding.css',
        ];

        parent::init();
    }
}
