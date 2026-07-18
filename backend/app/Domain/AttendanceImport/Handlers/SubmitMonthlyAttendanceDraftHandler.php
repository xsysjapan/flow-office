<?php

namespace App\Domain\AttendanceImport\Handlers;

use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\Attendance\Handlers\SubmitAttendanceMonthHandler;
use App\Domain\AttendanceImport\Commands\SubmitMonthlyAttendanceDraft;
use App\Domain\AttendanceImport\Events\MonthlyAttendanceDraftSubmitted;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\FieldProvenance;
use App\Models\MonthlyAttendanceDraft;
use App\Models\MonthlyDraftStatus;

/**
 * UC-R002手順3〜6: ユーザーの明示的な指示によってのみ月次申請する。未解決のエラーや
 * 未確認のAI推定値(重要項目)が残っている場合は拒否する(AI_INFERRED_VALUE_UNCONFIRMED)。
 * 下書きの内容は既存の月次提出フロー(UC-A008、SubmitAttendanceMonthHandler)へそのまま
 * 引き渡し、計算・締め判定ロジックを複製しない(docs/03-architecture.md 3.5)。
 *
 * @implements CommandHandler<SubmitMonthlyAttendanceDraft>
 */
class SubmitMonthlyAttendanceDraftHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly SubmitAttendanceMonthHandler $submitAttendanceMonthHandler,
    ) {}

    public function handle(Command $command): MonthlyAttendanceDraft
    {
        assert($command instanceof SubmitMonthlyAttendanceDraft);

        $draft = MonthlyAttendanceDraft::query()->findOrFail($command->draftId);

        if ($draft->status === MonthlyDraftStatus::SUBMITTED) {
            throw new DomainRuleException('この下書きは既に月次申請済みです。');
        }

        $unconfirmed = FieldProvenance::latestForEntity(FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT, $draft->id)
            ->filter(fn (FieldProvenance $provenance) => $provenance->isImportantAndUnconfirmed());

        if ($unconfirmed->isNotEmpty()) {
            throw new DomainRuleException(
                'AI推定値のうちユーザー未確認の重要項目が残っているため申請できません(AI_INFERRED_VALUE_UNCONFIRMED): '
                .$unconfirmed->pluck('field_name')->implode(', ')
            );
        }

        if ($draft->status !== MonthlyDraftStatus::READY_TO_SUBMIT) {
            throw new DomainRuleException('未解決の問題が残っているため申請できません。先に検証を通過させてください。');
        }

        $attendanceMonth = $this->submitAttendanceMonthHandler->handle(new SubmitAttendanceMonth(
            userId: $draft->user_id,
            yearMonth: $draft->target_month,
            approverUserId: $command->approverUserId,
        ));

        $draft->status = MonthlyDraftStatus::SUBMITTED;
        $draft->submitted_at = now();
        $draft->save();

        $this->eventStore->append(
            aggregateType: 'monthly_attendance_draft',
            aggregateId: (string) $draft->id,
            event: new MonthlyAttendanceDraftSubmitted(
                draftId: $draft->id,
                attendanceMonthId: $attendanceMonth->id,
                submittedByUserId: $command->submittedByUserId,
            ),
        );

        return $draft;
    }
}
