<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest;
use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveUsed;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\PaidLeaveUsage;
use Illuminate\Support\Carbon;

/**
 * UC-P004: 有給を承認する。承認時に (1) 有効期限が近い付与分から消化し、
 * (2) 対象日の勤怠(attendance_days.work_type)に有給区分を反映する。
 *
 * @implements CommandHandler<ApprovePaidLeaveRequest>
 */
class ApprovePaidLeaveRequestHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): PaidLeaveRequest
    {
        assert($command instanceof ApprovePaidLeaveRequest);

        $request = PaidLeaveRequest::query()->findOrFail($command->paidLeaveRequestId);

        if ($request->status !== PaidLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの有給申請のみ承認できます。');
        }

        if ($request->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        $day = $this->reflectOnAttendanceDay($request);

        $request->status = PaidLeaveRequestStatus::APPROVED;
        $request->approved_at = Carbon::now();
        $request->save();

        $this->eventStore->append(
            aggregateType: 'paid_leave_request',
            aggregateId: (string) $request->id,
            event: new PaidLeaveRequestApproved(
                paidLeaveRequestId: $request->id,
                approvedByUserId: $command->approvedByUserId,
            ),
        );

        $this->consumeGrants($request, $day->id);

        $calculation = $this->calculator->calculate($day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'));

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayCalculated(
                attendanceDayId: $day->id,
                calculation: $calculation,
            ),
        );

        return $request;
    }

    private function reflectOnAttendanceDay(PaidLeaveRequest $request): AttendanceDay
    {
        $day = AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->first();

        $this->guard->assertMutable($day, $request->user_id, $request->target_date->toDateString());

        if ($day === null) {
            $shiftAssignment = EmployeeShiftAssignment::query()
                ->where('user_id', $request->user_id)
                ->whereDate('work_date', $request->target_date)
                ->first();

            $day = AttendanceDay::query()->create([
                'user_id' => $request->user_id,
                'work_date' => $request->target_date,
                'shift_assignment_id' => $shiftAssignment?->id,
                'status' => AttendanceDayStatus::NOT_STARTED,
                'source' => AttendanceDaySource::MANUAL,
            ]);
        }

        $day->work_type = PaidLeaveType::toAttendanceWorkType($request->leave_type);
        if ($request->leave_type === PaidLeaveType::FULL) {
            // 全休は出退勤操作が発生しないため、締め忘れとして警告されないよう完了扱いにする。
            $day->status = AttendanceDayStatus::CLOCKED_OUT;
        }
        $day->save();

        return $day;
    }

    private function consumeGrants(PaidLeaveRequest $request, int $attendanceDayId): void
    {
        $remainingToConsume = (float) $request->requested_days;

        $grants = PaidLeaveGrant::query()
            ->where('user_id', $request->user_id)
            ->whereDate('expires_on', '>=', $request->target_date)
            ->where('remaining_days', '>', 0)
            ->orderBy('expires_on')
            ->get();

        foreach ($grants as $grant) {
            if ($remainingToConsume <= 0) {
                break;
            }

            $consume = min((float) $grant->remaining_days, $remainingToConsume);

            $grant->used_days = (float) $grant->used_days + $consume;
            $grant->remaining_days = (float) $grant->remaining_days - $consume;
            $grant->save();

            $usage = PaidLeaveUsage::query()->create([
                'user_id' => $request->user_id,
                'attendance_day_id' => $attendanceDayId,
                'paid_leave_grant_id' => $grant->id,
                'paid_leave_request_id' => $request->id,
                'used_on' => $request->target_date,
                'used_days' => $consume,
                'used_minutes' => $request->hours !== null ? (int) round($request->hours * 60) : null,
                'usage_type' => $request->leave_type,
            ]);

            $this->eventStore->append(
                aggregateType: 'paid_leave_grant',
                aggregateId: (string) $grant->id,
                event: new PaidLeaveUsed(
                    paidLeaveUsageId: $usage->id,
                    userId: $request->user_id,
                    paidLeaveGrantId: $grant->id,
                    paidLeaveRequestId: $request->id,
                    usedOn: $request->target_date->toDateString(),
                    usedDays: $consume,
                ),
            );

            $remainingToConsume -= $consume;
        }

        if ($remainingToConsume > 0) {
            throw new DomainRuleException('有給残数が不足しているため承認できません。');
        }
    }
}
