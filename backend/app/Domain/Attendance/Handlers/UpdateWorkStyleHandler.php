<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\WorkStyleAggregate;
use App\Domain\Attendance\Commands\UpdateWorkStyle;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\WorkStyle;
use Illuminate\Validation\ValidationException;

/**
 * 勤務形態(work_styles)の設定内容を変更する。初回オンボーディングで作成された
 * 標準の勤務形態(system_generated=true)であっても、コード・デフォルト指定・
 * システム生成フラグ以外の項目は変更できる(これらはSetDefaultWorkStyle等、
 * 専用のコマンドで扱う)。
 *
 * @implements CommandHandler<UpdateWorkStyle>
 */
class UpdateWorkStyleHandler implements CommandHandler
{
    private const PROTECTED_KEYS = ['id', 'is_default', 'system_generated'];

    public function handle(Command $command): WorkStyle
    {
        assert($command instanceof UpdateWorkStyle);

        $workStyle = WorkStyle::query()->find($command->workStyleId);

        if ($workStyle === null) {
            throw ValidationException::withMessages(['work_style' => '指定された勤務形態が見つかりません。']);
        }

        $attributes = array_diff_key($command->attributes, array_flip(self::PROTECTED_KEYS));

        WorkStyleAggregate::retrieve($workStyle->id)
            ->update($attributes, $command->updatedByUserId)
            ->persist();

        return WorkStyle::query()->findOrFail($workStyle->id);
    }
}
