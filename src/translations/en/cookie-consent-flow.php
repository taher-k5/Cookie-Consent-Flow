<?php

/**
 * Cookie Consent Flow — English source strings.
 *
 * Every user-facing string in the plugin goes through
 * `Craft::t('cookie-consent-flow', …)` or the `|t('cookie-consent-flow')` Twig
 * filter, using the English text as its own key. Craft falls back to the key
 * when a translation is missing, so this file is not required for English to
 * work — it exists as the catalogue of translatable strings.
 *
 * To translate, copy this file to `translations/<locale>/cookie-consent-flow.php`
 * in your Craft project (or add one to this plugin) and replace the values. Craft
 * loads the right file for the current site's locale automatically.
 *
 *   translations/de/cookie-consent-flow.php
 *   translations/fr/cookie-consent-flow.php
 *
 * Note the difference between the two kinds of text in this plugin:
 *
 * - **Plugin UI** (everything below) is translated here, per locale.
 * - **Cookie and category content** — banner heading, description, button
 *   labels, category names and descriptions, cookie purposes — is *not*
 *   translated here. It is admin-authored content, and on a multi-site install
 *   each site overrides it on the Multisite page, which is what lets a German
 *   site carry German banner text.
 *
 * @see https://craftcms.com/docs/5.x/system/sites.html#static-translations
 */

return [
    // Navigation and page titles
    'Cookie Consent' => 'Cookie Consent',
    'Cookie Consent Flow' => 'Cookie Consent Flow',
    'Cookie Consent Flow Settings' => 'Cookie Consent Flow Settings',
    'Dashboard' => 'Dashboard',
    'Banner' => 'Banner',
    'Cookies' => 'Cookies',
    'Consent Records' => 'Consent Records',
    'Consent Record' => 'Consent Record',
    'Multisite' => 'Multisite',
    'Settings' => 'Settings',

    // Permissions — these are the three Permissions::definition() actually
    // registers. The five finer-grained "Manage …" labels that used to sit
    // here were removed along with the permissions themselves, which were not
    // real enforcement boundaries.
    'Manage cookie consent configuration' => 'Manage cookie consent configuration',
    'View consent records' => 'View consent records',
    'Export consent records' => 'Export consent records',

    // Dashboard
    'Consent Overview' => 'Consent Overview',
    'Accepted all' => 'Accepted all',
    'Rejected all' => 'Rejected all',
    'Partial consent' => 'Partial consent',
    'Total decisions' => 'Total decisions',
    'Live Banner Preview' => 'Live Banner Preview',

    // Settings — categories
    'Cookie Categories' => 'Cookie Categories',
    'Add Category' => 'Add Category',
    'Remove category' => 'Remove category',
    'Key' => 'Key',
    'Label' => 'Label',
    'Description' => 'Description',
    'Enabled by default' => 'Enabled by default',
    'Always on (locked)' => 'Always on (locked)',
    'Google Consent Mode signals' => 'Google Consent Mode signals',

    // Settings — logging and retention
    'Consent Logging' => 'Consent Logging',
    'Log Consent Events' => 'Log Consent Events',
    'Log Retention (days)' => 'Log Retention (days)',

    // Settings — integrations
    'Integrations' => 'Integrations',
    'Enable Google Consent Mode v2' => 'Enable Google Consent Mode v2',
    'Implementation' => 'Implementation',
    'Advanced' => 'Advanced',
    'Basic' => 'Basic',
    'URL passthrough' => 'URL passthrough',
    'Ads data redaction' => 'Ads data redaction',

    // Settings — privacy signals
    'Privacy Signals' => 'Privacy Signals',
    'Honour Global Privacy Control (GPC)' => 'Honour Global Privacy Control (GPC)',
    'Honour Do Not Track (DNT)' => 'Honour Do Not Track (DNT)',

    // Settings — advanced
    'Consent Expiry (days)' => 'Consent Expiry (days)',
    'Policy Version' => 'Policy Version',
    'Invalidate Existing Consent' => 'Invalidate Existing Consent',

    // Geo-targeting
    'Geo-targeting' => 'Geo-targeting',
    'Enable Geo-targeting' => 'Enable Geo-targeting',
    'Target Countries' => 'Target Countries',

    // Consent records
    'Site' => 'Site',
    'All Sites' => 'All Sites',
    'Date' => 'Date',
    'Outcome' => 'Outcome',
    'Source' => 'Source',
    'Category' => 'Category',
    'Categories' => 'Categories',
    'Country' => 'Country',
    'Policy version' => 'Policy version',
    'Visitor UUID' => 'Visitor UUID',
    'IP Hash' => 'IP Hash',
    'Accept All' => 'Accept All',
    'Reject All' => 'Reject All',
    'Custom' => 'Custom',
    'All' => 'All',
    'Any' => 'Any',
    'From' => 'From',
    'To' => 'To',
    'Filter' => 'Filter',
    'Clear' => 'Clear',
    'Export' => 'Export',
    'Export CSV' => 'Export CSV',
    'Export JSON' => 'Export JSON',
    'No consent records found.' => 'No consent records found.',

    // Consent sources
    'Global Privacy Control' => 'Global Privacy Control',
    'Do Not Track' => 'Do Not Track',
    'JavaScript API' => 'JavaScript API',

    // Cookie table (front end)
    'Name' => 'Name',
    'Provider' => 'Provider',
    'Purpose' => 'Purpose',
    'Duration' => 'Duration',
    'No cookies have been documented yet.' => 'No cookies have been documented yet.',

    // Banner (front end)
    'Cookie consent' => 'Cookie consent',
    'Cookie consent options' => 'Cookie consent options',
    'always active' => 'always active',
    'Reset Cookie Preferences' => 'Reset Cookie Preferences',

    // Flash messages
    'Settings saved.' => 'Settings saved.',
    'Banner settings saved.' => 'Banner settings saved.',
    'Cookies saved.' => 'Cookies saved.',
    'Multisite reset.' => 'Multisite reset.',
    'Multisite copied.' => 'Multisite copied.',
    "Couldn't save settings." => "Couldn't save settings.",
    "Couldn't save cookies." => "Couldn't save cookies.",
    "Couldn't invalidate existing consent." => "Couldn't invalidate existing consent.",
    'That site no longer exists.' => 'That site no longer exists.',

    // Every remaining user-facing string in the plugin, so this file is the
    // complete catalogue a translator works from rather than a partial one.
    // English needs none of them — Craft falls back to the key — but a
    // translator cannot translate what is not listed.
    'A quick comparison of the records in the current view.' => 'A quick comparison of the records in the current view.',
    'Accept All Button' => 'Accept All Button',
    'Accept Button' => 'Accept Button',
    'Accepted All' => 'Accepted All',
    "Accessible name and tooltip for the preference centre's close icon." => "Accessible name and tooltip for the preference centre's close icon.",
    'Add Cookie' => 'Add Cookie',
    'Add' => 'Add',
    'Cookie' => 'Cookie',
    'Add from Library' => 'Add from Library',
    'Additional plugin-level options.' => 'Additional plugin-level options.',
    'Advanced: Google tags load immediately with storage denied, and receive an update once the visitor decides. Basic: no Google tag loads until its category is accepted — tag them with data-cck-category.' => 'Advanced: Google tags load immediately with storage denied, and receive an update once the visitor decides. Basic: no Google tag loads until its category is accepted — tag them with data-cck-category.',
    'All actions' => 'All actions',
    'All recorded decisions' => 'All recorded decisions',
    'An error occurred.' => 'An error occurred.',
    'Ask every visitor for consent again? This cannot be undone, and existing consent records are kept.' => 'Ask every visitor for consent again? This cannot be undone, and existing consent records are kept.',
    'Auto-inject into <head>' => 'Auto-inject into <head>',
    'Background' => 'Background',
    'Banner Background' => 'Banner Background',
    'Banner Border' => 'Banner Border',
    'Banner Colours' => 'Banner Colours',
    'Banner Layout' => 'Banner Layout',
    'Banner Settings' => 'Banner Settings',
    'Banner Text' => 'Banner Text',
    'Border' => 'Border',
    'Border Radius' => 'Border Radius',
    'Bottom Bar' => 'Bottom Bar',
    'Bottom Left' => 'Bottom Left',
    'Bottom Right' => 'Bottom Right',
    'Button Labels' => 'Button Labels',
    'Center Popup' => 'Center Popup',
    'Choose how and where the banner appears on the page. On mobile screens, the banner always appears as a bottom bar regardless of this setting, for a more reliable small-screen layout.' => 'Choose how and where the banner appears on the page. On mobile screens, the banner always appears as a bottom bar regardless of this setting, for a more reliable small-screen layout.',
    'Close Button' => 'Close Button',
    'Close Icon' => 'Close Icon',
    'Close Icon Colour' => 'Close Icon Colour',
    'Collapse All Sections' => 'Collapse All Sections',
    'Configure the cookie categories visitors can toggle in the preferences panel.' => 'Configure the cookie categories visitors can toggle in the preferences panel.',
    'Consent outcomes' => 'Consent outcomes',
    'Content' => 'Content',
    'Cookie Name' => 'Cookie Name',
    'Copy' => 'Copy',
    'Copy From' => 'Copy From',
    'Copy Global Settings' => 'Copy Global Settings',
    'Corner Popup' => 'Corner Popup',
    'Corner Position' => 'Corner Position',
    "Couldn't copy Multisite." => "Couldn't copy Multisite.",
    "Couldn't reset Multisite." => "Couldn't reset Multisite.",
    "Couldn't save banner settings." => "Couldn't save banner settings.",
    'Countries where the banner should be shown. Leave blank to show everywhere.' => 'Countries where the banner should be shown. Leave blank to show everywhere.',
    'Customize Button' => 'Customize Button',
    'Desktop' => 'Desktop',
    'Disabled' => 'Disabled',
    'Dismiss' => 'Dismiss',
    'Display the consent banner to all site visitors.' => 'Display the consent banner to all site visitors.',
    'DNT carries no general legal force and some browsers enable it by default, so it is a weaker signal of intent than GPC. Off unless you have decided to treat it as a rejection.' => 'DNT carries no general legal force and some browsers enable it by default, so it is a weaker signal of intent than GPC. Off unless you have decided to treat it as a rejection.',
    'Document' => 'Document',
    "Document each cookie your site actually sets, grouped by category. GDPR/ePrivacy expect visitors to be told WHAT cookies are used and why, not just a category-level description — this list is shown to visitors in the preferences panel alongside each category. It's disclosure content only: it doesn't block anything itself — blocking is still controlled by tagging scripts/iframes with data-cck-category (see the plugin README). Site-specific cookies (where a site's list differs from Global) are managed on the Multisite page." => "Document each cookie your site actually sets, grouped by category. GDPR/ePrivacy expect visitors to be told WHAT cookies are used and why, not just a category-level description — this list is shown to visitors in the preferences panel alongside each category. It's disclosure content only: it doesn't block anything itself — blocking is still controlled by tagging scripts/iframes with data-cck-category (see the plugin README). Site-specific cookies (where a site's list differs from Global) are managed on the Multisite page.",
    'Done.' => 'Done.',
    'e.g. 2 years, Session.' => 'e.g. 2 years, Session.',
    'e.g. 24px or 16px 24px' => 'e.g. 24px or 16px 24px',
    'e.g. 8px or 0' => 'e.g. 8px or 0',
    'Edit Banner' => 'Edit Banner',
    'Edit Settings' => 'Edit Settings',
    'Enable Cookie Banner' => 'Enable Cookie Banner',
    'Enabled' => 'Enabled',
    'Every stored consent carries the policy version in effect when it was given. Change this and each visitor is asked again.' => 'Every stored consent carries the policy version in effect when it was given. Change this and each visitor is asked again.',
    'Existing consent invalidated — visitors will be asked again. Policy version is now {version}.' => 'Existing consent invalidated — visitors will be asked again. Policy version is now {version}.',
    'Existing consent records are not deleted: they remain accurate evidence of what each visitor agreed to under the previous policy.' => 'Existing consent records are not deleted: they remain accurate evidence of what each visitor agreed to under the previous policy.',
    'Expand All Sections' => 'Expand All Sections',
    'Explain how your site uses cookies. Basic HTML (links, strong) is allowed.' => 'Explain how your site uses cookies. Basic HTML (links, strong) is allowed.',
    'Exports exactly the records matching the filters above.' => 'Exports exactly the records matching the filters above.',
    'Fixed / Sticky Position' => 'Fixed / Sticky Position',
    'Full Width' => 'Full Width',
    'General' => 'General',
    'Geo' => 'Geo',
    "Google Consent Mode tells Google's own tags what they may store. It is not a replacement for blocking — tag this site's Google scripts with data-cck-category as well." => "Google Consent Mode tells Google's own tags what they may store. It is not a replacement for blocking — tag this site's Google scripts with data-cck-category as well.",
    'GPC is an explicit, deliberate signal and is recognised by several US state privacy laws. Recommended.' => 'GPC is an explicit, deliberate signal and is recognised by several US state privacy laws. Recommended.',
    'Heading' => 'Heading',
    'Hover Background' => 'Hover Background',
    'Hover Text' => 'Hover Text',
    "How long a visitor's consent stays valid before the banner is shown again. Set to 0 to never expire. Guidance (ICO/CNIL) recommends re-asking within 6–12 months." => "How long a visitor's consent stays valid before the banner is shown again. Set to 0 to never expire. Guidance (ICO/CNIL) recommends re-asking within 6–12 months.",
    'How long Google waits for a consent update before acting on the default state. 500 suits most sites; 0 disables the wait.' => 'How long Google waits for a consent update before acting on the default state. 500 suits most sites; 0 disables the wait.',
    'How long to keep consent records. Set to 0 to keep indefinitely.' => 'How long to keep consent records. Set to 0 to keep indefinitely.',
    'Inherited' => 'Inherited',
    'Keep the banner fixed on screen while the user scrolls.' => 'Keep the banner fixed on screen while the user scrolls.',
    'Last Updated' => 'Last Updated',
    'Layout Controls' => 'Layout Controls',
    'Links' => 'Links',
    'Live Preview' => 'Live Preview',
    'Logo' => 'Logo',
    'Machine-readable identifier, e.g. analytics (lowercase, no spaces).' => 'Machine-readable identifier, e.g. analytics (lowercase, no spaces).',
    'Main heading shown at the top of the banner.' => 'Main heading shown at the top of the banner.',
    'Manage Multisite →' => 'Manage Multisite →',
    'Max Height' => 'Max Height',
    'Max Width' => 'Max Width',
    'Maximum width and height for popup layouts (center and corner). Ignored for bar layouts. Max Width is ignored when Full Width is enabled. Examples: 600px (width), 80vh or 600px (height). Leave blank for no limit.' => 'Maximum width and height for popup layouts (center and corner). Ignored for bar layouts. Max Width is ignored when Full Width is enabled. Examples: 600px (width), 80vh or 600px (height). Leave blank for no limit.',
    'Mobile' => 'Mobile',
    'No consent has been recorded for this site yet. Figures appear here once visitors start responding to the banner.' => 'No consent has been recorded for this site yet. Figures appear here once visitors start responding to the banner.',
    'No cookies documented yet.' => 'No cookies documented yet.',
    'No data yet. Consent logging will appear here once enabled.' => 'No data yet. Consent logging will appear here once enabled.',
    'No overrides' => 'No overrides',
    'No settings match your filters.' => 'No settings match your filters.',
    'of decisions' => 'of decisions',
    'Optional logo shown in the banner and preferences panel.' => 'Optional logo shown in the banner and preferences panel.',
    'Overlay (popups)' => 'Overlay (popups)',
    'Overridden' => 'Overridden',
    'Padding' => 'Padding',
    'Pass ad click information through URLs when ad storage is denied, so conversions can still be measured without cookies.' => 'Pass ad click information through URLs when ad storage is denied, so conversions can still be measured without cookies.',
    'Place the snippet as the first thing in <head> automatically. Turn off only if you need to position it yourself with craft.cookieConsent.consentModeScript().' => 'Place the snippet as the first thing in <head> automatically. Turn off only if you need to position it yourself with craft.cookieConsent.consentModeScript().',
    'Pre-check this category in the preferences panel.' => 'Pre-check this category in the preferences panel.',
    "Pre-filled entries for common third-party cookies (Google Analytics, Meta Pixel, etc.) — verify duration/purpose against the provider's current docs before relying on it." => "Pre-filled entries for common third-party cookies (Google Analytics, Meta Pixel, etc.) — verify duration/purpose against the provider's current docs before relying on it.",
    'Preview device size' => 'Preview device size',
    'Previewing site' => 'Previewing site',
    'Privacy Policy Link Label' => 'Privacy Policy Link Label',
    'Privacy Policy URL' => 'Privacy Policy URL',
    'records' => 'records',
    'Redact ad click identifiers in network requests while ad storage is denied.' => 'Redact ad click identifiers in network requests while ad storage is denied.',
    'Reject All Button' => 'Reject All Button',
    'Reject Button' => 'Reject Button',
    'Rejected All' => 'Rejected All',
    'Remove' => 'Remove',
    'Remove cookie' => 'Remove cookie',
    'Remove every override for this site and return it to Global Settings?' => 'Remove every override for this site and return it to Global Settings?',
    'Replace this site’s overrides with a copy of the selected site’s? This cannot be undone.' => 'Replace this site’s overrides with a copy of the selected site’s? This cannot be undone.',
    'Reset Multisite' => 'Reset Multisite',
    'Site Settings' => 'Site Settings',
    'Restrict banner display to specific countries only.' => 'Restrict banner display to specific countries only.',
    'Salted per record — cannot be reversed to an IP address, and cannot be matched against other records.' => 'Salted per record — cannot be reversed to an IP address, and cannot be matched against other records.',
    'Save Preferences Button' => 'Save Preferences Button',
    'Saved before section-level toggles existed — switch "Use Global Settings" off then on again to normalise it.' => 'Saved before section-level toggles existed — switch "Use Global Settings" off then on again to normalise it.',
    'Search settings' => 'Search settings',
    'Search settings…' => 'Search settings…',
    'Send consent signals to Google tags. Map each category to its signals on the Cookie Categories tab.' => 'Send consent signals to Google tags. Map each category to its signals on the Cookie Categories tab.',
    'Sets a new policy version, so every visitor is asked to consent again on their next visit. Do this after a material change — a new category, a new tracker, a changed purpose.' => 'Sets a new policy version, so every visitor is asked to consent again on their next visit. Do this after a material change — a new category, a new tracker, a changed purpose.',
    'Shadow' => 'Shadow',
    'Show a drop shadow around the banner.' => 'Show a drop shadow around the banner.',
    'Showing' => 'Showing',
    'Signals granted when a visitor accepts this category. Only used when Consent Mode is enabled; leave empty to grant none.' => 'Signals granted when a visitor accepts this category. Only used when Consent Mode is enabled; leave empty to grant none.',
    'Site Override' => 'Site Override',
    'Size Limits' => 'Size Limits',
    'Some browsers send a machine-readable privacy preference. When one is honoured, the visitor is treated as having rejected every optional category and is not shown the banner.' => 'Some browsers send a machine-readable privacy preference. When one is honoured, the visitor is treated as having rejected every optional category and is not shown the banner.',
    'Store a privacy-safe record of each consent action in the database.' => 'Store a privacy-safe record of each consent action in the database.',
    'Stretch the banner to the full viewport width.' => 'Stretch the banner to the full viewport width.',
    'Tablet' => 'Tablet',
    'Text' => 'Text',
    'The exact cookie name, or a pattern like _ga_* for a family of cookies.' => 'The exact cookie name, or a pattern like _ga_* for a family of cookies.',
    'These cookie names were actually seen in a visitor\'s browser (names only — never values) but aren\'t documented below yet. "Document" adds a row pre-filled with the name; "Dismiss" hides it here if it doesn\'t need visitor-facing disclosure (e.g. a first-party technical cookie).' => 'These cookie names were actually seen in a visitor\'s browser (names only — never values) but aren\'t documented below yet. "Document" adds a row pre-filled with the name; "Dismiss" hides it here if it doesn\'t need visitor-facing disclosure (e.g. a first-party technical cookie).',
    'These settings override Global Settings for an individual site. Each section below has its own "Use Global Settings" toggle — leave it on to inherit every value in that section from Global Settings, or turn it off to edit and save site-specific values just for that section.' => 'These settings override Global Settings for an individual site. Each section below has its own "Use Global Settings" toggle — leave it on to inherit every value in that section from Global Settings, or turn it off to edit and save site-specific values just for that section.',
    'This is how your cookie banner currently looks to visitors, using your saved Banner settings.' => 'This is how your cookie banner currently looks to visitors, using your saved Banner settings.',
    'This site currently inherits all Global Settings.' => 'This site currently inherits all Global Settings.',
    'Top Bar' => 'Top Bar',
    'Total' => 'Total',
    'Total Records' => 'Total Records',
    'Use Global Cookies' => 'Use Global Cookies',
    'Use Global Settings' => 'Use Global Settings',
    'View' => 'View',
    'View all consent logs' => 'View all consent logs',
    'View Consent Records' => 'View Consent Records',
    'Visitor cannot disable this category.' => 'Visitor cannot disable this category.',
    'wait_for_update (ms)' => 'wait_for_update (ms)',
    'What this cookie is used for — shown to visitors in the preferences panel.' => 'What this cookie is used for — shown to visitors in the preferences panel.',
    'Who sets this cookie, e.g. Google Analytics.' => 'Who sets this cookie, e.g. Google Analytics.',
    'Multisite Saved — {count, plural, =1{1 overridden setting} other{# overridden settings}}' => 'Multisite Saved — {count, plural, =1{1 overridden setting} other{# overridden settings}}',

    // Strings that take parameters (counts, field names, site names).
    'Cookies used ({count})' => 'Cookies used ({count})',
    "Couldn't save Multisite — no site was changed." => "Couldn't save Multisite — no site was changed.",
    "Couldn't save Multisite — no site was changed. Check {site}." => "Couldn't save Multisite — no site was changed. Check {site}.",
    'Deleted Site (ID: {id})' => 'Deleted Site (ID: {id})',
    'Detected, not yet documented' => 'Detected, not yet documented',
    'Page {current} of {total}' => 'Page {current} of {total}',
    "This cookie's category no longer exists, so visitors are not shown it. Pick a current category, or remove the row." => "This cookie's category no longer exists, so visitors are not shown it. Pick a current category, or remove the row.",
    'Visitors are not shown these, because the category they belong to is gone — renamed or deleted on the Settings page. Each one is flagged below: give it a current category, or remove it.' => 'Visitors are not shown these, because the category they belong to is gone — renamed or deleted on the Settings page. Each one is flagged below: give it a current category, or remove it.',
    '{count, plural, =1{1 cookie is filed under a category that no longer exists} other{# cookies are filed under categories that no longer exist}}' => '{count, plural, =1{1 cookie is filed under a category that no longer exists} other{# cookies are filed under categories that no longer exist}}',
    '{count, plural, =1{1 Override} other{# Overrides}}' => '{count, plural, =1{1 Override} other{# Overrides}}',
    '{count}/{total} Overridden' => '{count}/{total} Overridden',
    '{field}: unexpected value.' => '{field}: unexpected value.',
    '{key} (category no longer exists)' => '{key} (category no longer exists)',
    '{label}: {count} records, {percent}%' => '{label}: {count} records, {percent}%',
];
