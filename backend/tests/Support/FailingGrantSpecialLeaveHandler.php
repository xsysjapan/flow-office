<?php

namespace Tests\Support;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Commands\GrantSpecialLeave;
use App\Domain\SpecialLeave\Handlers\GrantSpecialLeaveHandler;
use App\Models\SpecialLeaveGrant;

/**
 * 指定した利用者の付与だけを業務ルール違反で失敗させるテスト用のHandler
 * (特別休暇の付与は業務ルールで失敗する条件が無いため、一括の全部か無しかの確認に使う)。
 * `config(['domain.command_handlers.'.GrantSpecialLeave::class => self::class])`で差し替える。
 */
class FailingGrantSpecialLeaveHandler extends GrantSpecialLeaveHandler
{
    /** @var list<string> */
    public static array $failingUserIds = [];

    public function handle(Command $command): SpecialLeaveGrant
    {
        assert($command instanceof GrantSpecialLeave);

        if (in_array($command->userId, self::$failingUserIds, true)) {
            throw new DomainRuleException('この利用者には付与できません。');
        }

        return parent::handle($command);
    }
}
