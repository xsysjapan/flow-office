<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\CreateAttendanceDay;
use App\Domain\Attendance\Commands\EditAttendanceDay;
use App\Domain\Attendance\Commands\GeneratePatternAttendanceDays;
use App\Domain\Attendance\Services\WeeklyPatternResolver;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * 週次・月次の一括入力(曜日ごとの実際の出退勤時刻・休憩時刻 + 日単位の上書き)を、
 * 指定期間の実績(attendance_days)へ展開する。
 *
 * 日次の検証・締めガード・再計算ロジック(オフセット統一チェック、休憩/欠勤区間の重複
 * チェック、AttendanceCalculatorによる再計算)は一切複製せず、日ごとに既存の
 * `CreateAttendanceDay`/`EditAttendanceDay`をCommandBus経由でそのまま呼び出す
 * (設計原則9: 入口ごとの計算ロジック複製禁止)。1日分のdispatchが
 * `DomainRuleException`(締め後・有給区間重複等)を投げても、その日はネストされた
 * トランザクション(SAVEPOINT)がロールバックされるだけで、この呼び出し元では
 * その日を`rejected`として記録しループを継続する(バッチ全体を失敗させない)。
 *
 * @implements CommandHandler<GeneratePatternAttendanceDays>
 */
class GeneratePatternAttendanceDaysHandler implements CommandHandler
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    /**
     * @return array{results: list<array{date: string, status: string, message: ?string}>, created_count: int, updated_count: int, skipped_count: int, rejected_count: int}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof GeneratePatternAttendanceDays);

        $resolver = new WeeklyPatternResolver($command->weeklyPattern, $command->dayOverrides);
        $offsetMinutes = $this->parseOffsetMinutes($command->utcOffset);

        $period = Carbon::parse($command->from)->toPeriod(Carbon::parse($command->to));
        $results = [];
        $counts = ['created' => 0, 'updated' => 0, 'skipped_existing' => 0, 'rejected' => 0];

        foreach ($period as $date) {
            $resolved = $resolver->resolve($date);
            $value = $resolved['value'] ?? null;

            if ($value === null) {
                continue;
            }

            $existing = AttendanceDay::query()
                ->where('user_id', $command->userId)
                ->whereDate('work_date', $date->toDateString())
                ->first();

            if ($existing !== null && $command->overwriteMode === GeneratePatternAttendanceDays::OVERWRITE_MODE_SKIP_EXISTING) {
                $results[] = ['date' => $date->toDateString(), 'status' => 'skipped_existing', 'message' => null];
                $counts['skipped_existing']++;

                continue;
            }

            $actualStartAt = LocalDateTime::formatWithOffsetMinutes($date->copy()->setTimeFromTimeString($value['start_time']), $offsetMinutes);
            $endAt = $date->copy()->setTimeFromTimeString($value['end_time']);
            $startAt = $date->copy()->setTimeFromTimeString($value['start_time']);
            if ($endAt->lessThanOrEqualTo($startAt)) {
                $endAt->addDay();
            }
            $actualEndAt = LocalDateTime::formatWithOffsetMinutes($endAt, $offsetMinutes);

            $breaks = [];
            if (! empty($value['break_start_time']) && ! empty($value['break_end_time'])) {
                $breakStartAt = $date->copy()->setTimeFromTimeString($value['break_start_time']);
                $breakEndAt = $date->copy()->setTimeFromTimeString($value['break_end_time']);
                if ($breakEndAt->lessThanOrEqualTo($breakStartAt)) {
                    $breakEndAt->addDay();
                }
                $breaks[] = [
                    'start' => LocalDateTime::formatWithOffsetMinutes($breakStartAt, $offsetMinutes),
                    'end' => LocalDateTime::formatWithOffsetMinutes($breakEndAt, $offsetMinutes),
                ];
            }

            try {
                if ($existing === null) {
                    $this->commandBus->dispatch(new CreateAttendanceDay(
                        userId: $command->userId,
                        workDate: $date->toDateString(),
                        actualStartAt: $actualStartAt,
                        actualEndAt: $actualEndAt,
                        breaks: $breaks,
                        workType: null,
                        note: null,
                        leaveSegments: [],
                        reason: $command->reason,
                        createdByUserId: $command->actingUserId,
                    ));
                    $results[] = ['date' => $date->toDateString(), 'status' => 'created', 'message' => null];
                    $counts['created']++;
                } else {
                    $this->commandBus->dispatch(new EditAttendanceDay(
                        attendanceDayId: $existing->id,
                        actualStartAt: $actualStartAt,
                        actualEndAt: $actualEndAt,
                        breaks: $breaks,
                        workType: null,
                        note: null,
                        leaveSegments: [],
                        reason: $command->reason,
                        editedByUserId: $command->actingUserId,
                    ));
                    $results[] = ['date' => $date->toDateString(), 'status' => 'updated', 'message' => null];
                    $counts['updated']++;
                }
            } catch (DomainRuleException $exception) {
                $results[] = ['date' => $date->toDateString(), 'status' => 'rejected', 'message' => $exception->getMessage()];
                $counts['rejected']++;
            }
        }

        return [
            'results' => $results,
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'skipped_count' => $counts['skipped_existing'],
            'rejected_count' => $counts['rejected'],
        ];
    }

    /**
     * "+09:00" 形式のオフセット文字列をUTCオフセット(分)に変換する。
     */
    private function parseOffsetMinutes(string $offset): int
    {
        $sign = $offset[0] === '-' ? -1 : 1;
        [$hours, $minutes] = array_map('intval', explode(':', substr($offset, 1)));

        return $sign * ($hours * 60 + $minutes);
    }
}
