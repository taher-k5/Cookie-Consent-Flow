<?php

namespace sfsinfotech\craftcookieconsentflow\events;

use yii\base\Event;

/**
 * AfterConsentSaveEvent — fired after a visitor's consent record has been
 * saved.
 *
 * Use this event to trigger downstream integrations (analytics init,
 * tag-manager data-layer pushes, etc.).
 *
 * It fires only for a decision that was actually recorded: after the consent
 * record is committed and the statistics cache invalidated. It does not fire
 * when consent logging is disabled (nothing is saved), nor when the save
 * fails. A listener that throws is logged and cannot affect the record or
 * the visitor's response — the record already exists.
 *
 * It is a notification, not a filter: there is no cancel flag, and changing
 * these properties changes neither what is stored nor what the endpoint
 * returns.
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

    /**
     * How the decision was reached — one of the `ConsentService::SOURCE_*`
     * constants. Lets a listener distinguish a deliberate choice in the banner
     * from one derived from a browser privacy signal.
     */
    public string $source = 'banner';

    /** The Craft site the consent was recorded against. */
    public int $siteId = 0;

    /** The id of the consent record that was saved. */
    public int $recordId = 0;
}
