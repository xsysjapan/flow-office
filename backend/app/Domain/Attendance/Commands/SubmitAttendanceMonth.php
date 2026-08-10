<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A008: 月次勤怠を提出する。
 */
class SubmitAttendanceMonth implements Command
{
    /**
     * @param  ?string  $attendanceMonthId  集約ID(attendance_months.id)。月次勤怠申請の
     *                                      workflow_requestを先に下書き作成する経路では、
     *                                      subject_idとして確定済みのIDを呼び出し元
     *                                      (AttendanceMonthLockOnWorkflowRequestDraftedReactor)
     *                                      が渡す。nullの場合はHandlerが既存行から解決する。
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $yearMonth,
        public readonly string $approverUserId,
        public readonly ?string $attendanceMonthId = null,
        public readonly ?string $workflowRequestId = null,
    ) {}
}
