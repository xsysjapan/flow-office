<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\Reconcile\SeededDataCurrent;
use App\Domain\EventSourcing\Rebuild\Reconcile\SeededDataReconciler;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\EventSourcing\Rebuild\UserEventOccurredAt;
use PHPUnit\Framework\TestCase;

/**
 * meta_data に created-at が無い user.* の発生日時(論点19 決定10-11)。
 * - user.created_from_sso_login・user.onboarded_as_admin・user.hire_date_set: 利用者の現在の users 行の created_at
 * - user.logged_in: payload の loggedInAt
 * - それ以外・行が無い・値が無い場合: 列 created_at のまま
 */
class UserEventOccurredAtTest extends TestCase
{
    private const USER = '11111111-1111-4111-8111-111111111111';

    private const OTHER_USER = '22222222-2222-4222-8222-222222222222';

    private const COLUMN = '2026-08-10 23:39:31';

    private const ROW_CREATED_AT = '2026-07-24 04:05:43';

    public function test_sso_login_created_event_takes_the_users_row_created_at(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(self::ROW_CREATED_AT, $resolver->occurredAt($this->row('user.created_from_sso_login', null, self::USER)));
        $this->assertSame(1, $resolver->report()[UserEventOccurredAt::SOURCE_USER_ROW]);
    }

    public function test_admin_onboarding_and_hire_date_events_take_the_users_row_created_at(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(self::ROW_CREATED_AT, $resolver->occurredAt($this->row('user.onboarded_as_admin', null, self::USER)));
        $this->assertSame(self::ROW_CREATED_AT, $resolver->occurredAt($this->row('user.hire_date_set', null, self::USER)));
        $this->assertSame(2, $resolver->report()[UserEventOccurredAt::SOURCE_USER_ROW]);
    }

    public function test_logged_in_takes_the_payload_logged_in_at(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('2026-07-20 09:00:00', $resolver->occurredAt($this->row('user.logged_in', null, self::USER, ['loggedInAt' => '2026-07-20 09:00:00', 'wasFirstLogin' => false])));
        $this->assertSame(1, $resolver->report()[UserEventOccurredAt::SOURCE_LOGGED_IN_AT]);
    }

    public function test_logged_in_at_is_normalized_to_the_meta_format(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('2026-07-20 09:00:00', $resolver->occurredAt($this->row('user.logged_in', null, self::USER, ['loggedInAt' => '2026-07-20T09:00:00'])));
    }

    public function test_logged_in_does_not_need_the_users_row(): void
    {
        $resolver = new UserEventOccurredAt([]);

        $this->assertSame('2026-07-20 09:00:00', $resolver->occurredAt($this->row('user.logged_in', null, 'nobody', ['loggedInAt' => '2026-07-20 09:00:00'])));
    }

    public function test_logged_in_without_a_usable_logged_in_at_keeps_the_column(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->occurredAt($this->row('user.logged_in', null, self::USER, [])));
        $this->assertNull($resolver->occurredAt($this->row('user.logged_in', null, self::USER, ['loggedInAt' => ''])));
        $this->assertSame(2, $resolver->report()[UserEventOccurredAt::COLUMN_NO_LOGGED_IN_AT]);
    }

    public function test_user_event_with_meta_created_at_is_not_changed_and_not_counted(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->occurredAt($this->row('user.hire_date_set', '2026-08-04 08:43:09', self::USER)));
        $this->assertNull($resolver->occurredAt($this->row('user.logged_in', '2026-08-04 08:43:09', self::USER, ['loggedInAt' => '2026-07-20 09:00:00'])));
        $this->assertSame(0, array_sum($resolver->report()));
    }

    public function test_non_user_event_without_meta_created_at_keeps_the_column(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->occurredAt($this->row('shift_pattern.created', null, self::USER)));
        $this->assertSame(0, array_sum($resolver->report()));
    }

    public function test_other_user_event_without_meta_created_at_keeps_the_column_and_is_reported(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->occurredAt($this->row('user.migrated_from_legacy', null, self::USER)));
        $this->assertNull($resolver->occurredAt($this->row('user.profile_updated', null, self::USER)));
        $this->assertSame(2, $resolver->report()[UserEventOccurredAt::COLUMN_OTHER_USER_EVENT]);
    }

    public function test_row_event_without_a_users_row_keeps_the_column_and_is_reported(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->occurredAt($this->row('user.created_from_sso_login', null, 'no-such-user')));
        $this->assertSame(1, $resolver->report()[UserEventOccurredAt::COLUMN_NO_USER_ROW]);
    }

    public function test_row_event_whose_users_row_has_no_created_at_keeps_the_column(): void
    {
        $resolver = new UserEventOccurredAt([['id' => self::USER, 'created_at' => null]]);

        $this->assertNull($resolver->occurredAt($this->row('user.hire_date_set', null, self::USER)));
        $this->assertSame(1, $resolver->report()[UserEventOccurredAt::COLUMN_NO_USER_ROW]);
    }

    public function test_rebuilder_writes_the_explicit_time_to_meta_and_column_for_passed_through_user_events(): void
    {
        $rows = [
            $this->row('user.created_from_sso_login', null, self::USER, ['entraUserId' => 'e1', 'name' => 'n', 'email' => 'e@example.test'], 1),
            $this->row('user.logged_in', null, self::OTHER_USER, ['loggedInAt' => '2026-07-20 09:00:00', 'wasFirstLogin' => false], 2),
            $this->row('user.profile_updated', null, self::USER, ['before' => [], 'after' => [], 'changedByUserId' => null], 3),
            $this->row('shift_pattern.created', null, 'shift-1', [], 4),
        ];
        // 照合(UserReconciler)は作成イベントの列の日付から usage_start_date を導くため、行の値をそれに合わせて差を出さない。
        $seeded = new SeededDataCurrent(users: [
            $this->userRow(self::USER, self::ROW_CREATED_AT),
            $this->userRow(self::OTHER_USER, '2026-01-01 00:00:00'),
        ]);

        $result = (new EventStoreRebuilder)->rebuild($rows, [], [], $seeded);

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $byClass = [];
        foreach ($result->rows as $row) {
            $byClass[$row['event_class']] = $row;
        }
        $this->assertSame(self::ROW_CREATED_AT, $byClass['user.created_from_sso_login']['created_at']);
        $this->assertSame(self::ROW_CREATED_AT, $byClass['user.created_from_sso_login']['meta_data']['created-at']);
        $this->assertSame('2026-07-20 09:00:00', $byClass['user.logged_in']['created_at']);
        $this->assertSame('2026-07-20 09:00:00', $byClass['user.logged_in']['meta_data']['created-at']);
        $this->assertSame(self::COLUMN, $byClass['user.profile_updated']['created_at']);
        $this->assertSame(self::COLUMN, $byClass['shift_pattern.created']['created_at']);
        $this->assertSame(
            [
                UserEventOccurredAt::SOURCE_USER_ROW => 1,
                UserEventOccurredAt::SOURCE_LOGGED_IN_AT => 1,
                UserEventOccurredAt::COLUMN_NO_USER_ROW => 0,
                UserEventOccurredAt::COLUMN_NO_LOGGED_IN_AT => 0,
                UserEventOccurredAt::COLUMN_OTHER_USER_EVENT => 1,
            ],
            $result->report['user_occurred_at'],
        );
    }

    public function test_apply_puts_the_explicit_time_on_the_row_and_keeps_the_others(): void
    {
        $rows = (new UserEventOccurredAt([$this->userRow(self::USER, self::ROW_CREATED_AT)]))->apply([
            $this->row('user.created_from_sso_login', null, self::USER, [], 1),
            $this->row('user.hire_date_set', '2026-08-04 08:43:09', self::USER, [], 2),
        ]);

        $this->assertSame(self::ROW_CREATED_AT, $rows[0]->occurredAt());
        $this->assertSame(self::COLUMN, $rows[0]->createdAt);
        $this->assertSame('2026-08-04 08:43:09', $rows[1]->occurredAt());
    }

    public function test_derived_event_anchored_on_an_overridden_row_takes_the_overridden_time(): void
    {
        [$anchor] = (new UserEventOccurredAt([$this->userRow(self::USER, self::ROW_CREATED_AT)]))->apply([
            $this->row('user.created_from_sso_login', null, self::USER, [], 1),
        ]);

        $after = RebuiltEvent::derived($anchor, 1, 'user.hire_date_set', self::USER, ['hireDate' => '2026-07-24 00:00:00']);
        $same = RebuiltEvent::derived($anchor, 0, 'user.created_from_sso_login', self::USER, []);

        $this->assertSame(self::ROW_CREATED_AT, $after->createdAt);
        $this->assertSame(self::ROW_CREATED_AT, $same->createdAt);
    }

    public function test_reconciler_folds_usage_start_date_with_the_overridden_time(): void
    {
        $row = $this->row('user.created_from_sso_login', null, self::USER, ['entraUserId' => 'e1', 'name' => 'n', 'email' => 'e@example.test'], 1);
        // 利用者の行の usage_start_date は、上書き後の作成日(2026-07-24)と同じ。
        $seeded = new SeededDataCurrent(users: [$this->userRow(self::USER, self::ROW_CREATED_AT, '2026-07-24 00:00:00')]);

        $applied = (new UserEventOccurredAt($seeded->users))->apply([$row]);
        $result = (new SeededDataReconciler)->reconcile($applied, $seeded);

        $this->assertSame([], array_values(array_filter($result->events, fn (RebuiltEvent $e) => $e->eventClass === 'user.usage_start_date_set')));
    }

    public function test_reconciler_without_the_override_derives_usage_start_date_from_the_column(): void
    {
        // 対照: 上書きが無いと列の日付(2026-08-10)で畳み込まれ、行の値(2026-07-24)との差が導出イベントになる。
        $row = $this->row('user.created_from_sso_login', null, self::USER, ['entraUserId' => 'e1', 'name' => 'n', 'email' => 'e@example.test'], 1);
        $seeded = new SeededDataCurrent(users: [$this->userRow(self::USER, self::ROW_CREATED_AT, '2026-07-24 00:00:00')]);

        $result = (new SeededDataReconciler)->reconcile([$row], $seeded);

        $derived = array_values(array_filter($result->events, fn (RebuiltEvent $e) => $e->eventClass === 'user.usage_start_date_set'));
        $this->assertCount(1, $derived);
        $this->assertSame('2026-07-24', $derived[0]->payload['usageStartDate']);
    }

    /** @param  array<string, mixed>  $payload */
    private function row(string $class, ?string $metaCreatedAt, string $aggregate, array $payload = [], int $id = 1): StoredEventRow
    {
        $meta = ['aggregate-root-uuid' => $aggregate];
        if ($metaCreatedAt !== null) {
            $meta['created-at'] = $metaCreatedAt;
        }

        return new StoredEventRow($id, $aggregate, 1, $class, $payload, $meta, self::COLUMN);
    }

    /** 照合(UserReconciler)の既定値と一致する利用者の行(作成イベントの日付を usage_start_date に持つ)。 */
    private function userRow(string $id, string $createdAt, string $usageStartDate = '2026-08-10 00:00:00'): array
    {
        return [
            'id' => $id, 'created_at' => $createdAt, 'usage_start_date' => $usageStartDate, 'hire_date' => null,
            'termination_date' => null, 'timezone' => null, 'paid_leave_auto_grant_enabled' => 1, 'special_leave_auto_grant_enabled' => 1,
        ];
    }

    private function resolver(): UserEventOccurredAt
    {
        return new UserEventOccurredAt([
            ['id' => self::USER, 'created_at' => self::ROW_CREATED_AT],
            ['id' => self::OTHER_USER, 'created_at' => '2026-01-01 00:00:00'],
        ]);
    }
}
