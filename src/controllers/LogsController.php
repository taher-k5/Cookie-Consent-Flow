<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\Plugin;
use yii\web\Response;
use yii\web\NotFoundHttpException;

/**
 * Logs Controller — renders the Consent Logs CP page with filtering and pagination.
 */
class LogsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public const PAGE_SIZE = 50;

    public const VALID_ACTIONS = ['accept_all', 'reject_all', 'custom'];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Consent logs contain visitor identifiers, IP hashes, user agents,
        // and country data — restrict beyond "logged into the control
        // panel" to a specific, grantable permission.
        $this->requirePermission('cookieConsentFlow:viewLogs');

        return true;
    }

    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $plugin  = Plugin::getInstance();

        // Site filter: absent, empty, or the explicit "all" sentinel all
        // mean "All Sites" (siteId = null) — the page's default, matching
        // how a multi-site admin most often wants to land here (everything,
        // then narrow down). The "All Sites" option in the dropdown below
        // links to `?site=all` explicitly rather than relying on the param
        // simply being missing, so the choice is unambiguous and always
        // produces a real navigation/selected-state change. A non-empty,
        // non-"all" param is resolved to a real site, falling back to the
        // primary site if it no longer exists (deleted site, stale link).
        $siteParam = $request->getParam('site');
        $site      = ($siteParam !== null && $siteParam !== '' && $siteParam !== 'all')
            ? ConsentHelper::resolveSiteFromParam($siteParam)
            : null;

        // Action filter. Note: the query param is deliberately named
        // `filter`, NOT `action` — Craft treats any request carrying a
        // non-empty `action` GET/POST param as an action-route request
        // (its `?action=some/controller/action` trigger mechanism) and
        // will try to resolve that value as a route, producing an
        // `InvalidRouteException` / 404 instead of rendering this page.
        $filter = $request->getParam('filter', '');
        if (!in_array($filter, self::VALID_ACTIONS, true)) {
            $filter = '';
        }

        $stats = $plugin->consent->getStats($site?->id);

        $totalRecords = max(1, $stats['total']);

        $statsCards = [
            [
                'label' => 'Accept All',
                'count' => $stats['acceptAll'],
                'percent' => round(($stats['acceptAll'] / $totalRecords) * 100),
                'color' => 'green',
            ],
            [
                'label' => 'Reject All',
                'count' => $stats['rejectAll'],
                'percent' => round(($stats['rejectAll'] / $totalRecords) * 100),
                'color' => 'red',
            ],
            [
                'label' => 'Custom',
                'count' => $stats['custom'],
                'percent' => round(($stats['custom'] / $totalRecords) * 100),
                'color' => 'blue',
            ],
            [
                'label' => 'Total Records',
                'count' => $stats['total'],
                'percent' => 100,
                'color' => 'gray',
            ],
        ];

        // --- pagination ---
        $page = max(1, (int) $request->getParam('page', 1));

        [$records, $total] = $plugin->consent->getLogs(
            siteId: $site?->id,
            actionFilter: $filter,
            page: $page,
            perPage: self::PAGE_SIZE
        );

        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));

        return $this->renderTemplate('cookie-consent-flow/logs/index', [
            'plugin'      => $plugin,
            'records'     => $records,
            'total'       => $total,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'perPage'     => self::PAGE_SIZE,
            'statsCards'  => $statsCards,
            'currentSite' => $site,
            'allSites'    => Craft::$app->getSites()->getAllSites(),
            'filter'      => $filter,
        ]);
    }

    public function actionView(int $id): Response
    {
        $this->requireCpRequest();

        $record = Plugin::getInstance()
            ->consent
            ->getLogById($id);

        if (!$record) {
            throw new NotFoundHttpException('Consent record not found.');
        }

        return $this->renderTemplate(
            'cookie-consent-flow/logs/view',
            [
                'plugin' => Plugin::getInstance(),
                'record' => $record,
            ]
        );
    }
}
