<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Commands\CreateAttendanceDay;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceBreak;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\SystemSetting;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 出勤日(attendance_days)を任意の勤務日に新規作成する。打刻(attendance_punches)とは
 * 勤務日が同じというだけの緩い関係しかなく、打刻の有無にかかわらず作成できる。その月が
 * 編集不可(承認済み・締め済み)になるまでは、いつでも作成できる(AttendanceEditGuard参照)。
 *
 * @implements CommandHandler<CreateAttendanceDay>
 */
class CreateAttendanceDayHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof CreateAttendanceDay);

        $exists = AttendanceDay::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->workDate)
            ->exists();

        if ($exists) {
            throw new DomainRuleException('指定した勤務日の出勤日は既に存在します。日次編集を使用してください。');
        }

        $this->guard->assertMutable(null, $command->userId, $command->workDate);

        $offsetMinutes = $this->resolveOffsetMinutes($command);

        $shiftAssignment = EmployeeShiftAssignment::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->workDate)
            ->first();

        $breaksPayload = [];
        $parsedBreaks = [];
        foreach ($command->breaks as $break) {
            $start = LocalDateTime::splitOffset($break['start'])[0];
            $end = $break['end'] !== null ? LocalDateTime::splitOffset($break['end'])[0] : null;
            $parsedBreaks[] = new AttendanceBreak(['break_start_at' => $start, 'break_end_at' => $end]);
            $breaksPayload[] = [
                'start' => LocalDateTime::formatWithOffsetMinutes($start, $offsetMinutes),
                'end' => $end !== null ? LocalDateTime::formatWithOffsetMinutes($end, $offsetMinutes) : null,
            ];
        }

        [$leaveSegmentsPayload] = $this->buildLeaveSegments($command->leaveSegments, $parsedBreaks, $offsetMinutes);

        $dayId = (string) Str::uuid();
        $status = $command->actualEndAt !== null ? AttendanceDayStatus::CLOCKED_OUT : AttendanceDayStatus::NOT_STARTED;
        $actualStartAt = $command->actualStartAt !== null ? LocalDateTime::splitOffset($command->actualStartAt)[0] : null;
        $actualEndAt = $command->actualEndAt !== null ? LocalDateTime::splitOffset($command->actualEndAt)[0] : null;

        AttendanceDayAggregate::retrieve($dayId)
            ->create(
                userId: $command->userId,
                workDate: $command->workDate,
                shiftAssignmentId: $shiftAssignment?->id,
                status: $status,
                source: AttendanceDaySource::MANUAL,
                utcOffsetMinutes: $offsetMinutes,
                actualStartAt: $command->actualStartAt !== null ? LocalDateTime::formatWithOffsetMinutes($actualStartAt, $offsetMinutes) : null,
                actualEndAt: $command->actualEndAt !== null ? LocalDateTime::formatWithOffsetMinutes($actualEndAt, $offsetMinutes) : null,
                workType: $command->workType,
                workLocationType: $command->workLocationType,
                note: $command->note,
                breaks: $breaksPayload,
                leaveSegments: $leaveSegmentsPayload,
                reason: $command->reason,
                createdByUserId: $command->createdByUserId,
            )
            ->persist();

        $day = AttendanceDay::query()->findOrFail($dayId);

        // 計算(AttendanceCalculator)は永続化後の実データから読み直す(通常のDBに保存された
        // Projectionを使う。これはCreate/Edit時のみの割り切りで、パフォーマンス上問題ない範囲)。
        $calculation = $this->calculator->calculate($day->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'));

        AttendanceDayAggregate::retrieve($dayId)->calculate($calculation)->persist();

        return AttendanceDay::query()->findOrFail($dayId);
    }

    /**
     * 今回の作成で送られた日時(actual_start_at / actual_end_at / breaks[].start / breaks[].end)
     * のオフセットが全て一致することを検証し、その値を返す。1件も送られなかった場合は
     * システムのデフォルトタイムゾーン(`system_settings.default_timezone`)のオフセットを使う
     * (裁量労働制など、打刻を伴わない出勤日の作成を想定。docs/03-architecture.md 3.4)。
     */
    private function resolveOffsetMinutes(CreateAttendanceDay $command): int
    {
        $inputs = array_filter([
            $command->actualStartAt,
            $command->actualEndAt,
            ...array_column($command->breaks, 'start'),
            ...array_column($command->breaks, 'end'),
            ...array_column($command->leaveSegments, 'start'),
            ...array_column($command->leaveSegments, 'end'),
        ], fn (?string $value) => $value !== null);

        $resolved = null;
        foreach ($inputs as $input) {
            [, $offsetMinutes] = LocalDateTime::splitOffset($input);
            if ($resolved !== null && $resolved !== $offsetMinutes) {
                throw new DomainRuleException('同一勤務日の時刻はすべて同じタイムゾーンオフセットで入力してください。');
            }
            $resolved = $offsetMinutes;
        }

        if ($resolved !== null) {
            return $resolved;
        }

        $defaultTimezone = SystemSetting::current()->default_timezone;

        return intdiv(Carbon::parse($command->workDate, $defaultTimezone)->getOffset(), 60);
    }

    /**
     * 遅刻・早退等を欠勤時間として扱う区間(有給休暇を除く)を作成する。区間同士、
     * および休憩との重複は、同じ時間帯が二重に労働時間から控除されたり欠勤時間が
     * 過大集計されたりするのを防ぐため許可しない。
     *
     * @param  array<int, array{start: string, end: string, note: string|null}>  $leaveSegments
     * @param  array<int, AttendanceBreak>  $parsedBreaks
     * @return array{0: array<int, array{start: string, end: string, note: string|null}>, 1: array<int, array{start: Carbon, end: Carbon}>}
     */
    private function buildLeaveSegments(array $leaveSegments, array $parsedBreaks, int $offsetMinutes): array
    {
        $payload = [];
        /** @var array<int, array{start: Carbon, end: Carbon}> $parsed */
        $parsed = [];
        foreach ($leaveSegments as $segment) {
            $start = LocalDateTime::splitOffset($segment['start'])[0];
            $end = LocalDateTime::splitOffset($segment['end'])[0];
            if (! $end->greaterThan($start)) {
                throw new DomainRuleException('遅刻・早退の終了時刻は開始時刻より後にしてください。');
            }

            foreach ($parsed as $existing) {
                if ($this->intervalsOverlap($start, $end, $existing['start'], $existing['end'])) {
                    throw new DomainRuleException('遅刻・早退の時間帯が重複しています。');
                }
            }
            foreach ($parsedBreaks as $break) {
                if ($break->break_end_at !== null && $this->intervalsOverlap($start, $end, $break->break_start_at, $break->break_end_at)) {
                    throw new DomainRuleException('遅刻・早退の時間帯が休憩と重複しています。');
                }
            }
            $parsed[] = ['start' => $start, 'end' => $end];

            $payload[] = [
                'start' => LocalDateTime::formatWithOffsetMinutes($start, $offsetMinutes),
                'end' => LocalDateTime::formatWithOffsetMinutes($end, $offsetMinutes),
                'note' => $segment['note'] ?? null,
            ];
        }

        return [$payload, $parsed];
    }

    private function intervalsOverlap(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): bool
    {
        return $aStart->lessThan($bEnd) && $bStart->lessThan($aEnd);
    }
}
