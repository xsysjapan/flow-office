<?php

namespace App\Domain\CompensatoryLeave\Handlers;

use App\Domain\CompensatoryLeave\Aggregates\CompensatoryLeaveRequestAggregate;
use App\Domain\CompensatoryLeave\Commands\ResubmitCompensatoryLeaveRequest;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;

/**
 * 差戻された代休申請を同じ内容のまま再提出する(論点8。設計書docs/09 再提出時は新規の消化記録)。
 * 申請中に戻し、新しい消化記録の作成(残数)と勤怠への反映(勤怠)は`compensatory_leave.request_resubmitted`を受ける各Reactorが行う。
 *
 * viaReactor=true(ワークフローの提出からのReactor)で、差戻し中でなければ何もしない(冪等)。
 *
 * @implements CommandHandler<ResubmitCompensatoryLeaveRequest>
 */
class ResubmitCompensatoryLeaveRequestHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof ResubmitCompensatoryLeaveRequest);

        $aggregate = CompensatoryLeaveRequestAggregate::retrieve($command->compensatoryLeaveRequestId);

        // viaReactor=true は差戻し中の申請だけを再申請する。初回の提出(申請中)・承認済み・取消済みは何もしない(冪等)。
        if ($command->viaReactor && ! $aggregate->isReturned()) {
            return null;
        }

        $aggregate->resubmit($command->resubmittedByUserId)->persist();

        return null;
    }
}
