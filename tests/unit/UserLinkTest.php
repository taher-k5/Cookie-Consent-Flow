<?php

namespace sfsinfotech\craftcookieconsentflow\tests\unit;

use PHPUnit\Framework\TestCase;
use sfsinfotech\craftcookieconsentflow\models\Settings;
use sfsinfotech\craftcookieconsentflow\services\UserLinkService;

/**
 * Linking consent records to logged-in users.
 *
 * What links a browser to a user is the visitor cookie this plugin set, read
 * from Craft's validated cookies at login — so which cookies count, and how a
 * record is classed against a user, decide whether a person's request returns
 * their records and only theirs.
 */
final class UserLinkTest extends TestCase
{
    private const A = '2a5c0cff-91b3-4e2d-a7f0-3c1d9e8b4a21';
    private const B = '7e19b0aa-0000-4000-8000-000000000001';

    public function testVisitorCookiesOfEveryScopeAreRead(): void
    {
        $ids = UserLinkService::visitorIdsFromCookies([
            'cck_visitor_1'  => self::A,
            'cck_visitor_g2' => strtoupper(self::B),
            'CraftSessionId' => self::A,
        ]);

        self::assertSame([self::A, self::B], $ids);
    }

    public function testOnlyPluginVisitorCookiesWithRealIdsCount(): void
    {
        self::assertSame([], UserLinkService::visitorIdsFromCookies([
            'cck_consent_1'       => self::A,
            'cck_visitor'         => self::A,
            'cck_visitor_a;b'     => self::A,
            'cck_visitor_1'       => 'not-an-id',
            'cck_visitor_2'       => ['nested'],
            'my_cck_visitor_1'    => self::A,
        ]));
    }

    public function testTheSameIdUnderTwoScopesIsLinkedOnce(): void
    {
        self::assertSame([self::A], UserLinkService::visitorIdsFromCookies(['cck_visitor_1' => self::A, 'cck_visitor_3' => self::A]));
    }

    public function testARecordMadeLoggedInIsTheUsersExactly(): void
    {
        self::assertSame(UserLinkService::MATCH_USER, UserLinkService::matchType(42, self::A, 42, [self::A => [42, 7]]));
    }

    public function testALoggedOutRecordOnTheirOwnBrowserIsTheirBrowsers(): void
    {
        self::assertSame(UserLinkService::MATCH_BROWSER, UserLinkService::matchType(null, self::A, 42, [self::A => [42]]));
    }

    /** A family computer: never quietly treated as one person's. */
    public function testALoggedOutRecordOnASharedBrowserIsMarkedShared(): void
    {
        self::assertSame(UserLinkService::MATCH_SHARED, UserLinkService::matchType(null, self::A, 42, [self::A => [42, 7]]));
    }

    public function testLinkingIsOffByDefaultAndKeepsRecordsWhenAUserGoes(): void
    {
        $settings = new Settings();

        self::assertFalse($settings->linkUsers, 'anonymous behaviour is unchanged until turned on');
        self::assertSame(Settings::USER_DELETE_ANONYMISE, $settings->userDeleteAction, 'proof of consent is kept by default');
    }

    public function testTheUserDeleteActionIsValidated(): void
    {
        $settings = new Settings();

        foreach ([Settings::USER_DELETE_ANONYMISE, Settings::USER_DELETE_DELETE] as $value) {
            $settings->userDeleteAction = $value;
            self::assertTrue($settings->validate(['userDeleteAction']), $value);
        }

        $settings->userDeleteAction = 'archive';
        self::assertFalse($settings->validate(['userDeleteAction']));
    }

    // -------------------------------------------------------------------------
    // Carrying a guest decision into the account at login
    // -------------------------------------------------------------------------

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-16 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testAGuestDecisionInEffectIsCarried(): void
    {
        self::assertTrue(UserLinkService::carryable(null, '3', '2026-10-01 09:00:00', '3', 180, self::now()));
    }

    public function testADecisionAlreadyOwnedIsNotCarried(): void
    {
        self::assertFalse(UserLinkService::carryable(42, '3', '2026-10-01 09:00:00', '3', 180, self::now()), 'this user, or another');
    }

    /** The browser would ask again under a new policy, so it is no decision to carry. */
    public function testADecisionUnderAnOldPolicyIsNotCarried(): void
    {
        self::assertFalse(UserLinkService::carryable(null, '2', '2026-10-01 09:00:00', '3', 180, self::now()));
    }

    public function testAnExpiredDecisionIsNotCarried(): void
    {
        self::assertFalse(UserLinkService::carryable(null, '3', '2026-01-01 09:00:00', '3', 180, self::now()));
        self::assertTrue(UserLinkService::carryable(null, '3', '2016-01-01 09:00:00', '3', 0, self::now()), '0 never expires');
    }

    public function testAnUnreadableDateIsNotCarried(): void
    {
        self::assertFalse(UserLinkService::carryable(null, '3', 'not a date', '3', 180, self::now()));
    }

    /** Attached at login or registration: theirs, and marked as decided as a guest. */
    public function testAnAttachedRecordIsMarkedLinked(): void
    {
        self::assertSame(UserLinkService::MATCH_LINKED, UserLinkService::matchType(42, self::A, 42, [self::A => [42]], true));
        self::assertSame(UserLinkService::MATCH_USER, UserLinkService::matchType(42, self::A, 42, [self::A => [42]], false));
    }

    /** Records are written once; only attaching updates one. */
    public function testOnlyALaterUpdateMeansAttached(): void
    {
        self::assertTrue(UserLinkService::wasAttached('2026-10-16 12:42:06', '2026-10-16 13:13:00'));
        self::assertFalse(UserLinkService::wasAttached('2026-10-16 12:42:06', '2026-10-16 12:42:06'), 'as saved');
        self::assertFalse(UserLinkService::wasAttached(null, '2026-10-16 13:13:00'));
        self::assertFalse(UserLinkService::wasAttached('not a date', 'also not'));
    }
}
