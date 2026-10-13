<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\Reconcile\ReconcileResult;
use App\Domain\EventSourcing\Rebuild\Reconcile\SeededDataCurrent;
use App\Domain\EventSourcing\Rebuild\Reconcile\SeededDataReconciler;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * 照合による補完(論点19 決定1)の純粋な検証。DBは使わない。テーブルごとに
 * 「イベントが無い行 → 作成イベントが先頭/依存先の直後に出る」「イベントがあるのに最終状態が違う → 最後の直後に差分」
 * 「一致していれば何も出ない」を確かめる。
 */
class SeededDataReconcilerTest extends TestCase
{
    private const AT = '2026-09-01 10:00:00';

    private const ROW_AT = '2026-01-01 00:00:00';

    private const USER = '11111111-1111-4111-8111-111111111111';

    private const USER2 = '22222222-2222-4222-8222-222222222222';

    private const GROUP = '33333333-3333-4333-8333-333333333333';

    private const YEAR = '44444444-4444-4444-8444-444444444444';

    private const CALENDAR = '55555555-5555-4555-8555-555555555555';

    // ---- group_types ----

    public function test_group_type_without_events_is_created_at_the_head_with_row_values_and_status_is_restored_by_updated(): void
    {
        $result = $this->reconcile([$this->event(5, 'other', 'attendance_day.created', [])], new SeededDataCurrent(groupTypes: [$this->groupType(['is_system' => 1, 'status' => 'inactive'])]));

        $events = $this->ordered($result);
        $this->assertSame(['group_type.created', 'group_type.updated'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:department')->toString();
        $this->assertSame($aggregate, $events[0]->aggregateUuid);
        $this->assertSame(0, $events[0]->anchorId);
        $this->assertSame(self::ROW_AT, $events[0]->createdAt);
        $this->assertTrue($events[0]->payload['isSystem']);
        $this->assertNull($events[0]->payload['actorUserId']);
        $this->assertSame(1, $events[0]->payload['groupTypeId']);
        $this->assertSame('inactive', $events[1]->payload['status']);
        $this->assertSame([], $result->mismatches);
        $this->assertSame(['group_type.created' => 1, 'group_type.updated' => 1], $result->derivedByTable['group_types']);
    }

    public function test_group_type_with_matching_events_derives_nothing(): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:department')->toString();
        $result = $this->reconcile(
            [$this->event(1, $aggregate, 'group_type.created', $this->groupTypeCreated())],
            new SeededDataCurrent(groupTypes: [$this->groupType()]),
        );

        $this->assertSame([], $result->events);
        $this->assertSame([], $result->mismatches);
    }

    public function test_group_type_with_events_but_different_final_state_gets_updated_after_the_last_event(): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:department')->toString();
        $result = $this->reconcile(
            [
                $this->event(1, $aggregate, 'group_type.created', $this->groupTypeCreated()),
                $this->event(2, 'other', 'attendance_day.created', []),
                $this->event(3, $aggregate, 'group_type.updated', ['groupTypeId' => 1, 'name' => '部署(旧)', 'displayOrder' => 10, 'status' => 'active', 'membershipLimitType' => 'unlimited', 'maxMembershipsPerUser' => null, 'primaryMembershipRequired' => false, 'maxPrimaryMemberships' => null, 'actorUserId' => 'x'], '2026-09-02 10:00:00'),
            ],
            new SeededDataCurrent(groupTypes: [$this->groupType(['name' => '部署(再実行後)'])]),
        );

        $this->assertCount(1, $result->events);
        $event = $result->events[0];
        $this->assertSame('group_type.updated', $event->eventClass);
        $this->assertSame(3, $event->anchorId);
        $this->assertGreaterThan(0, $event->subOrder);
        $this->assertSame('2026-09-02 10:00:00', $event->createdAt);
        $this->assertSame('部署(再実行後)', $event->payload['name']);
        $this->assertCount(1, $result->mismatches['group_types']);
        $this->assertStringContainsString('group_type department', $result->mismatches['group_types'][0]);
    }

    public function test_group_type_created_event_with_a_different_is_system_cannot_be_fixed_by_updated(): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:department')->toString();
        $result = $this->reconcile(
            [$this->event(1, $aggregate, 'group_type.created', $this->groupTypeCreated())],
            new SeededDataCurrent(groupTypes: [$this->groupType(['is_system' => 1])]),
        );

        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('isSystem', $result->issues[0]['reason']);
    }

    public function test_group_type_with_only_updated_events_gets_created_just_before_the_first_event_with_the_row_created_at(): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:department')->toString();
        $result = $this->reconcile(
            [
                $this->event(4, 'other', 'attendance_day.created', [], '2026-08-01 09:00:00'),
                $this->event(5, $aggregate, 'group_type.updated', ['groupTypeId' => 1, 'name' => '部署', 'displayOrder' => 10, 'status' => 'active', 'membershipLimitType' => 'unlimited', 'maxMembershipsPerUser' => null, 'primaryMembershipRequired' => false, 'maxPrimaryMemberships' => null, 'actorUserId' => null], '2026-09-02 10:00:00'),
            ],
            new SeededDataCurrent(groupTypes: [$this->groupType()]),
        );

        $this->assertCount(1, $result->events);
        $created = $result->events[0];
        $this->assertSame('group_type.created', $created->eventClass);
        $this->assertSame(5, $created->anchorId);
        $this->assertLessThan(0, $created->subOrder);
        $this->assertSame(self::ROW_AT, $created->createdAt);
        $this->assertCount(1, $result->mismatches['group_types']);
    }

    // ---- groups ----

    public function test_group_without_events_is_created_right_after_its_group_type_and_parents_come_first(): void
    {
        $child = '66666666-6666-4666-8666-666666666666';
        $result = $this->reconcile([], new SeededDataCurrent(
            groupTypes: [$this->groupType()],
            groups: [
                $this->group(['id' => $child, 'code' => 'child', 'parent_group_id' => self::GROUP, 'created_at' => '2026-01-03 00:00:00']),
                $this->group(['id' => self::GROUP, 'code' => 'parent', 'created_at' => '2026-01-02 00:00:00']),
            ],
        ));

        $events = $this->ordered($result);
        $this->assertSame(['group_type.created', 'group.created', 'group.created'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $this->assertSame([self::GROUP, $child], [$events[1]->aggregateUuid, $events[2]->aggregateUuid]);
        $this->assertSame('2026-01-02 00:00:00', $events[1]->createdAt);
        $this->assertSame(self::GROUP, $events[2]->payload['parentGroupId']);
        $this->assertSame(1, $events[1]->payload['groupTypeId']);
    }

    public function test_group_with_events_but_different_final_state_gets_updated_after_the_last_event(): void
    {
        $result = $this->reconcile(
            [
                $this->event(1, $this->groupTypeAggregate(), 'group_type.created', $this->groupTypeCreated()),
                $this->event(2, self::GROUP, 'group.created', ['groupTypeId' => 1, 'name' => '旧', 'code' => 'dev', 'description' => null, 'parentGroupId' => null, 'actorUserId' => null]),
            ],
            new SeededDataCurrent(groupTypes: [$this->groupType()], groups: [$this->group(['name' => '開発部'])]),
        );

        $this->assertCount(1, $result->events);
        $this->assertSame('group.updated', $result->events[0]->eventClass);
        $this->assertSame(2, $result->events[0]->anchorId);
        $this->assertSame('開発部', $result->events[0]->payload['name']);
        $this->assertCount(1, $result->mismatches['groups']);
    }

    // ---- roles ----

    public function test_role_without_events_is_created_with_is_system_and_status_from_the_row(): void
    {
        $result = $this->reconcile([], new SeededDataCurrent(roles: [$this->role(['is_system' => 1, 'status' => 'inactive'])]));

        $events = $this->ordered($result);
        $this->assertSame(['role.created', 'role.updated'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $this->assertSame(Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:admin')->toString(), $events[0]->aggregateUuid);
        $this->assertTrue($events[0]->payload['isSystem']);
        $this->assertSame(7, $events[0]->payload['roleId']);
        $this->assertSame('inactive', $events[1]->payload['status']);
    }

    public function test_role_whose_aggregate_has_only_permission_events_gets_created_before_the_first_event(): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:admin')->toString();
        $result = $this->reconcile(
            [
                $this->event(3, 'other', 'attendance_day.created', [], '2026-08-01 09:00:00'),
                $this->event(4, $aggregate, 'role.permissions_changed', ['roleId' => 7, 'permissionIds' => [1, 2], 'actorUserId' => null]),
            ],
            new SeededDataCurrent(roles: [$this->role()], permissionRole: [['role_id' => 7, 'permission_id' => 1], ['role_id' => 7, 'permission_id' => 2]]),
        );

        $this->assertCount(1, $result->events);
        $this->assertSame('role.created', $result->events[0]->eventClass);
        $this->assertSame(4, $result->events[0]->anchorId);
        $this->assertLessThan(0, $result->events[0]->subOrder);
        $this->assertSame(self::ROW_AT, $result->events[0]->createdAt);
    }

    // ---- permission_role・role_features ----

    public function test_permission_role_without_events_is_derived_right_after_the_roles_derived_created(): void
    {
        $result = $this->reconcile([], new SeededDataCurrent(
            roles: [$this->role()],
            permissionRole: [['role_id' => 7, 'permission_id' => 5], ['role_id' => 7, 'permission_id' => 3]],
            roleFeatures: [['role_id' => 7, 'feature_id' => 9, 'created_at' => '2026-01-05 00:00:00']],
        ));

        $events = $this->ordered($result);
        $this->assertSame(['role.created', 'role.permissions_changed', 'role.features_changed'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $this->assertSame([3, 5], $events[1]->payload['permissionIds']);
        $this->assertSame([9], $events[2]->payload['featureIds']);
        $this->assertNull($events[1]->payload['actorUserId']);
        $this->assertSame('2026-01-05 00:00:00', $events[2]->createdAt);
    }

    public function test_permission_role_when_the_role_was_created_by_an_event_is_placed_after_the_create_event(): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:admin')->toString();
        $result = $this->reconcile(
            [$this->event(1, $aggregate, 'role.created', ['roleId' => 7, 'code' => 'admin', 'name' => '管理者', 'description' => null, 'actorUserId' => null, 'isSystem' => false])],
            new SeededDataCurrent(roles: [$this->role()], permissionRole: [['role_id' => 7, 'permission_id' => 1]]),
        );

        $this->assertCount(1, $result->events);
        $this->assertSame('role.permissions_changed', $result->events[0]->eventClass);
        $this->assertSame(1, $result->events[0]->anchorId);
        $this->assertGreaterThan(0, $result->events[0]->subOrder);
    }

    public function test_permission_role_with_a_different_last_event_gets_a_full_replacement_after_the_last_event_and_equal_sets_derive_nothing(): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:admin')->toString();
        $rows = [
            $this->event(1, $aggregate, 'role.created', ['roleId' => 7, 'code' => 'admin', 'name' => '管理者', 'description' => null, 'actorUserId' => null, 'isSystem' => false]),
            $this->event(2, $aggregate, 'role.permissions_changed', ['roleId' => 7, 'permissionIds' => [1, 2], 'actorUserId' => null]),
            $this->event(3, $aggregate, 'role.permissions_changed', ['roleId' => 7, 'permissionIds' => [2, 1, 4], 'actorUserId' => null], '2026-09-02 10:00:00'),
        ];

        $different = $this->reconcile($rows, new SeededDataCurrent(roles: [$this->role()], permissionRole: [['role_id' => 7, 'permission_id' => 1], ['role_id' => 7, 'permission_id' => 2]]));
        $this->assertCount(1, $different->events);
        $this->assertSame(3, $different->events[0]->anchorId);
        $this->assertSame([1, 2], $different->events[0]->payload['permissionIds']);
        $this->assertSame('2026-09-02 10:00:00', $different->events[0]->createdAt);
        $this->assertCount(1, $different->mismatches['permission_role']);

        $same = $this->reconcile($rows, new SeededDataCurrent(roles: [$this->role()], permissionRole: [['role_id' => 7, 'permission_id' => 4], ['role_id' => 7, 'permission_id' => 1], ['role_id' => 7, 'permission_id' => 2]]));
        $this->assertSame([], $same->events);
    }

    // ---- role_assignments ----

    public function test_role_assignment_without_events_is_created_after_its_role_with_assigned_by_as_the_actor(): void
    {
        $assignment = '77777777-7777-4777-8777-777777777777';
        $result = $this->reconcile([], new SeededDataCurrent(
            users: [$this->user()],
            roles: [$this->role()],
            roleAssignments: [[
                'id' => $assignment, 'subject_type' => 'user', 'subject_id' => self::USER, 'role_id' => 7, 'scope_type' => 'company', 'scope_group_id' => null,
                'include_descendants' => 0, 'starts_at' => null, 'ends_at' => '2027-03-31 00:00:00', 'status' => 'active', 'assigned_by' => self::USER, 'created_at' => '2026-01-09 00:00:00',
            ]],
        ));

        $events = $this->ordered($result);
        $this->assertSame(['user.migrated_from_legacy', 'role.created', 'role_assignment.created'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $created = $events[2];
        $this->assertSame($assignment, $created->aggregateUuid);
        $this->assertSame(self::USER, $created->payload['actorUserId']);
        $this->assertSame('2027-03-31T00:00:00Z', $created->payload['endsAt']);
        $this->assertNull($created->payload['startsAt']);
        $this->assertSame('2026-01-09 00:00:00', $created->createdAt);
    }

    public function test_role_assignment_differences_are_filled_by_updated_and_removed_with_a_null_actor_and_unfixable_ones_stop(): void
    {
        $assignment = '77777777-7777-4777-8777-777777777777';
        $row = [
            'id' => $assignment, 'subject_type' => 'user', 'subject_id' => self::USER, 'role_id' => 7, 'scope_type' => 'company', 'scope_group_id' => null,
            'include_descendants' => 0, 'starts_at' => null, 'ends_at' => null, 'status' => 'active', 'assigned_by' => null, 'created_at' => self::ROW_AT,
        ];
        $created = $this->event(1, $assignment, 'role_assignment.created', ['subjectType' => 'user', 'subjectId' => self::USER, 'roleId' => 7, 'scopeType' => 'company', 'scopeGroupId' => null, 'includeDescendants' => false, 'startsAt' => null, 'endsAt' => null, 'actorUserId' => null]);
        $base = ['users' => [$this->user()], 'roles' => [$this->role()]];
        $only = fn (ReconcileResult $r, string $prefix) => array_values(array_filter($r->events, fn (RebuiltEvent $e) => str_starts_with($e->eventClass, $prefix)));

        $scope = $this->reconcile([$created], new SeededDataCurrent(...$base, roleAssignments: [['scope_type' => 'self', 'ends_at' => '2027-03-31 00:00:00'] + $row]));
        $updated = $only($scope, 'role_assignment.');
        $this->assertSame([], $scope->issues);
        $this->assertCount(1, $updated);
        $this->assertSame('role_assignment.updated', $updated[0]->eventClass);
        $this->assertSame(1, $updated[0]->anchorId);
        $this->assertSame('self', $updated[0]->payload['scopeType']);
        $this->assertSame('2027-03-31T00:00:00Z', $updated[0]->payload['endsAt']);
        $this->assertNull($updated[0]->payload['actorUserId']);
        $this->assertCount(1, $scope->mismatches['role_assignments']);

        $removed = $this->reconcile([], new SeededDataCurrent(...$base, roleAssignments: [['status' => 'removed'] + $row]));
        $this->assertSame([], $removed->issues);
        $this->assertSame(['role_assignment.created', 'role_assignment.removed'], array_column($only($removed, 'role_assignment.'), 'eventClass'));
        $this->assertNull($only($removed, 'role_assignment.')[1]->payload['actorUserId']);

        $removedByEvent = $this->reconcile([$created, $this->event(2, $assignment, 'role_assignment.removed', ['actorUserId' => 'x'])], new SeededDataCurrent(...$base, roleAssignments: [$row]));
        $this->assertCount(1, $removedByEvent->issues);

        $differentRole = $this->reconcile([$created], new SeededDataCurrent(...$base, roleAssignments: [['role_id' => 8] + $row]));
        $this->assertCount(1, $differentRole->issues);

        $same = $this->reconcile([$created], new SeededDataCurrent(...$base, roleAssignments: [$row]));
        $this->assertSame([], $same->issues);
        $this->assertSame([], $only($same, 'role_assignment.'));
    }

    // ---- group_feature_assignments ----

    public function test_group_feature_assignment_is_folded_from_assigned_and_removed_events(): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-access:'.self::GROUP)->toString();
        $rows = [
            $this->event(1, $this->groupTypeAggregate(), 'group_type.created', $this->groupTypeCreated()),
            $this->event(2, self::GROUP, 'group.created', ['groupTypeId' => 1, 'name' => '開発部', 'code' => 'dev', 'description' => null, 'parentGroupId' => null, 'actorUserId' => null]),
            $this->event(3, $aggregate, 'feature.assigned_to_group', ['groupId' => self::GROUP, 'featureId' => 1, 'actorUserId' => self::USER]),
            $this->event(4, $aggregate, 'feature.assigned_to_group', ['groupId' => self::GROUP, 'featureId' => 2, 'actorUserId' => self::USER]),
            $this->event(5, $aggregate, 'feature.removed_from_group', ['groupId' => self::GROUP, 'featureId' => 2, 'actorUserId' => self::USER], '2026-09-03 10:00:00'),
        ];
        $base = ['groupTypes' => [$this->groupType()], 'groups' => [$this->group()], 'users' => [$this->user()]];

        // 行: feature 1(一致)・feature 3(イベント無し)・feature 2 は removed なのに行がある。
        $result = $this->reconcile($rows, new SeededDataCurrent(...$base, groupFeatureAssignments: [
            ['group_id' => self::GROUP, 'feature_id' => 1, 'assigned_by' => self::USER, 'created_at' => '2026-01-07 00:00:00'],
            ['group_id' => self::GROUP, 'feature_id' => 2, 'assigned_by' => self::USER, 'created_at' => '2026-01-07 00:00:00'],
            ['group_id' => self::GROUP, 'feature_id' => 3, 'assigned_by' => null, 'created_at' => '2026-01-08 00:00:00'],
        ]));
        $features = array_values(array_filter($result->events, fn (RebuiltEvent $e) => $e->eventClass === 'feature.assigned_to_group'));
        $byFeature = [];
        foreach ($features as $event) {
            $byFeature[$event->payload['featureId']] = $event;
        }
        $this->assertSame([2, 3], array_keys($byFeature));
        // feature 2: 最後のイベント(removed)の直後。
        $this->assertSame(5, $byFeature[2]->anchorId);
        $this->assertSame('2026-09-03 10:00:00', $byFeature[2]->createdAt);
        $this->assertSame(self::USER, $byFeature[2]->payload['actorUserId']);
        // feature 3: そのキーのイベントが無く、集約には最初のイベント(id=3)がある → 直前。
        $this->assertSame(3, $byFeature[3]->anchorId);
        $this->assertLessThan(0, $byFeature[3]->subOrder);
        $this->assertNull($byFeature[3]->payload['actorUserId']);

        // 行が無いのに assigned 済み(feature 1・2 とも行が無い)→ feature 1 に removed を導出する。
        $extra = $this->reconcile($rows, new SeededDataCurrent(...$base));
        $removed = array_values(array_filter($extra->events, fn (RebuiltEvent $e) => str_starts_with($e->eventClass, 'feature.')));
        $this->assertCount(1, $removed);
        $this->assertSame('feature.removed_from_group', $removed[0]->eventClass);
        $this->assertSame(1, $removed[0]->payload['featureId']);
        $this->assertNull($removed[0]->payload['actorUserId']);
        $this->assertSame(5, $removed[0]->anchorId);
    }

    public function test_group_feature_assignment_without_any_events_is_placed_after_the_groups_derived_created(): void
    {
        $result = $this->reconcile([], new SeededDataCurrent(
            groupTypes: [$this->groupType()],
            groups: [$this->group()],
            groupFeatureAssignments: [['group_id' => self::GROUP, 'feature_id' => 4, 'assigned_by' => null, 'created_at' => '2026-01-10 00:00:00']],
        ));

        $events = $this->ordered($result);
        $this->assertSame(['group_type.created', 'group.created', 'feature.assigned_to_group'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $this->assertSame(Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-access:'.self::GROUP)->toString(), $events[2]->aggregateUuid);
        $this->assertSame('2026-01-10 00:00:00', $events[2]->createdAt);
    }

    // ---- users ----

    public function test_only_users_without_a_creation_event_get_user_migrated_from_legacy(): void
    {
        $result = $this->reconcile(
            [
                $this->event(1, self::USER, 'user.created_manually', ['attributes' => [], 'createdByUserId' => self::USER]),
                $this->event(2, 'import', 'external_hr.import_applied', ['rows' => [['user_id' => self::USER2, 'external_subject_id' => 'E1', 'changes' => []]], 'actorUserId' => self::USER]),
            ],
            new SeededDataCurrent(users: [
                $this->user(),
                $this->user(['id' => self::USER2]),
                $this->user(['id' => '88888888-8888-4888-8888-888888888888', 'email' => 'c@example.test', 'employee_number' => 'E3', 'hire_date' => '2020-04-01 00:00:00', 'password' => 'secret', 'paid_leave_auto_grant_enabled' => 0, 'created_at' => '2026-01-04 00:00:00']),
            ]),
        );

        $this->assertCount(1, $result->events);
        $event = $result->events[0];
        $this->assertSame('user.migrated_from_legacy', $event->eventClass);
        $this->assertSame('88888888-8888-4888-8888-888888888888', $event->aggregateUuid);
        $this->assertSame(0, $event->anchorId);
        $this->assertSame('2026-01-04 00:00:00', $event->createdAt);
        $this->assertSame('E3', $event->payload['attributes']['employee_number']);
        $this->assertSame('2020-04-01', $event->payload['attributes']['hire_date']);
        $this->assertFalse($event->payload['attributes']['paid_leave_auto_grant_enabled']);
        $this->assertArrayNotHasKey('special_leave_auto_grant_enabled', $event->payload['attributes']);
        $this->assertSame('2026-01-04 00:00:00', $event->payload['attributes']['created_at']);
        $this->assertArrayNotHasKey('password', $event->payload['attributes']);
        $this->assertArrayNotHasKey('termination_date', $event->payload['attributes']);
    }

    public function test_user_without_a_creation_event_but_with_other_events_is_created_just_before_the_first_event(): void
    {
        $result = $this->reconcile(
            [
                $this->event(1, 'other', 'attendance_day.created', [], '2026-08-01 09:00:00'),
                $this->event(2, self::USER, 'user.logged_in', ['wasFirstLogin' => true, 'loggedInAt' => '2026-08-02 09:00:00'], '2026-08-02 09:00:00'),
            ],
            new SeededDataCurrent(users: [$this->user()]),
        );

        $this->assertCount(1, $result->events);
        $this->assertSame(2, $result->events[0]->anchorId);
        $this->assertLessThan(0, $result->events[0]->subOrder);
        $this->assertSame(self::ROW_AT, $result->events[0]->createdAt);
    }

    // ---- memberships ----

    public function test_membership_without_events_is_placed_after_the_user_and_group_creation(): void
    {
        $result = $this->reconcile([], new SeededDataCurrent(
            groupTypes: [$this->groupType()],
            groups: [$this->group()],
            users: [$this->user()],
            memberships: [['user_id' => self::USER, 'group_id' => self::GROUP, 'membership_kind' => 'primary', 'is_primary' => 1, 'created_by' => self::USER, 'created_at' => '2026-01-11 00:00:00']],
        ));

        $events = $this->ordered($result);
        $this->assertSame(['group_type.created', 'user.migrated_from_legacy', 'group.created', 'membership.added'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $membership = $events[3];
        $this->assertSame(UserManagementStreamId::for('user-membership', self::USER), $membership->aggregateUuid);
        $this->assertSame(['userId' => self::USER, 'groupId' => self::GROUP, 'membershipKind' => 'primary', 'isPrimary' => true, 'actorUserId' => self::USER], $membership->payload);
        $this->assertSame('2026-01-11 00:00:00', $membership->createdAt);
    }

    public function test_membership_is_folded_from_added_primary_changed_and_removed(): void
    {
        $aggregate = UserManagementStreamId::for('user-membership', self::USER);
        $rows = [
            $this->event(1, self::USER, 'user.created_manually', ['attributes' => [], 'createdByUserId' => self::USER]),
            $this->event(2, $aggregate, 'membership.added', ['userId' => self::USER, 'groupId' => self::GROUP, 'membershipKind' => 'member', 'isPrimary' => false, 'actorUserId' => self::USER]),
            $this->event(3, $aggregate, 'membership.primary_changed', ['userId' => self::USER, 'groupId' => self::GROUP, 'isPrimary' => true, 'actorUserId' => self::USER], '2026-09-04 10:00:00'),
        ];
        $base = ['groupTypes' => [$this->groupType()], 'groups' => [$this->group()], 'users' => [$this->user()]];
        $row = ['user_id' => self::USER, 'group_id' => self::GROUP, 'membership_kind' => 'primary', 'is_primary' => 1, 'created_by' => self::USER, 'created_at' => self::ROW_AT];

        $same = $this->reconcile($rows, new SeededDataCurrent(...$base, memberships: [$row]));
        $this->assertSame([], array_values(array_filter($same->events, fn (RebuiltEvent $e) => $e->eventClass === 'membership.added')));

        $different = $this->reconcile($rows, new SeededDataCurrent(...$base, memberships: [['is_primary' => 0, 'membership_kind' => 'member'] + $row]));
        $added = array_values(array_filter($different->events, fn (RebuiltEvent $e) => $e->eventClass === 'membership.added'));
        $this->assertCount(1, $added);
        $this->assertSame(3, $added[0]->anchorId);
        $this->assertFalse($added[0]->payload['isPrimary']);
        $this->assertCount(1, $different->mismatches['memberships']);

        $missingRow = $this->reconcile($rows, new SeededDataCurrent(...$base));
        $this->assertSame([], $missingRow->issues);
        $removed = array_values(array_filter($missingRow->events, fn (RebuiltEvent $e) => $e->eventClass === 'membership.removed'));
        $this->assertCount(1, $removed);
        $this->assertSame(3, $removed[0]->anchorId);
        $this->assertSame(['userId' => self::USER, 'groupId' => self::GROUP, 'actorUserId' => null], $removed[0]->payload);
    }

    // ---- external_identities ----

    public function test_external_identity_without_events_is_linked_right_after_the_users_creation(): void
    {
        $result = $this->reconcile([], new SeededDataCurrent(
            users: [$this->user()],
            externalIdentities: [[
                'user_id' => self::USER, 'provider' => 'MICROSOFT_ENTRA', 'external_tenant_id' => 'tenant', 'external_subject_id' => 'entra-1', 'external_code' => null,
                'email' => 'a@example.test', 'status' => 'active', 'created_at' => '2026-01-12 00:00:00',
            ]],
        ));

        $events = $this->ordered($result);
        $this->assertSame(['user.migrated_from_legacy', 'external_identity.linked'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $this->assertSame(UserManagementStreamId::for('user-identity', self::USER), $events[1]->aggregateUuid);
        $this->assertSame('entra-1', $events[1]->payload['externalSubjectId']);
        $this->assertSame('tenant', $events[1]->payload['externalTenantId']);
        $this->assertNull($events[1]->payload['actorUserId']);
        $this->assertSame('2026-01-12 00:00:00', $events[1]->createdAt);
    }

    public function test_external_identity_created_by_a_user_event_matches_and_an_unlinked_row_without_events_gets_linked_then_unlinked(): void
    {
        $matched = $this->reconcile(
            [$this->event(1, self::USER, 'user.onboarded_as_admin', ['entraUserId' => 'entra-1', 'name' => 'A', 'email' => null, 'authMethod' => 'sso'])],
            new SeededDataCurrent(users: [$this->user()], externalIdentities: [['user_id' => self::USER, 'provider' => 'MICROSOFT_ENTRA', 'external_tenant_id' => null, 'external_subject_id' => 'entra-1', 'external_code' => null, 'email' => null, 'status' => 'active', 'created_at' => self::ROW_AT]]),
        );
        $this->assertSame([], $matched->events);

        $unlinked = $this->reconcile([], new SeededDataCurrent(users: [$this->user()], externalIdentities: [['user_id' => self::USER, 'provider' => 'EXTERNAL_HR', 'external_tenant_id' => null, 'external_subject_id' => 'E1', 'external_code' => null, 'email' => null, 'status' => 'unlinked', 'created_at' => self::ROW_AT, 'id' => 5]]));
        $this->assertSame([], $unlinked->issues);
        $events = $this->ordered($unlinked);
        $this->assertSame(['user.migrated_from_legacy', 'external_identity.linked', 'external_identity.unlinked'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $this->assertSame('E1', $events[2]->payload['externalSubjectId']);
        $this->assertNull($events[2]->payload['actorUserId']);
    }

    // ---- field_authorities ----

    public function test_field_authority_is_placed_at_the_head_or_before_the_first_event_and_mismatches_go_after_the_last_event(): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'field-authority-catalog')->toString();
        $row = fn (string $key, string $type, ?string $provider = null) => ['field_key' => $key, 'authority_type' => $type, 'provider' => $provider, 'created_at' => '2026-01-13 00:00:00'];

        $none = $this->reconcile([], new SeededDataCurrent(fieldAuthorities: [$row('email', 'LOCAL')]));
        $this->assertSame(0, $none->events[0]->anchorId);
        $this->assertSame('2026-01-13 00:00:00', $none->events[0]->createdAt);
        $this->assertSame(['fieldKey' => 'email', 'authorityType' => 'LOCAL', 'provider' => null, 'actorUserId' => null], $none->events[0]->payload);

        $events = [
            $this->event(5, 'other', 'attendance_day.created', [], '2026-08-01 09:00:00'),
            $this->event(6, $aggregate, 'user.field_authority_changed', ['fieldKey' => 'email', 'authorityType' => 'EXTERNAL', 'provider' => 'MICROSOFT_ENTRA', 'actorUserId' => self::USER], '2026-09-02 10:00:00'),
        ];
        $result = $this->reconcile($events, new SeededDataCurrent(fieldAuthorities: [$row('email', 'EXTERNAL', 'MICROSOFT_ENTRA'), $row('name', 'LOCAL'), $row('phone', 'EXTERNAL', 'X')]));
        $byKey = [];
        foreach ($result->events as $event) {
            $byKey[$event->payload['fieldKey']] = $event;
        }
        $this->assertSame(['name', 'phone'], array_keys($byKey));
        $this->assertSame(6, $byKey['name']->anchorId);
        $this->assertLessThan(0, $byKey['name']->subOrder);
        $this->assertSame('2026-01-13 00:00:00', $byKey['name']->createdAt);

        $mismatch = $this->reconcile($events, new SeededDataCurrent(fieldAuthorities: [$row('email', 'LOCAL')]));
        $this->assertCount(1, $mismatch->events);
        $this->assertSame(6, $mismatch->events[0]->anchorId);
        $this->assertGreaterThan(0, $mismatch->events[0]->subOrder);
        $this->assertCount(1, $mismatch->mismatches['field_authorities']);
    }

    // ---- company_calendar_years ----

    public function test_calendar_year_without_events_is_created_at_the_head_and_published_with_published_at_and_by(): void
    {
        $result = $this->reconcile([], new SeededDataCurrent(
            users: [$this->user()],
            calendarYears: [$this->year(['status' => 'published', 'published_at' => '2026-03-31 09:00:00', 'published_by_user_id' => self::USER])],
        ));

        $events = $this->ordered($result);
        $this->assertSame(['user.migrated_from_legacy', 'company_calendar_year.created', 'company_calendar_year.published'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $created = $events[1];
        $this->assertSame(self::YEAR, $created->aggregateUuid);
        $this->assertSame(['companyCalendarId' => self::CALENDAR, 'fiscalYear' => 2026, 'startsOn' => '2026-04-01', 'endsOn' => '2027-03-31', 'generatedFrom' => 'manual', 'createdByUserId' => null], $created->payload);
        $this->assertSame(self::ROW_AT, $created->createdAt);
        $this->assertSame(['publishedByUserId' => self::USER], $events[2]->payload);
        $this->assertSame('2026-03-31 09:00:00', $events[2]->createdAt);
    }

    public function test_archived_calendar_year_with_published_at_gets_published_then_archived_and_a_null_actor_is_allowed(): void
    {
        $archived = $this->reconcile([], new SeededDataCurrent(
            users: [$this->user()],
            calendarYears: [$this->year(['status' => 'archived', 'published_at' => '2026-03-31 09:00:00', 'published_by_user_id' => self::USER])],
        ));
        $this->assertSame(
            ['user.migrated_from_legacy', 'company_calendar_year.created', 'company_calendar_year.published', 'company_calendar_year.archived'],
            array_map(fn (RebuiltEvent $e) => $e->eventClass, $this->ordered($archived)),
        );

        $noActor = $this->reconcile([], new SeededDataCurrent(calendarYears: [$this->year(['status' => 'published', 'published_at' => '2026-03-31 09:00:00', 'published_by_user_id' => null])]));
        $this->assertSame([], $noActor->issues);
        $this->assertNull($this->ordered($noActor)[1]->payload['publishedByUserId']);

        $draftWhilePublished = $this->reconcile(
            [
                $this->event(1, self::YEAR, 'company_calendar_year.created', ['companyCalendarId' => self::CALENDAR, 'fiscalYear' => 2026, 'startsOn' => '2026-04-01', 'endsOn' => '2027-03-31', 'generatedFrom' => 'manual', 'createdByUserId' => null]),
                $this->event(2, self::YEAR, 'company_calendar_year.published', ['publishedByUserId' => self::USER]),
            ],
            new SeededDataCurrent(users: [$this->user()], calendarYears: [$this->year(['status' => 'draft'])]),
        );
        $this->assertSame([], $draftWhilePublished->issues);
        $unpublished = array_values(array_filter($draftWhilePublished->events, fn (RebuiltEvent $e) => $e->eventClass === 'company_calendar_year.unpublished'));
        $this->assertCount(1, $unpublished);
        $this->assertSame(2, $unpublished[0]->anchorId);
        $this->assertNull($unpublished[0]->payload['unpublishedByUserId']);
    }

    public function test_calendar_year_with_a_published_event_and_matching_status_derives_nothing(): void
    {
        $result = $this->reconcile(
            [
                $this->event(1, self::YEAR, 'company_calendar_year.created', ['companyCalendarId' => self::CALENDAR, 'fiscalYear' => 2026, 'startsOn' => '2026-04-01', 'endsOn' => '2027-03-31', 'generatedFrom' => 'manual', 'createdByUserId' => null]),
                $this->event(2, self::YEAR, 'company_calendar_year.published', ['publishedByUserId' => self::USER]),
            ],
            new SeededDataCurrent(users: [$this->user()], calendarYears: [$this->year(['status' => 'published', 'published_by_user_id' => self::USER, 'published_at' => self::AT])]),
        );

        $this->assertSame([], array_values(array_filter($result->events, fn (RebuiltEvent $e) => str_starts_with($e->eventClass, 'company_calendar_year.'))));
    }

    // ---- company_calendar_days ----

    public function test_calendar_days_without_events_follow_the_year_creation_and_differing_days_follow_the_last_event(): void
    {
        $day = fn (string $date, array $over = []) => $over + [
            'calendar_id' => self::YEAR, 'date' => $date.' 00:00:00', 'day_type' => 'weekday', 'is_working_day' => 1, 'is_legal_holiday' => 0, 'is_company_holiday' => 0,
            'is_public_holiday' => 0, 'public_holiday_name' => null, 'schedule_state' => 'WORK', 'note' => null, 'created_at' => '2026-02-01 00:00:00',
        ];

        // イベント無し: 年も導出。日は作成の直後(行の created_at ごと)。
        $result = $this->reconcile([], new SeededDataCurrent(calendarYears: [$this->year()], calendarDays: [
            $day('2026-04-02'), $day('2026-04-01', ['created_at' => '2026-02-02 00:00:00']), $day('2026-04-03', ['day_type' => 'legal_holiday', 'is_working_day' => 0, 'is_legal_holiday' => 1, 'schedule_state' => 'OFF']),
        ]));
        $events = $this->ordered($result);
        $this->assertSame(['company_calendar_year.created', 'company_calendar_year.days_updated', 'company_calendar_year.days_updated'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $events));
        $this->assertSame(['2026-04-02', '2026-04-03'], array_column($events[1]->payload['days'], 'date'));
        $this->assertSame('2026-02-01 00:00:00', $events[1]->createdAt);
        $this->assertSame(['2026-04-01'], array_column($events[2]->payload['days'], 'date'));
        $this->assertNull($events[1]->payload['updatedByUserId']);
        $this->assertSame('OFF', $events[1]->payload['days'][1]['schedule_state']);

        // イベントあり: 一致する日は出ない。違う日だけ、最後のイベントの直後。
        $rows = [
            $this->event(1, self::YEAR, 'company_calendar_year.created', ['companyCalendarId' => self::CALENDAR, 'fiscalYear' => 2026, 'startsOn' => '2026-04-01', 'endsOn' => '2027-03-31', 'generatedFrom' => 'manual', 'createdByUserId' => null]),
            $this->event(2, self::YEAR, 'company_calendar_year.days_updated', ['days' => [
                ['date' => '2026-04-01', 'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false, 'note' => null],
                ['date' => '2026-04-02', 'day_type' => 'company_holiday', 'is_working_day' => false, 'is_legal_holiday' => false, 'is_company_holiday' => true, 'note' => '休'],
            ], 'updatedByUserId' => null], '2026-09-05 10:00:00'),
        ];
        $different = $this->reconcile($rows, new SeededDataCurrent(calendarYears: [$this->year()], calendarDays: [$day('2026-04-01'), $day('2026-04-02')]));
        $this->assertCount(1, $different->events);
        $this->assertSame(2, $different->events[0]->anchorId);
        $this->assertSame('2026-09-05 10:00:00', $different->events[0]->createdAt);
        $this->assertSame(['2026-04-02'], array_column($different->events[0]->payload['days'], 'date'));
        $this->assertCount(1, $different->mismatches['company_calendar_days']);
    }

    public function test_days_updated_under_a_company_calendar_id_is_moved_to_the_year_that_contains_the_dates(): void
    {
        $dayEvent = fn (int $id, string $aggregate, string $date) => $this->event($id, $aggregate, 'company_calendar_year.days_updated', ['days' => [
            ['date' => $date, 'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false, 'note' => null],
        ], 'updatedByUserId' => null]);
        $current = new SeededDataCurrent(
            companyCalendarIds: [self::CALENDAR],
            calendarYears: [$this->year(), $this->year(['id' => '99999999-9999-4999-8999-999999999999', 'fiscal_year' => 2027, 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'])],
        );

        $result = $this->reconcile([$dayEvent(10, self::CALENDAR, '2026-05-01'), $dayEvent(11, self::CALENDAR, '2027-05-01')], $current);

        $this->assertSame([], $result->issues);
        $this->assertSame([10, 11], $result->consumedIds);
        $this->assertSame([
            ['id' => 10, 'from' => self::CALENDAR, 'to' => self::YEAR],
            ['id' => 11, 'from' => self::CALENDAR, 'to' => '99999999-9999-4999-8999-999999999999'],
        ], $result->remapped);
        $moved = array_values(array_filter($result->events, fn (RebuiltEvent $e) => $e->eventClass === 'company_calendar_year.days_updated' && $e->anchorId === 10 && $e->subOrder === 0));
        $this->assertCount(1, $moved);
        $this->assertSame(self::YEAR, $moved[0]->aggregateUuid);
        $this->assertSame(self::YEAR, $moved[0]->meta['aggregate-root-uuid'] ?? self::YEAR);
    }

    public function test_days_updated_under_an_unknown_aggregate_or_outside_the_calendars_years_is_an_issue(): void
    {
        $dayEvent = fn (int $id, string $aggregate, string $date) => $this->event($id, $aggregate, 'company_calendar_year.days_updated', ['days' => [
            ['date' => $date, 'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false, 'note' => null],
        ], 'updatedByUserId' => null]);
        $current = new SeededDataCurrent(companyCalendarIds: [self::CALENDAR], calendarYears: [$this->year()]);

        $unknown = $this->reconcile([$dayEvent(10, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '2026-05-01')], $current);
        $this->assertCount(1, $unknown->issues);
        $this->assertSame([], $unknown->consumedIds);

        $outside = $this->reconcile([$dayEvent(10, self::CALENDAR, '2030-05-01')], $current);
        $this->assertCount(1, $outside->issues);
        $this->assertStringContainsString('2030-05-01', $outside->issues[0]['reason']);

        // 作成イベントのある(削除済み等の)年の集約は付け替えず、問題にもしない。
        $deletedYear = '77777777-0000-4777-8777-777777777777';
        $kept = $this->reconcile([
            $this->event(1, $deletedYear, 'company_calendar_year.created', ['companyCalendarId' => self::CALENDAR, 'fiscalYear' => 2020, 'startsOn' => '2020-04-01', 'endsOn' => '2021-03-31', 'generatedFrom' => 'manual', 'createdByUserId' => null]),
            $dayEvent(2, $deletedYear, '2020-05-01'),
        ], $current);
        $this->assertSame([], $kept->issues);
        $this->assertSame([], $kept->remapped);
    }

    // ---- EventStoreRebuilder への組み込み ----

    public function test_rebuilder_orders_derived_events_renumbers_ids_and_versions_and_reports_the_reconcile_summary(): void
    {
        $rebuilder = new EventStoreRebuilder;
        $rows = [
            $this->event(1, self::USER, 'user.logged_in', ['wasFirstLogin' => true, 'loggedInAt' => self::AT]),
            $this->event(2, self::USER, 'user.hire_date_set', ['hireDate' => '2020-04-01']),
        ];
        $result = $rebuilder->rebuild($rows, [], [], new SeededDataCurrent(users: [$this->user()], groupTypes: [$this->groupType()]));

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertSame(['group_type.created', 'user.migrated_from_legacy', 'user.logged_in', 'user.hire_date_set'], array_column($result->rows, 'event_class'));
        $this->assertSame(range(1, 4), array_column($result->rows, 'id'));
        $userRows = array_values(array_filter($result->rows, fn (array $r) => $r['aggregate_uuid'] === self::USER));
        $this->assertSame([1, 2, 3], array_column($userRows, 'aggregate_version'));
        foreach ($result->rows as $row) {
            $this->assertSame($row['aggregate_uuid'], $row['meta_data']['aggregate-root-uuid']);
            $this->assertSame($row['id'], $row['meta_data']['stored-event-id']);
        }
        $this->assertSame(['group_types' => ['group_type.created' => 1], 'users' => ['user.migrated_from_legacy' => 1]], $result->report['reconcile']['derived']);
        $this->assertSame(0, $result->report['versions_changed_outside_leave']);

        $without = $rebuilder->rebuild($rows);
        $this->assertSame(['user.logged_in', 'user.hire_date_set'], array_column($without->rows, 'event_class'));
    }

    public function test_rebuilder_fails_when_the_reconcile_cannot_decide(): void
    {
        $deleted = [
            $this->event(1, self::YEAR, 'company_calendar_year.created', ['companyCalendarId' => self::CALENDAR, 'fiscalYear' => 2026, 'startsOn' => '2026-04-01', 'endsOn' => '2027-03-31', 'generatedFrom' => 'manual', 'createdByUserId' => null]),
            $this->event(2, self::YEAR, 'company_calendar_year.deleted', ['deletedByUserId' => self::USER]),
        ];
        $result = (new EventStoreRebuilder)->rebuild($deleted, [], [], new SeededDataCurrent(calendarYears: [$this->year()]));

        $this->assertFalse($result->ok);
        $this->assertSame('company_calendar_year '.self::YEAR, $result->unsupported[0]['key']);
    }

    // ---- 部品 ----

    /** @return array<string, mixed> */
    private function groupType(array $over = []): array
    {
        return $over + [
            'id' => 1, 'code' => 'department', 'name' => '部署', 'display_order' => 10, 'is_system' => 0, 'status' => 'active', 'membership_limit_type' => 'unlimited',
            'max_memberships_per_user' => null, 'primary_membership_required' => 0, 'max_primary_memberships' => null, 'created_at' => self::ROW_AT,
        ];
    }

    private function groupTypeAggregate(): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:department')->toString();
    }

    /** @return array<string, mixed> */
    private function groupTypeCreated(): array
    {
        return [
            'groupTypeId' => 1, 'code' => 'department', 'name' => '部署', 'displayOrder' => 10, 'membershipLimitType' => 'unlimited', 'maxMembershipsPerUser' => null,
            'primaryMembershipRequired' => false, 'maxPrimaryMemberships' => null, 'actorUserId' => null, 'isSystem' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function group(array $over = []): array
    {
        return $over + [
            'id' => self::GROUP, 'group_type_id' => 1, 'name' => '開発部', 'code' => 'dev', 'description' => null, 'parent_group_id' => null, 'status' => 'active', 'created_at' => self::ROW_AT,
        ];
    }

    /** @return array<string, mixed> */
    private function role(array $over = []): array
    {
        return $over + ['id' => 7, 'code' => 'admin', 'name' => '管理者', 'description' => null, 'is_system' => 0, 'status' => 'active', 'created_at' => self::ROW_AT];
    }

    /** @return array<string, mixed> */
    private function user(array $over = []): array
    {
        return $over + [
            'id' => self::USER, 'name' => '山田', 'email' => 'a@example.test', 'account_status' => 'active', 'source_type' => 'local', 'employment_status' => 'active',
            'timezone' => 'Asia/Tokyo', 'created_at' => self::ROW_AT,
        ];
    }

    /** @return array<string, mixed> */
    private function year(array $over = []): array
    {
        return $over + [
            'id' => self::YEAR, 'company_calendar_id' => self::CALENDAR, 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'draft',
            'generated_from' => 'manual', 'published_at' => null, 'published_by_user_id' => null, 'created_at' => self::ROW_AT,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function event(int $id, ?string $aggregate, string $class, array $payload, string $at = self::AT): StoredEventRow
    {
        return new StoredEventRow($id, $aggregate, 1, $class, $payload, ['aggregate-root-uuid' => $aggregate], $at);
    }

    /** @param  list<StoredEventRow>  $rows */
    private function reconcile(array $rows, SeededDataCurrent $current): ReconcileResult
    {
        return (new SeededDataReconciler)->reconcile($rows, $current);
    }

    /** @return list<RebuiltEvent> 出力の並び(anchorId・subOrder・出力順) */
    private function ordered(ReconcileResult $result): array
    {
        $indexed = [];
        foreach ($result->events as $i => $event) {
            $indexed[] = [$event, $i];
        }
        usort($indexed, fn (array $a, array $b) => [$a[0]->anchorId, $a[0]->subOrder, $a[1]] <=> [$b[0]->anchorId, $b[0]->subOrder, $b[1]]);

        return array_map(fn (array $pair) => $pair[0], $indexed);
    }
}
