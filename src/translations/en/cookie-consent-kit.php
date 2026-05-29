<?php
/**
 * Cookie Consent Kit — English translations.
 *
 * Add a translations/<locale>/cookie-consent-kit.php file for each additional
 * language you want to support. Craft will automatically load the correct file
 * based on the current site locale.
 *
 * @see https://craftcms.com/docs/5.x/system/sites.html#static-translations
 */

return [
    // General
    'Cookie Consent'         => 'Cookie Consent',
    'Cookie Consent Kit'     => 'Cookie Consent Kit',
    'Settings saved.'        => 'Settings saved.',

    // Dashboard
    'Dashboard'              => 'Dashboard',
    'Total Consents Logged'  => 'Total Consents Logged',
    'Accepted All'           => 'Accepted All',
    'Rejected All'           => 'Rejected All',
    'No data yet. Consent logging will appear here once enabled.' => 'No data yet. Consent logging will appear here once enabled.',

    // Settings
    'Cookie Consent Kit Settings' => 'Cookie Consent Kit Settings',
    'Banner'                 => 'Banner',
    'Enable Cookie Banner'   => 'Enable Cookie Banner',
    'Show the consent banner to site visitors.' => 'Show the consent banner to site visitors.',
    'Banner Position'        => 'Banner Position',
    'Where on the screen the banner should appear.' => 'Where on the screen the banner should appear.',
    'Bottom'                 => 'Bottom',
    'Top'                    => 'Top',
    'Bottom Left'            => 'Bottom Left',
    'Bottom Right'           => 'Bottom Right',
    'Banner Heading'         => 'Banner Heading',
    'Leave blank to use the default heading.' => 'Leave blank to use the default heading.',
    'Banner Body Text'       => 'Banner Body Text',
    'Describe how your site uses cookies. HTML is allowed.' => 'Describe how your site uses cookies. HTML is allowed.',
    'Consent Logging'        => 'Consent Logging',
    'Log Consent Events'     => 'Log Consent Events',
    'Store a privacy-safe record of each consent action in the database.' => 'Store a privacy-safe record of each consent action in the database.',
    'Log Retention (days)'   => 'Log Retention (days)',
    'How long to keep consent records. Set to 0 to keep indefinitely.' => 'How long to keep consent records. Set to 0 to keep indefinitely.',
    'Geo-targeting'          => 'Geo-targeting',
    'Enable Geo-targeting'   => 'Enable Geo-targeting',
    'Restrict banner display to specific countries.' => 'Restrict banner display to specific countries.',
    'Target Countries'       => 'Target Countries',
    "Comma-separated ISO 3166-1 alpha-2 codes, e.g. GB,DE,FR. Leave blank to show everywhere." => "Comma-separated ISO 3166-1 alpha-2 codes, e.g. GB,DE,FR. Leave blank to show everywhere.",

    // Widget
    'Consent Overview'       => 'Consent Overview',

    // Categories (dynamic, used via ConsentHelper::categoryLabel())
    'Necessary'              => 'Necessary',
    'Analytics'              => 'Analytics',
    'Marketing'              => 'Marketing',
    'Preferences'            => 'Preferences',
];
