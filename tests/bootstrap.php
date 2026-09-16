<?php

/**
 * Test bootstrap.
 *
 * Locates the Composer autoloader, then stands up the smallest Yii application
 * the code under test actually needs.
 *
 * That last part is not incidental. Some of this plugin's most important logic
 * sits on classes that reach for `Craft::warning()` or `HtmlPurifier`, which in
 * turn expect `Yii::$app` to exist. Without a container those paths cannot be
 * exercised at all — and they include the XSS sanitisation of the banner
 * description and the fail-open behaviour of a geo provider that throws, which
 * are precisely the paths worth testing. So a minimal console application is
 * created here: no database, no request, no Craft services, just enough for
 * aliases, logging and caching to resolve.
 */

$autoloaders = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];

$loaded = false;
foreach ($autoloaders as $autoloader) {
    if (file_exists($autoloader)) {
        $vendorDir = dirname($autoloader);
        require_once $autoloader;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Could not locate a Composer autoloader. Run `composer install`.\n");
    exit(1);
}

// Yii and Craft register themselves as classes (Craft extends Yii) via these
// files rather than through the autoloader, so they have to be required by path.
require_once $vendorDir . '/yiisoft/yii2/Yii.php';
require_once $vendorDir . '/craftcms/cms/src/Craft.php';

$runtimePath = sys_get_temp_dir() . '/cookie-consent-flow-tests';
if (!is_dir($runtimePath)) {
    mkdir($runtimePath, 0777, true);
}

Yii::setAlias('@runtime', $runtimePath);
Yii::setAlias('@vendor', $vendorDir);
Yii::setAlias('@craft', $vendorDir . '/craftcms/cms/src');
Yii::setAlias('@sfsinfotech/craftcookieconsentflow', dirname(__DIR__) . '/src');

new yii\console\Application([
    'id'          => 'cookie-consent-flow-tests',
    'basePath'    => dirname(__DIR__),
    'runtimePath' => $runtimePath,
    'vendorPath'  => $vendorDir,
    'components'  => [
        // In-memory only. A test that quietly wrote to the developer's real
        // cache directory would leak state between runs.
        'cache' => ['class' => yii\caching\ArrayCache::class],
        'log'   => ['traceLevel' => 0, 'targets' => []],
    ],
]);
