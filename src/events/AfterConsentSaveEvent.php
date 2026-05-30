<?php

namespace sfsinfotech\craftcookieconsentflow\events;

use yii\base\Event;

/**
 * AfterConsentSaveEvent — fired after a visitor's consent has been persisted.
 *
 * Use this event to trigger downstream integrations (analytics init,
 * tag-manager data-layer pushes, etc.).
 */
class AfterConsentSaveEvent extends Event
{
    /**
     * The action the visitor took.
     * One of: 'accept_all' | 'reject_all' | 'custom'
     */
    public string $action = '';

    /**
     * The category keys the visitor accepted.
     * e.g. ['necessary', 'analytics']
     *
     * @var string[]
     */
    public array $categories = [];

    /** The anonymous UUID that was stored in the visitor's browser cookie. */
    public string $visitorUuid = '';
}
