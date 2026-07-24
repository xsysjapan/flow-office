<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Commands\EditAttendanceDay;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceBreak;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * UC-A005: 日次勤怠を編集する。締め後(ロック後)、および承認済み・締め済みの月次に
 * 属する日次勤怠は修正申請ワークフローを使う(AttendanceEditGuard参照。UC-A015)。
 *
 * 入力される日時はオフセット付きISO8601を前提に、タイムゾーン変換をせず「入力された通りの
 * 現地時刻」として保存する。海外出張などで勤務日ごとに現地時刻(オフセット)が変わることを
 * 想定し、そのオフセットを attendance_days.utc_offset_minutes に記録する
 * (docs/03-architecture.md 3.4)。1回の編集で送られる日時は全て同じオフセットである
 * 必要がある(1勤務日に複数のオフセットは持たせない)。
 *
 * @implements CommandHandler<EditAttendanceDay>
 */
class EditAttendanceDayHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof EditAttendanceDay);

        $day = AttendanceDay::query()->findOrFail($command->attendanceDayId);

        $this->guard->assertMutable($day, $day->user_id, $day->work_date->toDateString());

        $offsetMinutes = $this->resolveOffsetMinutes($command, $day->utc_offset_minutes);

        $actualStartAt = $command->actualStartAt !== null ? LocalDateTime::splitOffset($command->actualStartAt)[0] : null;
        $actualEndAt = $command->actualEndAt !== null ? LocalDateTime::splitOffset($command->actualEndAt)[0] : null;
        $status = $command->actualEndAt !== null ? AttendanceDayStatus::CLOCKED_OUT : $day->status;

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

        AttendanceDayAggregate::retrieve($day->id)
            ->edit(
                utcOffsetMinutes: $offsetMinutes,
                actualStartAt: $command->actualStartAt !== null ? LocalDateTime::formatWithOffsetMinutes($actualStartAt, $offsetMinutes) : null,
                actualEndAt: $command->actualEndAt !== null ? LocalDateTime::formatWithOffsetMinutes($actualEndAt, $offsetMinutes) : null,
                status: $status,
                workType: $command->workType,
                workLocationType: $command->workLocationType,
                workLocationTypeProvided: $command->workLocationTypeProvided,
                note: $command->note,
                breaks: $breaksPayload,
                leaveSegments: $leaveSegmentsPayload,
                reason: $command->reason,
                editedByUserId: $command->editedByUserId,
            )
            ->persist();

        $day = AttendanceDay::query()->findOrFail($command->attendanceDayId);

        $calculation = $this->calculator->calculate($day->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'));

        AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();

        return AttendanceDay::query()->findOrFail($command->attendanceDayId);
    }

    /**
     * 今回の編集で送られた日時(actual_start_at / actual_end_at / breaks[].start / breaks[].end /
     * leaveSegments[].start / leaveSegments[].end)のオフセットが全て一致することを検証し、
     * その値を返す。1件も送られなかった場合は既存のオフセットを維持する。
     */
    private function resolveOffsetMinutes(EditAttendanceDay $command, int $existingOffsetMinutes): int
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

        return $resolved ?? $existingOffsetMinutes;
    }

    /**
     * 遅刻・早退等を欠勤時間として扱う区間(有給休暇を除く)を全件入れ替える
     * (attendance_breaksと同じ扱い)。区間同士、および休憩との重複は、同じ時間帯が
     * 二重に労働時間から控除されたり欠勤時間が過大集計されたりするのを防ぐため許可しない。
     *
     * @param  array<int, array{start: string, end: string, note: string|null}>  $leaveSegments
     * @param  array<int, AttendanceBreak>  $parsedBreaks
     * @return array{0: array<int, array{start: string, end: string, note: string|null}>}
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

        return [$payload];
    }

    private function intervalsOverlap(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): bool
    {
        return $aStart->lessThan($bEnd) && $bStart->lessThan($aEnd);
    }
}
