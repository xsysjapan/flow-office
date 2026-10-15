<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Domain\Attendance\Commands\RecalculateAttendanceDailyCalculation;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\CommandBus;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * データ補正・集計ロジック変更後の一括再計算: 指定期間の勤怠日について、現在の日次計算ロジックの結果を
 * 計算し、保存済みの日次計算(attendance_daily_calculations)と比べて変わる値を一覧する。
 *
 * - 既定は試し実行(何も記録しない)。--apply を付けたときだけ、変わる日について
 *   RecalculateAttendanceDailyCalculation を発行し、attendance_day.calculated を記録する。
 * - 手動調整済み(is_manually_adjusted)の日は対象外とし、除外した日を一覧に出す。
 * - 締め・提出済みの日も対象に含める(日次計算のみ更新し、提出時の月次スナップショットは変えない)。
 *   締め・提出済みの日は一覧に印を付ける。
 * - 変化のない日は何もしない(再実行しても結果は同じ)。
 */
#[AdminExecutable(
    label: '日次勤怠の一括再計算',
    rules: [
        'from' => ['required', 'date_format:Y-m-d'],
        'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        'user' => ['nullable', 'array'],
        'user.*' => ['string'],
        'apply' => ['nullable', 'boolean'],
    ],
    ui: [
        'from' => ['control' => 'text'],
        'to' => ['control' => 'text'],
        'user' => ['control' => 'text'],
        'apply' => ['control' => 'checkbox'],
    ],
)]
class RecalculateAttendanceDaysCommand extends Command
{
    protected $signature = 'attendance:recalculate-days
        {--from= : 対象期間の開始日(YYYY-MM-DD。必須)}
        {--to= : 対象期間の終了日(YYYY-MM-DD。必須)}
        {--user=* : 対象の社員ID(複数指定可)。省略または * で全社員}
        {--apply : 変わる日の日次計算を実際に記録する(指定しなければ試し実行)}';

    protected $description = '指定期間の勤怠日の日次計算を現在のロジックで再計算し、変わる値を一覧する(既定は試し実行)';

    public function handle(
        AttendanceCalculator $calculator,
        AttendanceEditGuard $guard,
        CommandBus $commandBus,
    ): int {
        if ($this->option('from') === null || $this->option('to') === null) {
            $this->error('--from と --to は必須です(YYYY-MM-DD)。');

            return self::FAILURE;
        }

        $from = $this->parseDate($this->option('from'));
        $to = $this->parseDate($this->option('to'));
        if ($from === null || $to === null) {
            $this->error('--from と --to は YYYY-MM-DD 形式の実在する日付で指定してください。');

            return self::FAILURE;
        }

        if ($from > $to) {
            $this->error('--from は --to 以前の日付を指定してください。');

            return self::FAILURE;
        }

        $userIds = array_values(array_filter(
            (array) $this->option('user'),
            fn ($value): bool => $value !== '',
        ));
        $allUsers = $userIds === [] || in_array('*', $userIds, true);
        $apply = (bool) $this->option('apply');

        $days = AttendanceDay::query()
            ->with(['breaks', 'leaveSegments', 'calendarEntry.workStyle'])
            ->when(! $allUsers, fn ($query) => $query->whereIn('user_id', $userIds))
            ->whereBetween('work_date', [$from, $to])
            ->orderBy('user_id')
            ->orderBy('work_date')
            ->get();

        $targetCount = 0;
        $changedCount = 0;
        $excludedCount = 0;

        foreach ($days as $day) {
            $targetCount++;
            $label = "{$day->user_id} / {$day->work_date->toDateString()} (attendance_days.id={$day->id})";

            $stored = AttendanceDailyCalculation::query()->where('attendance_day_id', $day->id)->first();

            if ($stored !== null && $stored->is_manually_adjusted) {
                $excludedCount++;
                $this->line("[除外] {$label}: 手動調整済みのため対象外");

                continue;
            }

            $calculated = $calculator->calculate($day);
            $changes = $this->changedValues($stored, $calculated);

            if ($changes === []) {
                continue;
            }

            $changedCount++;
            $locked = ! $guard->isMutable($day, $day->user_id, $day->work_date->toDateString());
            $mark = $locked ? '（締め・提出済み）' : '';
            $this->line("[変更] {$label}{$mark}: ".implode(', ', $changes));

            if ($apply) {
                $commandBus->dispatch(new RecalculateAttendanceDailyCalculation($day->id));
            }
        }

        $this->info("対象 {$targetCount} 件、変更あり {$changedCount} 件、手動調整済みで除外 {$excludedCount} 件。");
        if ($apply) {
            $this->info("{$changedCount} 件の日次計算を記録しました。");
        } else {
            $this->comment('試し実行のため記録していません。反映するには --apply を付けて実行してください。');
        }

        return self::SUCCESS;
    }

    /** YYYY-MM-DD形式の実在する日付なら、その文字列を返す。それ以外はnull。 */
    private function parseDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $parsed = Carbon::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * 保存済みの日次計算と比べて値が変わる項目を "項目: 旧 → 新" の一覧にする。保存済みが無い日は全項目を対象にする。
     *
     * @param  array<string, int|bool|float|null>  $calculated
     * @return list<string>
     */
    private function changedValues(?AttendanceDailyCalculation $stored, array $calculated): array
    {
        $changes = [];
        $columns = (new AttendanceDailyCalculation)->getFillable();

        foreach ($calculated as $key => $value) {
            if (! in_array($key, $columns, true)) {
                continue;
            }

            $old = $stored?->getAttribute($key);
            if ($stored !== null && $this->sameValue($old, $value)) {
                continue;
            }

            $changes[] = $key.': '.$this->display($old).' → '.$this->display($value);
        }

        return $changes;
    }

    private function sameValue(mixed $old, mixed $new): bool
    {
        if ($old === null || $new === null) {
            return $old === $new;
        }

        if (is_bool($new) || is_bool($old)) {
            return (bool) $old === (bool) $new;
        }

        if (is_numeric($old) && is_numeric($new)) {
            return abs((float) $old - (float) $new) < 1e-9;
        }

        return (string) $old === (string) $new;
    }

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
