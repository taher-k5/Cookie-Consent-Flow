<?php

namespace sfsinfotech\craftcookieconsentflow\events;

use yii\base\Event;

/**
 * AfterConsentSaveEvent — fired after a visitor's consent has been persisted.
 *
 * Use this event to trigger downstream integrations (analytics init,
 * tag-manager data-layer pushes, etc.).
 *
 * It is a notification, not a filter. It fires once the decision is final —
 * the action, the categories and the visitor are settled, and the visitor's own
 * browser already holds them — but there is deliberately no cancel flag, and
 * changing these properties changes neither what is stored in the consent log
 * nor what the endpoint returns.
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
}
