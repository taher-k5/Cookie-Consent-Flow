<?php
// Applies global settings (JSON) to a disposable Craft project, for the browser suite.
require $argv[1] . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
$plugin = sfsinfotech\craftcookieconsentflow\Plugin::getInstance();
$values = json_decode($argv[2], true);
if (isset($values['__cookies'])) {
    $ok = $plugin->cookieDefinitions->saveAll($plugin->cookieSettings->getGlobalSettingsId(), $values['__cookies']);
    unset($values['__cookies']);
    if (!$ok) { fwrite(STDERR, implode(' ', $plugin->cookieDefinitions->getValidationErrors()) . "\n"); exit(1); }
}
if ($values && !$plugin->cookieSettings->saveGlobalSettings($values)) {
    fwrite(STDERR, implode(' ', $plugin->cookieSettings->getValidationErrors()) . "\n");
    exit(1);
}
Craft::$app->getCache()->flush();
