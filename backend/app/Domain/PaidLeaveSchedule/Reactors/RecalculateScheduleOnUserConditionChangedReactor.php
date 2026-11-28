<?php

namespace App\Domain\PaidLeaveSchedule\Reactors;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\RecalculateFutureSchedule;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Domain\UserManagement\Events\PaidLeaveAutoGrantEnabledSet;
use App\Domain\UserManagement\Events\UserHireDateSet;
use App\Domain\UserManagement\Events\UserUsageStartDateSet;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
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
    /** このReactorが購読するイベントの短い種別名(config/event-sourcing.phpのevent_class_map) */
    private const SUBSCRIBED_EVENT_CLASSES = [
        'user.hire_date_set',
        'user.usage_start_date_set',
        'user.paid_leave_auto_grant_enabled_set',
    ];

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly ScheduleCandidateGenerator $generator,
    ) {}

    public function onUserHireDateSet(UserHireDateSet $event): void
    {
        $this->recalculate($event, 'hire_dateの変更');
    }

    public function onUserUsageStartDateSet(UserUsageStartDateSet $event): void
    {
        $this->recalculate($event, 'usage_start_dateの変更');
    }

    public function onPaidLeaveAutoGrantEnabledSet(PaidLeaveAutoGrantEnabledSet $event): void
    {
        $this->recalculate($event, 'paid_leave_auto_grant_enabledの変更');
    }

    /**
     * 同じ利用者のストリームに、この Reactor が購読する種類のイベントで、処理中のイベントより版の大きいものが
     * 既に保存されていれば何もしない(1回の persist で複数の条件を変えたとき、最後の1件のときだけ再計算する。
     * そのときは同じ persist の Projector の更新がすべて反映された状態を読める)。
     */
    private function recalculate(UserHireDateSet|UserUsageStartDateSet|PaidLeaveAutoGrantEnabledSet $event, string $reason): void
    {
        $userId = $event->aggregateRootUuid();
        $version = $event->aggregateRootVersion();
        if ($version !== null && $this->hasLaterSubscribedEvent($userId, $version)) {
            return;
        }

        $user = User::find($userId);

        if ($user === null) {
            return;
        }

        $from = BusinessDate::today();
        $candidates = $this->generator->candidatesFor($user, $from, $from->copy()->addYear());

        $this->commandBus->dispatch(new RecalculateFutureSchedule(
            userId: $userId,
            candidates: $candidates,
            reason: $reason,
            fromDate: $from->toDateString(),
        ));
    }

    private function hasLaterSubscribedEvent(string $userId, int $version): bool
    {
        return DB::table('stored_events')
            ->where('aggregate_uuid', $userId)
            ->whereIn('event_class', self::SUBSCRIBED_EVENT_CLASSES)
            ->where('aggregate_version', '>', $version)
            ->exists();
    }
}
