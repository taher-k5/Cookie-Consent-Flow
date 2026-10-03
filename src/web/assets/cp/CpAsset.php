<?php

namespace sfsinfotech\craftcookieconsentflow\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * CP Asset Bundle — loads plugin CSS and JS in the Craft control panel.
 */
class CpAsset extends AssetBundle
{
    public function init(): void
    {
        // Development convenience only — see BannerAsset for the reasoning.
        $this->publishOptions = ['forceCopy' => \Craft::$app->getConfig()->getGeneral()->devMode];

        $this->sourcePath = __DIR__;

        $this->depends = [
            CraftCpAsset::class,
        ];

        $this->js = [
            'cookie-consent.js',
        ];

        $this->css = [
            'cookie-consent.css',
        ];

        parent::init();
    }
}
