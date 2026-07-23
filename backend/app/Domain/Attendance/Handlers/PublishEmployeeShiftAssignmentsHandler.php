<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\EmployeeShiftAssignmentAggregate;
use App\Domain\Attendance\Commands\PublishEmployeeShiftAssignments;
use App\Domain\Attendance\Services\ShiftScheduleReviewService;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Jobs\SendNotificationJob;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * UC-C004 手順6: 3交代制シフト表を公開する。下書き中(is_published=false)の
 * シフトパターン割当を対象社員へ公開し、メール通知する。
 * 手順5の警告(法定休日不足・連続勤務・月間予定時間)は公開をブロックしない。
 *
 * @implements CommandHandler<PublishEmployeeShiftAssignments>
 */
class PublishEmployeeShiftAssignmentsHandler implements CommandHandler
{
    public function __construct(
        private readonly ShiftScheduleReviewService $reviewService,
    ) {}

    /**
     * @return array{published_count: int, warnings: array<string, mixed>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof PublishEmployeeShiftAssignments);

        $monthStart = Carbon::createFromFormat('Y-m', $command->yearMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $warnings = $this->reviewService->review($command->userIds, $command->yearMonth);

        $assignments = EmployeeShiftAssignment::query()
            ->whereIn('user_id', $command->userIds)
            ->whereDate('work_date', '>=', $monthStart->toDateString())
            ->whereDate('work_date', '<=', $monthEnd->toDateString())
            ->where('is_published', false)
            ->get();

        foreach ($assignments as $assignment) {
            EmployeeShiftAssignmentAggregate::retrieve($assignment->id)
                ->publish(
                    userId: $assignment->user_id,
                    workDate: $assignment->work_date->toDateString(),
                    publishedByUserId: $command->publishedByUserId,
                )
                ->persist();
        }

        foreach ($assignments->groupBy('user_id') as $userId => $userAssignments) {
            $recipient = User::find($userId);
            if ($recipient === null) {
                continue;
            }

            SendNotificationJob::enqueue(
                recipient: $recipient,
                title: 'シフト表公開',
                summary: "{$command->yearMonth}のシフト表が公開されました({$userAssignments->count()}日分)。",
                detailUrl: null,
            );
        }

        return [
            'published_count' => $assignments->count(),
            'warnings' => $warnings,
        ];
    }
}
