<?php

namespace sfsinfotech\craftcookieconsentkit\web\assets\banner;

use craft\web\AssetBundle;

/**
 * Banner Asset Bundle — loads the cookie consent banner CSS and JS on the frontend.
 *
 * Intentionally does NOT depend on CraftCpAsset so it remains lightweight for
 * public-facing pages.
 */
class BannerAsset extends AssetBundle
{
    public $publishOptions = ['forceCopy' => true];

    public function init(): void
    {
        $this->sourcePath = __DIR__;

        $this->css = [
            'cookie-banner.css',
        ];

        $this->js = [
            'cookie-banner.js',
        ];

        parent::init();
    }
}
