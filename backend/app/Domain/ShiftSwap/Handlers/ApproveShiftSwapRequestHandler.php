<?php

namespace App\Domain\ShiftSwap\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\ShiftSwap\Commands\ApproveShiftSwapRequest;
use App\Domain\ShiftSwap\Services\ShiftSwapRequestApprover;
use App\Models\ShiftSwapRequest;

/**
 * 振替休日申請を承認する。処理本体は承認不要の設定での直接の申請と共通のShiftSwapRequestApproverが行う
 * (承認時に対象日・振替先日それぞれのEmployeeCalendarEntryの休日区分・所定時刻を丸ごと入れ替える)。
 *
 * @implements CommandHandler<ApproveShiftSwapRequest>
 */
class ApproveShiftSwapRequestHandler implements CommandHandler
{
    public function __construct(private readonly ShiftSwapRequestApprover $approver) {}

    public function handle(Command $command): ShiftSwapRequest
    {
        assert($command instanceof ApproveShiftSwapRequest);

        return $this->approver->approve($command->shiftSwapRequestId, $command->approvedByUserId);
    }
}
