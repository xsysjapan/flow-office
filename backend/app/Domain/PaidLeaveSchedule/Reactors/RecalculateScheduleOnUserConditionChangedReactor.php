<?php

namespace App\Domain\PaidLeaveSchedule\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\RecalculateFutureSchedule;
use App\Domain\UserManagement\Events\PaidLeaveAutoGrantEnabledSet;
use App\Domain\UserManagement\Events\UserHireDateSet;
use App\Domain\UserManagement\Events\UserUsageStartDateSet;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * spec.md論点7: `hire_date`/`usage_start_date`/`paid_leave_auto_grant_enabled`の変更を
 * 検知し、対象社員の未来Scheduleを再計算する。
 *
 * これら3列は`App\Domain\UserManagement\Aggregates\UserAggregate`
 * (AggregateId=userId)がイベントソース化しており、`$event->aggregateRootUuid()`が
 * そのままuserIdになるため、Reactor側でuserIdを取得できる
 * (`App\Domain\UserManagement\Projectors\UserProjector`と同じ取得方法)。
 *
 * `work_style_id`割当・`WorkStyle`の区分判定関連列・独自ルール
 * (`paid_leave_grant_rules`/steps)の変更トリガーは別Reactor
 * (`RecalculateScheduleOnWorkStyleChangedReactor`)、または本Phaseでは
 * イベントソース化されておらずトリガーを配線できていない
 * (実装結果に明記。`paid_leave_grant_rules`はPaidLeaveController::storeRule()等で
 * 素朴なEloquent CRUDのまま)。
 */
class RecalculateScheduleOnUserConditionChangedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onUserHireDateSet(UserHireDateSet $event): void
    {
        $this->recalculate($event->aggregateRootUuid(), 'hire_dateの変更');
    }

    public function onUserUsageStartDateSet(UserUsageStartDateSet $event): void
    {
        $this->recalculate($event->aggregateRootUuid(), 'usage_start_dateの変更');
    }

    public function onPaidLeaveAutoGrantEnabledSet(PaidLeaveAutoGrantEnabledSet $event): void
    {
        $this->recalculate($event->aggregateRootUuid(), 'paid_leave_auto_grant_enabledの変更');
    }

    private function recalculate(string $userId, string $reason): void
    {
        $this->commandBus->dispatch(new RecalculateFutureSchedule(userId: $userId, reason: $reason));
    }
}
