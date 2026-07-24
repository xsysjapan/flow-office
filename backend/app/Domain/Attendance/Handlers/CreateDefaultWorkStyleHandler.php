<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\WorkStyleAggregate;
use App\Domain\Attendance\Commands\CreateDefaultWorkStyle;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\SystemSetting;
use App\Models\WorkStyle;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 指示書 3.1節・12.1節: 初回オンボーディングで「通常勤務」(月〜金9:00-18:00、休憩12:00-13:00、
 * 土日祝休み相当の毎週法定休日ルール)を明示的なデフォルト働き方として作成する。
 * 「未設定時に暗黙的に09:00-18:00を適用する」フォールバックとは異なり、実在する
 * work_styles の1行として作成し、一覧・監査ログに現れるようにする(指示書 2.2節・24節)。
 *
 * 会社のデフォルトは常に1件のため、既にデフォルトが存在する場合は作成しない
 * (デフォルトの切り替えは SetDefaultWorkStyle を使う)。
 *
 * @implements CommandHandler<CreateDefaultWorkStyle>
 */
class CreateDefaultWorkStyleHandler implements CommandHandler
{
    private const PROTECTED_KEYS = ['is_default', 'system_generated', 'code'];

    public function handle(Command $command): WorkStyle
    {
        assert($command instanceof CreateDefaultWorkStyle);

        if (WorkStyle::query()->where('is_default', true)->exists()) {
            throw ValidationException::withMessages(['default' => '既にデフォルト働き方が設定されています。']);
        }

        $attributes = array_merge(
            $this->standardAttributes(),
            array_diff_key($command->overrides, array_flip(self::PROTECTED_KEYS)),
        );
        $attributes['code'] = $this->uniqueCode('standard');
        $attributes['is_default'] = true;
        $attributes['system_generated'] = true;

        $id = (string) Str::uuid();

        WorkStyleAggregate::retrieve($id)
            ->create($attributes, $command->createdByUserId)
            ->persist();

        SystemSetting::current()->update(['default_work_style_id' => $id]);

        WorkStyleAggregate::retrieve($id)
            ->changeDefault(null, $command->createdByUserId)
            ->persist();

        return WorkStyle::query()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function standardAttributes(): array
    {
        return [
            'name' => '通常勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00',
            'default_end_time' => '18:00',
            'default_break_minutes' => 60,
            'default_break_start_time' => '12:00',
            'default_break_end_time' => '13:00',
            'calendar_id' => null,
            'is_shift_based' => false,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ];
    }

    private function uniqueCode(string $base): string
    {
        $code = $base;
        $suffix = 2;

        while (WorkStyle::query()->where('code', $code)->exists()) {
            $code = "{$base}-{$suffix}";
            $suffix++;
        }

        return $code;
    }
}
