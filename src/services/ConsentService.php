<?php

namespace sfsinfotech\craftcookieconsentkit\services;

use craft\base\Component;

/**
 * Consent Service — handles reading, writing, and managing user consent records.
 *
 * TODO: Implement consent logic in future iterations.
 */
class ConsentService extends Component
{
    // Placeholder: record a user's consent choices.
    public function saveConsent(): void
    {
        // TODO: persist consent record to {{%cookieconsent_log}}
    }

    // Placeholder: retrieve the latest consent record for the current visitor.
    public function getConsent(): ?array
    {
        // TODO: look up by cookie UUID / IP hash
        return null;
    }

    // Placeholder: purge log records older than the configured retention period.
    public function purgeOldLogs(): void
    {
        // TODO: delete records past Settings::$logRetentionDays
    }
}
