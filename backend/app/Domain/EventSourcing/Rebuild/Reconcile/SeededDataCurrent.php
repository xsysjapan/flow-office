<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

/**
 * ReadModelの現在の行(論点19 決定1の照合の入力)。各テーブルの行を「列名 => 値」の配列で持つ。
 * テーブルが無い環境では空の配列にする(EventStoreRebuildWriter::readSeededData)。
 */
final readonly class SeededDataCurrent
{
    /**
     * @param  list<array<string, mixed>>  $groupTypes
     * @param  list<array<string, mixed>>  $groups
     * @param  list<array<string, mixed>>  $roles
     * @param  list<array<string, mixed>>  $permissionRole  role_id・permission_id
     * @param  list<array<string, mixed>>  $roleFeatures  role_id・feature_id・created_at
     * @param  list<array<string, mixed>>  $roleAssignments
     * @param  list<array<string, mixed>>  $groupFeatureAssignments
     * @param  list<array<string, mixed>>  $users
     * @param  list<array<string, mixed>>  $memberships
     * @param  list<array<string, mixed>>  $externalIdentities
     * @param  list<array<string, mixed>>  $fieldAuthorities
     * @param  list<string>  $companyCalendarIds  company_calendars.id(旧集約UUIDの付け替え判定に使う)
     * @param  list<array<string, mixed>>  $companyCalendars  company_calendars の行(id・is_default・created_at。is_default の照合に使う)
     * @param  list<array<string, mixed>>  $calendarYears
     * @param  list<array<string, mixed>>  $calendarDays
     * @param  ?string  $defaultTimezone  system_settings.default_timezone(作成系イベントが users.timezone に書く既定値。論点19 決定10-3)
     */
    public function __construct(
        public array $groupTypes = [],
        public array $groups = [],
        public array $roles = [],
        public array $permissionRole = [],
        public array $roleFeatures = [],
        public array $roleAssignments = [],
        public array $groupFeatureAssignments = [],
        public array $users = [],
        public array $memberships = [],
        public array $externalIdentities = [],
        public array $fieldAuthorities = [],
        public array $companyCalendarIds = [],
        public array $calendarYears = [],
        public array $calendarDays = [],
        public array $companyCalendars = [],
        public ?string $defaultTimezone = null,
    ) {}
}
