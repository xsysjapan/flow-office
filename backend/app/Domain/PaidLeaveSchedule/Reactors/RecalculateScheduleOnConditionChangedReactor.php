<?php

namespace App\Domain\PaidLeaveSchedule\Reactors;

use App\Domain\Attendance\Events\UserWorkStyleAssignedForMonth;
use App\Domain\Attendance\Events\WorkStyleUpdated;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\RecalculateFutureSchedule;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Domain\UserManagement\Events\UserHireDateSet;
use App\Domain\UserManagement\Events\UserUsageStartDateSet;
use App\Models\User;
use App\Models\UserWorkStyleMonthlyAssignment;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * spec.md 実装対象Phase C・論点7: `hire_date`/`usage_start_date`/`work_style_id`割当/
 * `GrantCategoryClassifier`判定に影響する`WorkStyle`列変更を検知し、対象社員の
 * `RecalculateFutureSchedule`を発行する。
 *
 * 過去確定(`Granted`/`Cancelled`)エントリと個別修正(`is_manually_overridden`)エントリの
 * 除外は`PaidLeaveScheduleAggregate::recalculateFutureSchedule()`が内部で保証するため
 * (Phase Aで実装済み)、このReactorは「最新条件で算出した候補一式を渡す」ことだけに責務を
 * 限定する。
 *
 * 独自ルール(`paid_leave_grant_rules`)は現状イベントソーシング化されていない
 * (プレーンなEloquentモデルとしてPhase D管理APIから直接CRUDされる)ため、その変更を
 * 検知するトリガーは本Reactorでは扱わない(Phase D管理API実装時にコントローラ側で
 * `RecalculateFutureSchedule`を発行する方式を別途検討する。spec.md論点7のA案のうち
 * 「独自ルール変更」の部分は、本変更セットのスコープ外であるPhase D実装まわりの
 * 対応が必要な既知のギャップとして残す)。
 */
final class RecalculateScheduleOnConditionChangedReactor extends Reactor
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly ScheduleCandidateGenerator $generator,
    ) {}

    public function onUserHireDateSet(UserHireDateSet $event): void
    {
        $this->recalculateForUser($event->aggregateRootUuid(), 'hire_date変更');
    }

    public function onUserUsageStartDateSet(UserUsageStartDateSet $event): void
    {
        $this->recalculateForUser($event->aggregateRootUuid(), 'usage_start_date変更');
    }

    public function onUserWorkStyleAssignedForMonth(UserWorkStyleAssignedForMonth $event): void
    {
        $this->recalculateForUser($event->userId, 'work_style_id割当変更');
    }

    public function onWorkStyleUpdated(WorkStyleUpdated $event): void
    {
        $workStyleId = $event->aggregateRootUuid();

        $userIds = UserWorkStyleMonthlyAssignment::query()
            ->where('work_style_id', $workStyleId)
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $this->recalculateForUser($userId, 'WorkStyle設定変更');
        }
    }

    private function recalculateForUser(string $userId, string $reason): void
    {
        $user = User::query()->find($userId);

        if ($user === null || $user->hire_date === null) {
            return;
        }

        $from = Carbon::today();
        $to = $from->copy()->addYear();

        $candidates = $this->generator->candidatesFor($user, $from, $to);

        $this->commandBus->dispatch(new RecalculateFutureSchedule(
            userId: $user->id,
            candidates: $candidates,
            reason: $reason,
        ));
    }
}
