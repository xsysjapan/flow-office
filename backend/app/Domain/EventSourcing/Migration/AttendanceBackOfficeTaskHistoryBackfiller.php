<?php

namespace App\Domain\EventSourcing\Migration;

use App\Domain\BackOffice\Aggregates\BackOfficeTaskAggregate;
use App\Models\AttendanceMonth;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class AttendanceBackOfficeTaskHistoryBackfiller
{
    public function countMissing(): int
    {
        return $this->missingMonths()->count();
    }

    public function apply(): int
    {
        $created = 0;

        $this->missingMonths()->with('user')->orderBy('approved_at')->each(function (AttendanceMonth $month) use (&$created): void {
            $approval = DB::table('stored_events')
                ->where('aggregate_uuid', $month->id)
                ->where('event_class', 'attendance_month.approved')
                ->orderBy('aggregate_version')
                ->first();
            if ($approval === null) {
                throw new RuntimeException("Approved attendance month [{$month->id}] has no attendance_month.approved event.");
            }

            $occurredAt = CarbonImmutable::parse($approval->created_at);
            $taskId = Uuid::uuid5(Uuid::NAMESPACE_URL, "migrated-attendance-backoffice-task:{$month->id}")->toString();

            BackOfficeTaskAggregate::retrieve($taskId)
                ->create(
                    sourceType: 'attendance_month',
                    sourceId: $month->id,
                    taskType: 'attendance_month_confirmation',
                    title: "月次勤怠確認: {$month->user?->name} ({$month->year_month})",
                    assignedDepartment: '人事部',
                    dueOn: $occurredAt->addDays(7)->toDateString(),
                    occurredAt: $occurredAt,
                    metaData: [
                        'history-normalization' => [
                            'source' => 'attendance_month.approved',
                            'source_event_id' => $approval->id,
                        ],
                    ],
                )
                ->persist();

            // Spatie's repository always stamps the physical row with now(),
            // even when the domain event carries its historical created-at
            // metadata. Keep audit search and the projection on the approval
            // timeline as well.
            DB::table('stored_events')
                ->where('aggregate_uuid', $taskId)
                ->where('aggregate_version', 1)
                ->update(['created_at' => $occurredAt]);
            DB::table('backoffice_tasks')->where('id', $taskId)->update([
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            $created++;
        });

        return $created;
    }

    /** @return Builder<AttendanceMonth> */
    private function missingMonths(): Builder
    {
        return AttendanceMonth::query()
            ->where('status', 'approved')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('backoffice_tasks')
                    ->whereColumn('backoffice_tasks.source_id', 'attendance_months.id')
                    ->where('backoffice_tasks.source_type', 'attendance_month');
            });
    }
}
