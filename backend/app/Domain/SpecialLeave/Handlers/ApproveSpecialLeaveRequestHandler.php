<?php

namespace App\Domain\SpecialLeave\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveGrantAggregate;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Domain\SpecialLeave\Commands\ApproveSpecialLeaveRequest;
use App\Models\AttendanceDay;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveRequestStatus;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 特別休暇を承認する。対象日の勤怠(attendance_days.work_type)への反映は
 * 申請時点(RequestSpecialLeaveHandler)で既に行われているため、承認時に行うのは
 * 失効日が近い付与分(無期限は最後)からの消化(grant消費の確定)と、それに伴う日次集計
 * (special_leave_minutes/special_leave_days)の再計算のみ。残数が不足していても
 * (マイナスになっても)承認自体は成立させる(RequestSpecialLeaveHandler冒頭のコメント参照。
 * 残数は承認済み分のみで計測する方針のため、ここで消化できなかった分は単に記録しない)。
 * ApprovePaidLeaveRequestHandlerと同じ考え方だが、有給側のコードには一切依存しない
 * 独立した実装とする(有給は法定の要件を持つため)。複数集約トランザクション
 * (persistInTransaction)による消費計画の先確定も同様(ApprovePaidLeaveRequestHandler参照)。
 *
 * @implements CommandHandler<ApproveSpecialLeaveRequest>
 */
class ApproveSpecialLeaveRequestHandler implements CommandHandler
{
    public function __construct(private readonly AttendanceCalculator $calculator) {}

    public function handle(Command $command): SpecialLeaveRequest
    {
        assert($command instanceof ApproveSpecialLeaveRequest);

        $request = SpecialLeaveRequest::query()->findOrFail($command->specialLeaveRequestId);

        if ($request->status !== SpecialLeaveRequestStatus::SUBMITTED) {
            throw new DomainRuleException('提出済みの特別休暇申請のみ承認できます。');
        }

        // approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による承認不要の即時確定
        // (SpecialLeaveController::storeRequest参照)であり、承認者チェックそのものを行わない。
        if ($command->approvedByUserId !== null && $request->approver_user_id !== $command->approvedByUserId) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        // 申請時点(RequestSpecialLeaveHandler)で対象日の勤怠は必ず作成済みのため、
        // ここでは参照するのみ(存在しない場合は不整合として例外にする)。
        $day = AttendanceDay::query()
            ->where('user_id', $request->user_id)
            ->whereDate('work_date', $request->target_date)
            ->firstOrFail();

        $plan = $this->planConsumption($request);

        $usedMinutes = $request->hours !== null ? (int) round($request->hours * 60) : null;

        $aggregates = [
            SpecialLeaveRequestAggregate::retrieve($request->id)->approve($command->approvedByUserId),
        ];

        foreach ($plan as ['grant' => $grant, 'amount' => $amount]) {
            $aggregates[] = SpecialLeaveGrantAggregate::retrieve($grant->id)->use(
                userId: $request->user_id,
                specialLeaveRequestId: $request->id,
                attendanceDayId: $day->id,
                usedOn: $request->target_date->toDateString(),
                usedDays: $amount,
                usedMinutes: $usedMinutes,
                usageType: $request->leave_type,
            );
        }

        AggregateRoot::persistInTransaction(...$aggregates);

        if ($plan !== []) {
            $calculation = $this->calculator->calculate(
                $day->refresh()->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'calendarEntry.workStyle'),
            );
            AttendanceDayAggregate::retrieve($day->id)->calculate($calculation)->persist();
        }

        return SpecialLeaveRequest::query()->findOrFail($request->id);
    }

    /**
     * 消化計画を確定する。残数不足でも承認自体はブロックせず、消化できる分だけを
     * 記録する(残数が0のgrantへ紐付けることはできないため、不足分は単に記録しない)。
     * requires_grant=falseの種別(忌引・代休等)はそもそもgrantが存在しないため、
     * 消化計画は自然に空になる。
     *
     * @return array<int, array{grant: SpecialLeaveGrant, amount: float}>
     */
    private function planConsumption(SpecialLeaveRequest $request): array
    {
        $remainingToConsume = (float) $request->requested_days;
        $plan = [];

        $grants = SpecialLeaveGrant::query()
            ->availableOn($request->target_date->toDateString())
            ->where('user_id', $request->user_id)
            ->where('special_leave_type_id', $request->special_leave_type_id)
            ->where('remaining_days', '>', 0)
            // 失効日が近い付与分から優先的に消し込む。無期限(expires_on=null)の付与は最後に消化する。
            ->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->get();

        foreach ($grants as $grant) {
            if ($remainingToConsume <= 0) {
                break;
            }

            $consume = min((float) $grant->remaining_days, $remainingToConsume);
            $plan[] = ['grant' => $grant, 'amount' => $consume];
            $remainingToConsume -= $consume;
        }

        return $plan;
    }
}
