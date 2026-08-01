<?php

namespace sfsinfotech\craftcookieconsentflow\controllers;

use Craft;
use craft\web\Controller;
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

        $stats = $plugin->consent->getStats();

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
            page: $page,
            perPage: self::PAGE_SIZE
        );

        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));

        return $this->renderTemplate('cookie-consent-flow/logs/index', [
            'plugin'       => $plugin,
            'records'      => $records,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'perPage'      => self::PAGE_SIZE,
            'statsCards'   => $statsCards,
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
