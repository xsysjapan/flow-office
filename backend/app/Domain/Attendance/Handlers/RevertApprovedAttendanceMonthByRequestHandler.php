<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\RevertApprovedAttendanceMonth;
use App\Domain\Attendance\Commands\RevertApprovedAttendanceMonthByRequest;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;

/**
 * 勤怠確定取消依頼の承認で呼ばれる。対象の月を(user_id, year_month)で探し、状態ごとに扱いを
 * 分ける。approvedのときの確定取消と通知は既存のRevertApprovedAttendanceMonthHandlerに任せる。
 * 例外は同期Reactor経由で承認ごとロールバックされる。
 *
 * @implements CommandHandler<RevertApprovedAttendanceMonthByRequest>
 */
class RevertApprovedAttendanceMonthByRequestHandler implements CommandHandler
{
    public function __construct(private readonly RevertApprovedAttendanceMonthHandler $revertHandler) {}

    public function handle(Command $command): ?AttendanceMonth
    {
        assert($command instanceof RevertApprovedAttendanceMonthByRequest);

        $yearMonth = $command->yearMonth;
        $notFound = new DomainRuleException("対象の月次勤怠({$yearMonth})が見つかりません。");

        if (preg_match('/^\d{4}-\d{2}$/', $yearMonth) !== 1) {
            throw $notFound;
        }

        $month = AttendanceMonth::query()
            ->where('user_id', $command->userId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($month === null) {
            throw $notFound;
        }

        // すでに編集できる状態なので目的は達している(冪等)。
        if (in_array($month->status, [AttendanceMonthStatus::NOT_SUBMITTED, AttendanceMonthStatus::RETURNED], true)) {
            return $month;
        }

        if ($month->status === AttendanceMonthStatus::SUBMITTED) {
            throw new DomainRuleException("対象の月次勤怠({$yearMonth})は承認待ちのため、確定を取り消せません。承認者に差戻しを依頼してください。");
        }

        if ($month->status === AttendanceMonthStatus::CLOSED) {
            throw new DomainRuleException("対象の月次勤怠({$yearMonth})は締め済みです。先に管理者による締め取消が必要です。");
        }

        if ($command->revertedByUserId === null) {
            throw new DomainRuleException('承認者が不明なため、確定を取り消せません。');
        }

        return $this->revertHandler->handle(new RevertApprovedAttendanceMonth(
            attendanceMonthId: $month->id,
            revertedByUserId: $command->revertedByUserId,
            reason: $command->reason,
            workflowRequestId: $command->workflowRequestId,
        ));
    }
}
