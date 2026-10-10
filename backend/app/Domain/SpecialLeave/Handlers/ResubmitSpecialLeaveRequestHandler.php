<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\ResubmitSpecialLeaveRequest;
use App\Models\SpecialLeaveRequest;

/**
 * 差戻された特別休暇申請を同じ内容のまま再提出する(論点8。設計書docs/09 再提出時は新規の消化記録)。
 * 申請中に戻し、新しい消化記録の作成(残数)と勤怠への反映(勤怠)は`special_leave.request_resubmitted`を受ける各Reactorが行う。
 *
 * viaReactor=true(ワークフローの提出からのReactor)で、既に申請中(最初の提出)なら何もしない(冪等)。
 *
 * @implements CommandHandler<ResubmitSpecialLeaveRequest>
 */
class ResubmitSpecialLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof ResubmitSpecialLeaveRequest);

        $aggregate = SpecialLeaveRequestAggregate::retrieve($command->specialLeaveRequestId);

        if ($command->viaReactor && $aggregate->isSubmitted()) {
            return SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);
        }

        $aggregate->resubmit($command->resubmittedByUserId)->persist();

        return SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);
    }
}
