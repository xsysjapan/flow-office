<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\WorkStyleAggregate;
use App\Domain\Attendance\Commands\SetDefaultWorkStyle;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\SystemSetting;
use App\Models\WorkStyle;
use Illuminate\Validation\ValidationException;

/**
 * 指示書 3.2節: 会社内で有効なデフォルト働き方は原則1件。新しい働き方をデフォルトに
 * 設定した場合は既存のデフォルトを解除する。system_settings.default_work_style_id
 * (勤怠計算のフォールバック先、docs/16-database-schema.md参照)も同一トランザクションで
 * 同期させる。
 *
 * @implements CommandHandler<SetDefaultWorkStyle>
 */
class SetDefaultWorkStyleHandler implements CommandHandler
{
    public function handle(Command $command): WorkStyle
    {
        assert($command instanceof SetDefaultWorkStyle);

        $workStyle = WorkStyle::query()->find($command->workStyleId);

        if ($workStyle === null) {
            throw ValidationException::withMessages(['work_style_id' => '指定された勤務形態が見つかりません。']);
        }

        if ($workStyle->is_default) {
            return $workStyle;
        }

        $previousDefault = WorkStyle::query()->where('is_default', true)->first();

        WorkStyleAggregate::retrieve($workStyle->id)
            ->changeDefault($previousDefault?->id, $command->changedByUserId)
            ->persist();

        SystemSetting::current()->update(['default_work_style_id' => $workStyle->id]);

        return WorkStyle::query()->findOrFail($workStyle->id);
    }
}
