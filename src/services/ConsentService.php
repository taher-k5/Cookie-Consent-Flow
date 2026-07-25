<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use sfsinfotech\craftcookieconsentflow\events\AfterConsentSaveEvent;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\records\ConsentLogRecord;

/**
 * Consent Service — handles recording, retrieving, and managing consent records.
 */
class ConsentService extends Component
{
    /**
     * Persists a visitor's consent choice and fires the AfterConsentSave event.
     *
     * @param  string   $action     'accept_all' | 'reject_all' | 'custom'
     * @param  string[] $categories Category keys the visitor accepted.
     * @return array{visitorUuid: string, action: string, categories: string[]}
     */
    public function saveConsent(string $action, array $categories): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $request  = Craft::$app->getRequest();

        // Resolve visitor UUID
        $visitorUuid = $request->getCookies()->getValue('cck_visitor') ?? ConsentHelper::generateVisitorUuid();

        // Fire event (allow third-party code to react / cancel logging)
        $event = new AfterConsentSaveEvent([
            'action'      => $action,
            'categories'  => $categories,
            'visitorUuid' => $visitorUuid,
        ]);
        Plugin::getInstance()->trigger(
            Plugin::EVENT_AFTER_CONSENT_SAVE,
            $event
        );

        if ($settings->logEnabled) {
            $record              = new ConsentLogRecord();
            $record->visitorUuid = $visitorUuid;
            $record->ipHash      = ConsentHelper::hashIp($request->getRemoteIP() ?? '0.0.0.0');
            $record->siteId      = Craft::$app->getSites()->getCurrentSite()->id;
            $record->categories  = Json::encode($categories);
            $record->action      = $action;
            $record->countryCode = Plugin::getInstance()->geo->getCountryCode();
            $record->userAgent   = mb_substr(
                (string) $request->getHeaders()->get('user-agent', ''),
                0,
                500
            );
            if (!$record->save()) {
                Craft::error(
                    'Cookie consent log save failed: ' .
                    Json::encode($record->getErrors()),
                    __METHOD__
                );
            }
        }

        return [
            'visitorUuid' => $visitorUuid,
            'action'      => $action,
            'categories'  => $categories,
        ];
    }

    /**
     * Returns the most recent consent record for a visitor UUID, or null.
     *
     * @return array{action: string, categories: string[], dateCreated: string}|null
     */
    public function getConsent(string $visitorUuid): ?array
    {
        $record = ConsentLogRecord::find()
            ->where(['visitorUuid' => $visitorUuid])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        if (!$record) {
            return null;
        }

        return [
            'action'      => $record->action,
            'categories'  => Json::decode($record->categories),
            'dateCreated' => $record->dateCreated,
        ];
    }

    /**
     * Returns aggregated dashboard statistics for the current Craft site.
     *
     * @return array{total: int, acceptAll: int, rejectAll: int, custom: int}
     */
    public function getStats(): array
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $query = ConsentLogRecord::find()->where(['siteId' => $siteId]);

        return [
            'total'     => (int) (clone $query)->count(),
            'acceptAll' => (int) (clone $query)->andWhere(['action' => 'accept_all'])->count(),
            'rejectAll' => (int) (clone $query)->andWhere(['action' => 'reject_all'])->count(),
            'custom'    => (int) (clone $query)->andWhere(['action' => 'custom'])->count(),
        ];
    }

    /**
     * Returns a paginated list of consent log records for the CP log viewer.
     *
     * @param  string $actionFilter  '' (all), 'accept_all', 'reject_all', or 'custom'
     * @param  int    $page          1-based page number
     * @param  int    $perPage       Rows per page
     * @return array{0: ConsentLogRecord[], 1: int}  [records, totalCount]
     */
    public function getLogs(string $actionFilter = '', int $page = 1, int $perPage = 50): array
    {
        $query = ConsentLogRecord::find()->orderBy(['dateCreated' => SORT_DESC]);

        if ($actionFilter !== '') {
            $query->andWhere(['action' => $actionFilter]);
        }

        $total   = (int) (clone $query)->count();
        $offset  = ($page - 1) * $perPage;
        $records = $query->limit($perPage)->offset($offset)->all();

        return [$records, $total];
    }

    /**
     * Deletes consent log records older than the configured retention period.
     * Call from a queue job or console command on a schedule.
     */
    public function purgeOldLogs(): int
    {
        $days = Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days === 0) {
            return 0;
        }

        $cutoff = (new \DateTime())->modify("-{$days} days")->format('Y-m-d H:i:s');

        return (int) ConsentLogRecord::deleteAll(['<', 'dateCreated', $cutoff]);
    }

    public function getLogById(int $id): ?ConsentLogRecord
    {
        return ConsentLogRecord::findOne($id);
    }
}

