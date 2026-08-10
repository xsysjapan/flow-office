<?php

namespace App\Domain\EventSourcing\Migration;

use App\Domain\UserManagement\Support\UserManagementStreamId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Rewrites the one-time legacy cut-over history into the current domain flow.
 *
 * This service intentionally uses raw StoredEvent rows. Replaying deprecated
 * event classes before they have been normalized would reintroduce the legacy
 * user-role projection and would make a dry-run impossible.
 */
final class StoredEventHistoryNormalizer
{
    public function __construct(
        private readonly AttendanceBackOfficeTaskHistoryBackfiller $attendanceTaskBackfiller,
    ) {}

    private const LEGACY_USER_EVENTS = [
        'user.migrated_from_legacy',
        'user.roles_changed',
        'user.roles_migrated_from_legacy',
    ];

    private const ROLE_GROUPS = [
        'admin' => 'SYSTEM_ADMINISTRATORS',
        'hr_staff' => 'HUMAN_RESOURCES_USERS',
        'backoffice_staff' => 'BACKOFFICE_USERS',
    ];

    /** @return array<string, int|list<string>> */
    public function inspect(): array
    {
        $eventAliases = array_keys(config('event-sourcing.event_class_map', []));
        $storedAliases = DB::table('stored_events')->distinct()->pluck('event_class')->all();
        $unknownAliases = array_values(array_diff($storedAliases, $eventAliases));
        sort($unknownAliases);

        return [
            'stored_events' => DB::table('stored_events')->count(),
            'legacy_cutover_events' => DB::table('stored_events')
                ->whereNotNull(DB::raw("JSON_EXTRACT(meta_data, '$.legacy_migration.legacy_id')"))
                ->count(),
            'legacy_user_events' => DB::table('stored_events')->whereIn('event_class', self::LEGACY_USER_EVENTS)->count(),
            'users_to_backfill' => DB::table('stored_events')->whereIn('event_class', self::LEGACY_USER_EVENTS)
                ->distinct()->count('aggregate_uuid'),
            'attendance_month_workflows' => $this->attendanceWorkflowDrafts()->count(),
            'attendance_backoffice_tasks_to_backfill' => $this->attendanceTaskBackfiller->countMissing(),
            'legacy_store_events' => Schema::hasTable('legacy_stored_events')
                ? DB::table('legacy_stored_events')->count()
                : 0,
            'unknown_event_aliases' => $unknownAliases,
            'unsupported_role_codes' => $this->unsupportedRoleCodes(),
        ];
    }

    /** @return array<string, int|list<string>> */
    public function apply(string $backupTable): array
    {
        $this->assertBackupTableName($backupTable);
        $report = $this->inspect();

        if ($report['unknown_event_aliases'] !== []) {
            throw new RuntimeException('Unknown event aliases exist: '.implode(', ', $report['unknown_event_aliases']));
        }
        if ($report['unsupported_role_codes'] !== []) {
            throw new RuntimeException('Legacy roles without a standard-group mapping exist: '.implode(', ', $report['unsupported_role_codes']));
        }
        if (Schema::hasTable($backupTable) || Schema::hasTable($backupTable.'_legacy')) {
            throw new RuntimeException("Backup table [{$backupTable}] or its legacy companion already exists.");
        }

        // MySQL DDL causes an implicit commit. Create the immutable backups first,
        // then keep every history/projection rewrite in one real transaction.
        $this->backupStoredEvents($backupTable);
        $this->backupLegacyStoredEvents($backupTable.'_legacy');

        DB::transaction(function (): void {
            $this->normalizeUsersAndMemberships();
            $this->normalizeAttendanceMonthWorkflow();
            $this->attendanceTaskBackfiller->apply();
            $this->moveLegacyExports();
        });

        return $this->inspect();
    }

    private function assertBackupTableName(string $table): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,55}\z/', $table) !== 1) {
            throw new RuntimeException('Backup table must be a lower-case SQL identifier of at most 56 characters.');
        }
    }

    private function backupStoredEvents(string $table): void
    {
        Schema::create($table, function ($blueprint): void {
            $blueprint->id();
            $blueprint->uuid('aggregate_uuid')->nullable();
            $blueprint->unsignedBigInteger('aggregate_version')->nullable();
            $blueprint->unsignedTinyInteger('event_version')->default(1);
            $blueprint->string('event_class');
            $blueprint->json('event_properties');
            $blueprint->json('meta_data');
            $blueprint->timestamp('created_at');
            $blueprint->unique(['aggregate_uuid', 'aggregate_version'], 'seh_backup_stream_unique');
        });

        DB::table('stored_events')->orderBy('id')->chunk(500, function ($events) use ($table): void {
            DB::table($table)->insert($events->map(fn ($event) => (array) $event)->all());
        });
    }

    private function backupLegacyStoredEvents(string $table): void
    {
        if (! Schema::hasTable('legacy_stored_events') || Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function ($blueprint): void {
            $blueprint->id();
            $blueprint->uuid('event_id');
            $blueprint->string('aggregate_type');
            $blueprint->uuid('aggregate_id');
            $blueprint->unsignedBigInteger('version');
            $blueprint->string('event_type');
            $blueprint->json('payload');
            $blueprint->json('metadata')->nullable();
            $blueprint->timestamp('occurred_at');
            $blueprint->timestamps();
        });

        DB::table('legacy_stored_events')->orderBy('id')->chunk(500, function ($events) use ($table): void {
            DB::table($table)->insert($events->map(fn ($event) => (array) $event)->all());
        });
    }

    private function normalizeUsersAndMemberships(): void
    {
        if (! DB::table('stored_events')->whereIn('event_class', self::LEGACY_USER_EVENTS)->exists()) {
            return;
        }

        $groups = DB::table('groups')->whereIn('code', ['ALL_USERS', ...array_values(self::ROLE_GROUPS)])
            ->pluck('id', 'code');
        if (! $groups->has('ALL_USERS')) {
            throw new RuntimeException('Standard groups must be seeded before history normalization.');
        }

        DB::table('users')->orderBy('id')->each(function (object $user) use ($groups): void {
            $events = DB::table('stored_events')->where('aggregate_uuid', $user->id)
                ->orderBy('aggregate_version')->get();
            $genesis = $events->firstWhere('event_class', 'user.migrated_from_legacy');
            $registrationAt = $genesis?->created_at ?? $events->first()?->created_at ?? $user->created_at;
            $roleEvents = $events->filter(fn ($event) => in_array($event->event_class, [
                'user.roles_changed', 'user.roles_migrated_from_legacy',
            ], true))->values();
            $initialRoles = $this->initialRoles($roleEvents);

            if ($genesis !== null) {
                $this->replaceLegacyUserGenesis($genesis, $user, $initialRoles);
            }

            DB::table('stored_events')->where('aggregate_uuid', $user->id)
                ->whereIn('event_class', ['user.roles_changed', 'user.roles_migrated_from_legacy'])
                ->delete();
            $this->renumberStream((string) $user->id);

            $membershipFacts = [[
                'event_class' => 'membership.added',
                'group_id' => (string) $groups['ALL_USERS'],
                'actor_id' => (string) $user->id,
                'created_at' => $registrationAt,
            ]];

            $state = [];
            foreach (self::ROLE_GROUPS as $roleCode => $groupCode) {
                $state[$groupCode] = in_array($roleCode, $initialRoles, true);
                if ($state[$groupCode]) {
                    $membershipFacts[] = [
                        'event_class' => 'membership.added',
                        'group_id' => (string) $groups[$groupCode],
                        'actor_id' => (string) $user->id,
                        'created_at' => $registrationAt,
                    ];
                }
            }

            foreach ($roleEvents as $roleEvent) {
                if ($roleEvent->event_class !== 'user.roles_changed') {
                    continue;
                }
                $properties = $this->json($roleEvent->event_properties);
                $newRoles = $properties['newRoleCodes'] ?? [];
                foreach (self::ROLE_GROUPS as $roleCode => $groupCode) {
                    $newState = in_array($roleCode, $newRoles, true);
                    if ($newState === $state[$groupCode]) {
                        continue;
                    }
                    $membershipFacts[] = [
                        'event_class' => $newState ? 'membership.added' : 'membership.removed',
                        'group_id' => (string) $groups[$groupCode],
                        'actor_id' => (string) ($properties['changedByUserId'] ?? $user->id),
                        'created_at' => $roleEvent->created_at,
                    ];
                    $state[$groupCode] = $newState;
                }
            }

            $this->mergeMembershipStream((string) $user->id, $membershipFacts);
            $this->synchronizeMembershipProjection((string) $user->id, $groups, $state, $registrationAt);
            $this->removeTemporaryDirectRoleAssignments((string) $user->id);
        });
    }

    private function replaceLegacyUserGenesis(object $event, object $user, array $initialRoles): void
    {
        $properties = $this->json($event->event_properties);
        $attributes = $properties['attributes'] ?? [];
        $firstLogin = DB::table('stored_events')->where('aggregate_uuid', $user->id)
            ->where('event_class', 'user.logged_in')->orderBy('aggregate_version')->first();
        $firstLoginProperties = $firstLogin === null ? [] : $this->json($firstLogin->event_properties);
        $isFirstSsoLogin = $firstLogin !== null
            && (bool) ($firstLoginProperties['wasFirstLogin'] ?? false)
            && Carbon::parse($firstLogin->created_at)->equalTo(Carbon::parse($event->created_at));

        if ($isFirstSsoLogin) {
            $eventClass = 'user.created_from_sso_login';
            $eventProperties = [
                'entraUserId' => (string) ($attributes['entra_user_id'] ?? $user->entra_user_id),
                'name' => (string) ($attributes['name'] ?? $user->name),
                'email' => (string) ($attributes['email'] ?? $user->email),
            ];
        } elseif (in_array('admin', $initialRoles, true)) {
            $eventClass = 'user.onboarded_as_admin';
            $eventProperties = [
                'entraUserId' => $attributes['entra_user_id'] ?? $user->entra_user_id,
                'name' => (string) ($attributes['name'] ?? $user->name),
                'email' => $attributes['email'] ?? $user->email,
                'authMethod' => 'sso',
            ];
        } else {
            $eventClass = 'user.synced_from_ms365';
            $eventProperties = $this->ms365Properties($attributes, $user);
        }

        DB::table('stored_events')->where('id', $event->id)->update([
            'event_class' => $eventClass,
            'event_properties' => $this->encode($eventProperties),
            'meta_data' => $this->encode($this->normalizedMetadata($event)),
        ]);
    }

    /** @return array<string, mixed> */
    private function ms365Properties(array $attributes, object $user): array
    {
        return [
            'entraUserId' => (string) ($attributes['entra_user_id'] ?? $user->entra_user_id),
            'name' => (string) ($attributes['name'] ?? $user->name),
            'email' => $attributes['email'] ?? $user->email,
            'department' => $attributes['department'] ?? $user->department,
            'jobTitle' => $attributes['job_title'] ?? $user->job_title,
            'employmentStatus' => $attributes['employment_status'] ?? $user->employment_status ?? 'active',
        ];
    }

    /** @param iterable<object> $events @return list<string> */
    private function initialRoles(iterable $events): array
    {
        foreach ($events as $event) {
            $properties = $this->json($event->event_properties);
            if ($event->event_class === 'user.roles_changed') {
                return array_values($properties['previousRoleCodes'] ?? []);
            }
            if ($event->event_class === 'user.roles_migrated_from_legacy') {
                return array_values($properties['roleCodes'] ?? []);
            }
        }

        return [];
    }

    /** @param iterable<string, string> $groups @param array<string, bool> $state */
    private function synchronizeMembershipProjection(string $userId, iterable $groups, array $state, mixed $registrationAt): void
    {
        DB::table('memberships')->updateOrInsert(
            ['user_id' => $userId, 'group_id' => $groups['ALL_USERS']],
            ['membership_kind' => 'member', 'is_primary' => false, 'created_by' => $userId,
                'created_at' => $registrationAt, 'updated_at' => $registrationAt],
        );

        foreach (self::ROLE_GROUPS as $groupCode) {
            if ($state[$groupCode]) {
                DB::table('memberships')->updateOrInsert(
                    ['user_id' => $userId, 'group_id' => $groups[$groupCode]],
                    ['membership_kind' => 'member', 'is_primary' => false, 'created_by' => $userId,
                        'created_at' => $registrationAt, 'updated_at' => $registrationAt],
                );
            } else {
                DB::table('memberships')->where('user_id', $userId)->where('group_id', $groups[$groupCode])->delete();
            }
        }
    }

    private function removeTemporaryDirectRoleAssignments(string $userId): void
    {
        DB::table('roles')->whereIn('code', ['employee', ...array_keys(self::ROLE_GROUPS)])
            ->orderBy('id')
            ->each(function (object $role) use ($userId): void {
                $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "migrated-user-role-assignment:{$userId}:{$role->id}")->toString();
                DB::table('role_assignments')->where('id', $id)->delete();
            });
    }

    /** @param list<array{event_class:string,group_id:string,actor_id:string,created_at:mixed}> $facts */
    private function mergeMembershipStream(string $userId, array $facts): void
    {
        $aggregateUuid = UserManagementStreamId::for('user-membership', $userId);
        $existing = DB::table('stored_events')->where('aggregate_uuid', $aggregateUuid)
            ->orderBy('aggregate_version')->get();

        $known = [];
        foreach ($existing as $event) {
            $properties = $this->json($event->event_properties);
            $known[$event->event_class.'|'.($properties['groupId'] ?? '').'|'.$event->created_at] = true;
        }
        $facts = array_values(array_filter($facts, fn ($fact) => ! isset(
            $known[$fact['event_class'].'|'.$fact['group_id'].'|'.$fact['created_at']]
        )));
        if ($facts === []) {
            return;
        }

        DB::table('stored_events')->where('aggregate_uuid', $aggregateUuid)
            ->update(['aggregate_version' => DB::raw('aggregate_version + 1000000')]);

        $timeline = [];
        foreach ($existing as $event) {
            $timeline[] = ['existing_id' => $event->id, 'created_at' => $event->created_at, 'order' => 10];
        }
        foreach ($facts as $index => $fact) {
            $timeline[] = ['fact' => $fact, 'created_at' => $fact['created_at'], 'order' => $index];
        }
        usort($timeline, fn ($a, $b) => [$a['created_at'], $a['order']] <=> [$b['created_at'], $b['order']]);

        foreach ($timeline as $index => $item) {
            $version = $index + 1;
            if (isset($item['existing_id'])) {
                DB::table('stored_events')->where('id', $item['existing_id'])->update(['aggregate_version' => $version]);

                continue;
            }
            $fact = $item['fact'];
            $properties = [
                'userId' => $userId,
                'groupId' => $fact['group_id'],
                'actorUserId' => $fact['actor_id'],
            ];
            if ($fact['event_class'] === 'membership.added') {
                $properties = [
                    'userId' => $userId, 'groupId' => $fact['group_id'], 'membershipKind' => 'member',
                    'isPrimary' => false, 'actorUserId' => $fact['actor_id'],
                ];
            }
            DB::table('stored_events')->insert([
                'aggregate_uuid' => $aggregateUuid,
                'aggregate_version' => $version,
                'event_version' => 1,
                'event_class' => $fact['event_class'],
                'event_properties' => $this->encode($properties),
                'meta_data' => $this->encode([
                    'aggregate-root-uuid' => $aggregateUuid,
                    'aggregate-root-version' => $version,
                    'history-normalization' => ['source' => 'legacy_user_roles'],
                ]),
                'created_at' => $fact['created_at'],
            ]);
        }
    }

    private function renumberStream(string $aggregateUuid): void
    {
        $events = DB::table('stored_events')->where('aggregate_uuid', $aggregateUuid)
            ->orderBy('aggregate_version')->get();
        DB::table('stored_events')->where('aggregate_uuid', $aggregateUuid)
            ->update(['aggregate_version' => DB::raw('aggregate_version + 1000000')]);
        foreach ($events as $index => $event) {
            $version = $index + 1;
            $metadata = $this->json($event->meta_data);
            $metadata['aggregate-root-version'] = $version;
            DB::table('stored_events')->where('id', $event->id)->update([
                'aggregate_version' => $version,
                'meta_data' => $this->encode($metadata),
            ]);
        }
    }

    private function normalizeAttendanceMonthWorkflow(): void
    {
        $drafts = $this->attendanceWorkflowDrafts()->groupBy(
            fn ($draft) => $this->json($draft->event_properties)['subjectId'],
        );

        foreach ($drafts as $monthId => $monthDrafts) {
            $monthEvents = DB::table('stored_events')->where('aggregate_uuid', $monthId)
                ->orderBy('aggregate_version')->get();
            $submissions = $monthEvents->where('event_class', 'attendance_month.submitted')->values();

            foreach ($monthDrafts->values() as $cycle => $draft) {
                $submission = $submissions->get($cycle);
                if ($submission === null) {
                    continue;
                }
                // Some lock/share backfills were inserted before the workflow
                // draft while carrying a later timestamp. Preserve the real
                // draft time and use one-second causal offsets only for those
                // globally out-of-order rows (the schema has no microseconds).
                $hasLegacyGlobalOrder = $draft->id > $submission->id;
                $submissionAt = $hasLegacyGlobalOrder
                    ? Carbon::parse($draft->created_at)->addSecond()
                    : Carbon::parse($draft->created_at);
                $nextSubmissionVersion = $submissions->get($cycle + 1)?->aggregate_version ?? PHP_INT_MAX;
                $cycleEvents = $monthEvents->filter(fn ($event) => $event->aggregate_version >= $submission->aggregate_version
                    && $event->aggregate_version < $nextSubmissionVersion
                );
                $this->alignEventTime($submission, $submissionAt);
                foreach ($cycleEvents->whereIn('event_class', ['attendance_month.locked', 'attendance_month.shared']) as $event) {
                    $properties = $this->json($event->event_properties);
                    if ($event->event_class === 'attendance_month.locked') {
                        $properties['workflowRequestId'] = $draft->aggregate_uuid;
                        DB::table('attendance_locks')
                            ->where('user_id', $properties['userId'])
                            ->whereDate('period_start_date', $properties['periodStartDate'])
                            ->whereDate('period_end_date', $properties['periodEndDate'])
                            ->update(['locked_at' => $submissionAt, 'workflow_request_id' => $draft->aggregate_uuid]);
                    } else {
                        DB::table('entity_shares')
                            ->where('shareable_type', 'attendance_month')
                            ->where('shareable_id', $monthId)
                            ->where('shared_with_user_id', $properties['sharedWithUserId'])
                            ->update(['shared_at' => $submissionAt]);
                    }
                    $this->alignEventTime($event, $submissionAt, $properties);
                }
                DB::table('attendance_months')->where('id', $monthId)->update(['submitted_at' => $submissionAt]);

                if ($hasLegacyGlobalOrder) {
                    $workflowSubmitted = DB::table('stored_events')->where('aggregate_uuid', $draft->aggregate_uuid)
                        ->where('event_class', 'workflow_request.submitted')->first();
                    if ($workflowSubmitted !== null) {
                        $workflowSubmittedAt = Carbon::parse($draft->created_at)->addSeconds(2);
                        $this->alignEventTime($workflowSubmitted, $workflowSubmittedAt);
                        DB::table('workflow_requests')->where('id', $draft->aggregate_uuid)
                            ->update(['submitted_at' => $workflowSubmittedAt]);
                    }
                }

                $workflowTerminal = DB::table('stored_events')->where('aggregate_uuid', $draft->aggregate_uuid)
                    ->whereIn('event_class', ['workflow_request.approved', 'workflow_request.returned', 'workflow_request.cancelled'])
                    ->orderBy('aggregate_version')->first();
                if ($workflowTerminal === null) {
                    continue;
                }
                $attendanceTerminalClasses = match ($workflowTerminal->event_class) {
                    'workflow_request.approved' => ['attendance_month.approved'],
                    'workflow_request.returned' => ['attendance_month.returned', 'attendance_month.unlocked'],
                    'workflow_request.cancelled' => ['attendance_month.submission_cancelled', 'attendance_month.unlocked'],
                };
                foreach ($cycleEvents->whereIn('event_class', $attendanceTerminalClasses) as $event) {
                    $this->alignEventTime($event, $workflowTerminal->created_at);
                }
                match ($workflowTerminal->event_class) {
                    'workflow_request.approved' => DB::table('attendance_months')->where('id', $monthId)
                        ->update(['approved_at' => $workflowTerminal->created_at]),
                    'workflow_request.returned' => DB::table('attendance_months')->where('id', $monthId)
                        ->update(['returned_at' => $workflowTerminal->created_at]),
                    'workflow_request.cancelled' => null,
                };
                if (in_array($workflowTerminal->event_class, ['workflow_request.returned', 'workflow_request.cancelled'], true)) {
                    $lock = $cycleEvents->firstWhere('event_class', 'attendance_month.locked');
                    if ($lock !== null) {
                        $lockProperties = $this->json($lock->event_properties);
                        DB::table('attendance_locks')
                            ->where('user_id', $lockProperties['userId'])
                            ->whereDate('period_start_date', $lockProperties['periodStartDate'])
                            ->whereDate('period_end_date', $lockProperties['periodEndDate'])
                            ->update(['unlocked_at' => $workflowTerminal->created_at]);
                    }
                }
            }
        }
    }

    private function alignEventTime(object $event, mixed $createdAt, ?array $properties = null): void
    {
        DB::table('stored_events')->where('id', $event->id)->update([
            'created_at' => $createdAt,
            'event_properties' => $this->encode($properties ?? $this->json($event->event_properties)),
            'meta_data' => $this->encode($this->normalizedMetadata($event)),
        ]);
    }

    private function attendanceWorkflowDrafts()
    {
        return DB::table('stored_events')->where('event_class', 'workflow_request.drafted')
            ->get()->filter(fn ($event) => ($this->json($event->event_properties)['subjectType'] ?? null) === 'attendance_month'
                && ($this->json($event->event_properties)['subjectId'] ?? null) !== null
            )->sortBy([['created_at', 'asc'], ['id', 'asc']])->values();
    }

    private function moveLegacyExports(): void
    {
        if (! Schema::hasTable('legacy_stored_events')) {
            return;
        }
        DB::table('legacy_stored_events')->where('event_type', 'export.created')->orderBy('id')
            ->each(function (object $legacy): void {
                $payload = $this->json($legacy->payload);
                DB::table('stored_events')->insert([
                    'aggregate_uuid' => $legacy->aggregate_id,
                    'aggregate_version' => $legacy->version,
                    'event_version' => 1,
                    'event_class' => 'export.created',
                    'event_properties' => $this->encode([
                        'exportType' => $payload['export_type'],
                        'params' => $payload['params'] ?? [],
                        'requestedByUserId' => $payload['requested_by_user_id'],
                        'rowCount' => (int) ($payload['row_count'] ?? 0),
                    ]),
                    'meta_data' => $this->encode([
                        'aggregate-root-uuid' => $legacy->aggregate_id,
                        'aggregate-root-version' => $legacy->version,
                        'history-normalization' => ['source' => 'legacy_stored_events', 'legacy_event_id' => $legacy->event_id],
                    ]),
                    'created_at' => $legacy->occurred_at,
                ]);
            });
        DB::table('legacy_stored_events')->where('event_type', 'export.created')->delete();
    }

    /** @return list<string> */
    private function unsupportedRoleCodes(): array
    {
        $supported = ['employee', ...array_keys(self::ROLE_GROUPS)];
        $codes = [];
        DB::table('stored_events')->whereIn('event_class', ['user.roles_changed', 'user.roles_migrated_from_legacy'])
            ->orderBy('id')->each(function (object $event) use (&$codes): void {
                $properties = $this->json($event->event_properties);
                $codes = [...$codes, ...($properties['previousRoleCodes'] ?? []),
                    ...($properties['newRoleCodes'] ?? []), ...($properties['roleCodes'] ?? [])];
            });

        $unsupported = array_values(array_diff(array_unique($codes), $supported));
        sort($unsupported);

        return $unsupported;
    }

    /** @return array<string, mixed> */
    private function normalizedMetadata(object $event): array
    {
        $metadata = $this->json($event->meta_data);
        $metadata['history-normalization'] = ['version' => 1, 'normalized_at' => now()->toIso8601String()];

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return [];
        }

        return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
