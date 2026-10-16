<?php

namespace sfsinfotech\craftcookieconsentflow\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use sfsinfotech\craftcookieconsentflow\helpers\ConsentHelper;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\Plugin;
use sfsinfotech\craftcookieconsentflow\records\ConsentLogRecord;
use sfsinfotech\craftcookieconsentflow\records\UserVisitorRecord;

/**
 * Links consent records to logged-in Craft users, when the site turns on
 * "Link consent records to logged-in users".
 *
 * Two links, both written only from Craft's own session — never from anything
 * the browser sends:
 *
 * - a decision made while logged in records the user on the record itself
 *   (`cookieconsent_log.userId`) — exact;
 * - every visitor ID a user's browsers carry is linked to the user
 *   (`cookieconsent_user_visitor`), at login and at each decision while
 *   logged in. A visitor who decided anonymously and then registers or logs
 *   in has that earlier ID linked as they log in, and a new ID after cookies
 *   were cleared is linked the next time they are logged in.
 *
 * A user's records are then the exact ones plus the anonymous records made
 * with one of their visitor IDs. An anonymous record on a browser also linked
 * to another user (a shared computer) is marked as such, never assumed.
 *
 * Anonymous visitors are unaffected: nothing here runs without a logged-in
 * user, and visitor IDs, cookies and records work exactly as before.
 */
class UserLinkService extends Component
{
    /** The record carries this user: decided while logged in. */
    public const MATCH_USER = 'user';

    /** Decided as a guest, and attached to this user as they logged in or registered. */
    public const MATCH_LINKED = 'linked';

    /** Anonymous, on a browser linked to this user only. */
    public const MATCH_BROWSER = 'browser';

    /** Anonymous, on a browser linked to this user and to someone else. */
    public const MATCH_SHARED = 'shared';

    /** Visitor-cookie names, whichever consent scope set them (see ConsentScope). */
    private const VISITOR_COOKIE_PATTERN = '/^cck_visitor_[A-Za-z0-9_-]{1,64}$/D';

    /** Whether records are linked to users on this install. */
    public function isEnabled(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $settings->linkUsers && $settings->logEnabled;
    }

    /**
     * The user a decision made now belongs to, or null: no linking, no web
     * request, nobody logged in — or an admin impersonating the user through
     * "Log in as", whose clicks are not that user's consent.
     */
    public function currentUserId(): ?int
    {
        try {
            if (!$this->isEnabled() || !Craft::$app->getRequest() instanceof \craft\web\Request) {
                return null;
            }

            $session = Craft::$app->getUser();
            $userId  = $session->getId();

            if ($userId === null || self::isImpersonating($session)) {
                return null;
            }

            return (int) $userId;
        } catch (\Throwable $e) {
            Craft::warning('Cookie consent could not resolve the logged-in user: ' . $e->getMessage(), __METHOD__);

            return null;
        }
    }

    /**
     * A user by ID, username or email — including users in the trash and
     * pending ones, whose records a data subject request still covers.
     */
    public static function findUser(string $query): ?\craft\elements\User
    {
        $query = trim($query);

        if ($query === '') {
            return null;
        }

        $find = static fn() => \craft\elements\User::find()->status(null)->trashed(null);

        if (ctype_digit($query)) {
            return $find()->id((int) $query)->one();
        }

        // Matched literally: Craft's query params read `*`, `not …`, commas
        // and `:empty:` as operators, so `*` would find some user.
        $literal = Db::escapeParam($query);

        return $find()->email($literal)->one() ?? $find()->username($literal)->one();
    }

    /** Whether the session is an admin acting as another user. */
    public static function isImpersonating(\craft\web\User $session): bool
    {
        if (method_exists($session, 'getImpersonatorId')) {
            return $session->getImpersonatorId() !== null;
        }

        // Before Craft 5.6, the impersonator was kept in the session only.
        $key = defined(\craft\elements\User::class . '::IMPERSONATE_KEY') ? \craft\elements\User::IMPERSONATE_KEY : 'craft.impersonate';

        return Craft::$app->getSession()->get($key) !== null;
    }

    /**
     * Links a visitor ID to a user, or refreshes when they were last seen
     * together. A failure only means one fewer link; it is logged.
     */
    public function link(int $userId, string $visitorUuid): void
    {
        $visitorUuid = ConsentHelper::normalizeVisitorId($visitorUuid);

        if ($visitorUuid === null || $userId <= 0) {
            return;
        }

        $now = Db::prepareDateForDb(new \DateTime());

        try {
            $row = UserVisitorRecord::find()->where(['userId' => $userId, 'visitorUuid' => $visitorUuid])->one();

            if ($row === null) {
                $row              = new UserVisitorRecord();
                $row->userId      = $userId;
                $row->visitorUuid = $visitorUuid;
            }

            $row->lastLinked = $now;
            $row->save(false);
        } catch (\yii\db\IntegrityException) {
            // Linked by a concurrent request a moment ago: nothing is missing.
        } catch (\Throwable $e) {
            Craft::warning('Cookie consent could not link a visitor ID to user #' . $userId . ': ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Links every visitor ID this request's browser carries — one per
     * consent scope it has decided in — to the user, and attaches the
     * decision in effect to their account (see attachToAccount()). Called
     * as the user logs in, which is how a decision made before logging in
     * (or registering) comes to be theirs.
     *
     * The cookies are Craft's validated (signed) ones, so a browser cannot
     * claim a visitor ID it was never given.
     *
     * @return int How many visitor IDs were linked.
     */
    public function linkRequestVisitorIds(int $userId): int
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request) {
            return 0;
        }

        $values = [];
        foreach ($request->getCookies() as $name => $cookie) {
            $values[(string) $name] = $cookie->value;
        }

        $ids = self::visitorIdsFromCookies($values);

        foreach ($ids as $id) {
            $this->link($userId, $id);
        }

        $this->attachToAccount($userId, $ids);

        return count($ids);
    }

    /**
     * Attaches the decision in effect in this browser to the user's account
     * as they log in or register.
     *
     * A visitor who decided as a guest and then logs in is, rightly, not
     * asked again: their browser already holds a decision. Linking the
     * browser's visitor ID makes that decision findable; this makes it
     * theirs. The latest record of each linked visitor ID, on each site,
     * gets the user — the record itself, so a decision stays one record.
     *
     * Only that record, and only while it is the decision in effect:
     * anonymous (a record of another user stays theirs; one of this user
     * needs nothing), and valid as the browser would judge it — the site's
     * current policy version, not expired. Older anonymous records of the
     * browser are left as they were.
     *
     * Records are otherwise never updated, so an attached one shows it:
     * its `dateUpdated` (when it was attached) is later than its
     * `dateCreated` (when the decision was made) — see matchType(). What was
     * decided, when, under which policy, is not changed.
     *
     * Best-effort: a failure is logged and never stops the login.
     *
     * @param string[] $visitorIds
     * @return int How many decisions were attached.
     */
    public function attachToAccount(int $userId, array $visitorIds): int
    {
        if ($userId <= 0 || $visitorIds === []) {
            return 0;
        }

        $plugin   = Plugin::getInstance();
        $attached = 0;

        $latest = [];
        foreach (ConsentLogRecord::find()->where(['visitorUuid' => array_values($visitorIds)])->orderBy(ConsentService::LIST_ORDER)->all() as $record) {
            $latest[$record->visitorUuid . '|' . $record->siteId] ??= $record;
        }

        foreach ($latest as $record) {
            try {
                $settings = $plugin->cookieSettings->getEffectiveSettings((int) $record->siteId);

                if (!self::carryable(
                    $record->userId !== null ? (int) $record->userId : null,
                    (string) $record->policyVersion,
                    (string) $record->dateCreated,
                    $settings->policyVersion,
                    $settings->consentExpiryDays,
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                )) {
                    continue;
                }

                // Only while still anonymous: two logins at once cannot both take it.
                $attached += (int) ConsentLogRecord::updateAll(
                    ['userId' => $userId, 'dateUpdated' => Db::prepareDateForDb(new \DateTime())],
                    ['id' => $record->id, 'userId' => null]
                );
            } catch (\Throwable $e) {
                Craft::warning('Cookie consent could not attach a decision to user #' . $userId . '\'s account: ' . $e->getMessage(), __METHOD__);
            }
        }

        return $attached;
    }

    /**
     * Whether a visitor ID's latest record on a site is a decision to attach
     * to an account: anonymous, made under the site's current policy
     * version, and not expired — exactly when the browser still applies it.
     *
     * @param string $dateCreated UTC, as stored.
     */
    public static function carryable(
        ?int $recordUserId,
        string $recordPolicyVersion,
        string $dateCreated,
        string $currentPolicyVersion,
        int $expiryDays,
        \DateTimeImmutable $nowUtc,
    ): bool {
        if ($recordUserId !== null) {
            return false;
        }

        if ($currentPolicyVersion !== '' && $recordPolicyVersion !== $currentPolicyVersion) {
            return false;
        }

        if ($expiryDays > 0) {
            try {
                $created = new \DateTimeImmutable($dateCreated, new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                return false;
            }

            if ($created < $nowUtc->modify("-{$expiryDays} days")) {
                return false;
            }
        }

        return true;
    }

    /**
     * The visitor IDs among a request's cookies.
     *
     * @param array<string, mixed> $cookies Name => value.
     * @return string[]
     */
    public static function visitorIdsFromCookies(array $cookies): array
    {
        $ids = [];

        foreach ($cookies as $name => $value) {
            if (!preg_match(self::VISITOR_COOKIE_PATTERN, (string) $name)) {
                continue;
            }

            $id = ConsentHelper::normalizeVisitorId($value);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The visitor IDs linked to a user.
     *
     * @return string[]
     */
    public function visitorIdsForUser(int $userId): array
    {
        return array_map('strval', UserVisitorRecord::find()
            ->select(['visitorUuid'])
            ->where(['userId' => $userId])
            ->column());
    }

    /**
     * For each visitor ID, every user linked to it.
     *
     * @param string[] $visitorIds
     * @return array<string, int[]>
     */
    public function usersForVisitorIds(array $visitorIds): array
    {
        if ($visitorIds === []) {
            return [];
        }

        $map = [];
        foreach (UserVisitorRecord::find()->select(['visitorUuid', 'userId'])->where(['visitorUuid' => array_values($visitorIds)])->asArray()->all() as $row) {
            $map[(string) $row['visitorUuid']][] = (int) $row['userId'];
        }

        return $map;
    }

    /**
     * How a record belongs to a user: made while they were logged in, made
     * as a guest and attached to them at login or registration, made
     * anonymously on one of their browsers, or made anonymously on a browser
     * other users were also seen on.
     *
     * @param array<string, int[]> $usersByVisitor See usersForVisitorIds().
     * @param bool                 $attached       The record was updated after it was made, which only
     *                                             attaching to an account does (see attachToAccount()).
     */
    public static function matchType(?int $recordUserId, string $visitorUuid, int $userId, array $usersByVisitor, bool $attached = false): string
    {
        if ($recordUserId === $userId) {
            return $attached ? self::MATCH_LINKED : self::MATCH_USER;
        }

        $others = array_diff($usersByVisitor[$visitorUuid] ?? [], [$userId]);

        return $others === [] ? self::MATCH_BROWSER : self::MATCH_SHARED;
    }

    /**
     * Whether a record was attached to an account after it was made: records
     * are written once, so a later `dateUpdated` means attachToAccount().
     */
    public static function wasAttached(mixed $dateCreated, mixed $dateUpdated): bool
    {
        $created = strtotime((string) $dateCreated);
        $updated = strtotime((string) $dateUpdated);

        return $created !== false && $updated !== false && $updated > $created;
    }

    /**
     * The condition for a user's records: the exact ones, plus — when asked —
     * the anonymous records of their browsers. A record made logged in by
     * someone else is never one of them, whatever browser it came from.
     *
     * @param bool $browsersToo Include the anonymous records of their browsers.
     * @param bool $sharedToo   Of those, include browsers other users were also seen on.
     * @return array<int|string, mixed>
     */
    public function recordCondition(int $userId, bool $browsersToo = true, bool $sharedToo = true): array
    {
        $condition = ['or', ['userId' => $userId]];

        if (!$browsersToo) {
            return $condition;
        }

        $visitorIds = $this->visitorIdsForUser($userId);

        if (!$sharedToo && $visitorIds !== []) {
            $usersByVisitor = $this->usersForVisitorIds($visitorIds);
            $visitorIds = array_values(array_filter(
                $visitorIds,
                static fn(string $id): bool => array_diff($usersByVisitor[$id] ?? [], [$userId]) === []
            ));
        }

        if ($visitorIds !== []) {
            $condition[] = ['and', ['userId' => null], ['visitorUuid' => $visitorIds]];
        }

        return $condition;
    }

    /**
     * The records to delete with a user, when the site deletes them: those
     * made while the user was logged in. Anonymous records on their browsers
     * are not proof of anything about the user, and are left to retention.
     * Null when the site keeps (anonymises) records instead.
     *
     * @return int[]|null
     */
    public function recordIdsToDeleteWithUser(int $userId): ?array
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->userDeleteAction !== Settings::USER_DELETE_DELETE) {
            return null;
        }

        return array_map('intval', ConsentLogRecord::find()->select(['id'])->where(['userId' => $userId])->column());
    }
}
