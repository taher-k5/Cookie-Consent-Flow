<?php

namespace sfsinfotech\craftcookieconsentflow\web\assets\banner;

use craft\web\AssetBundle;

/**
 * Banner Asset Bundle — loads the cookie consent banner CSS and JS on the frontend.
 *
 * Intentionally does NOT depend on CraftCpAsset so it remains lightweight for
 * public-facing pages.
 */
class BannerAsset extends AssetBundle
{
    public function init(): void
    {
        // Re-publish on every request only while developing. In production the
        // published copy is refreshed when the source files change, so forcing
        // a copy per request just copies the same bytes again on every page
        // view that renders the banner through the Twig tag.
        $this->publishOptions = ['forceCopy' => \Craft::$app->getConfig()->getGeneral()->devMode];

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
