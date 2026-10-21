<?php

namespace App\Domain\PaidLeaveSchedule\Reactors;

use App\Domain\Attendance\Events\UserWorkStyleAssignedForMonth;
use App\Domain\Attendance\Events\WorkStyleUpdated;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\RecalculateFutureSchedule;
use App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * spec.md論点7: `work_style_id`割当変更、および`GrantCategoryClassifier`の区分判定に
 * 影響する`WorkStyle`列(`weekly_scheduled_days`/`annual_scheduled_days`/
 * `is_shift_based`/`agreed_scheduled_days_per_year`/`prescribed_weekly_minutes`)の
 * 変更を検知し、対象社員の未来Scheduleを再計算する。
 *
 * `UserWorkStyleAssignedForMonth`(AggregateId=assignment id)はペイロードに`userId`を
 * 直接持つため、対象社員1名を特定できる。`WorkStyleUpdated`(AggregateId=work_style_id)は
 * `userId`を持たないため、`ScheduleCandidateGenerator::userIdsCurrentlyOn`で「今(当月以前で最新の割当が)
 * この`WorkStyle`の社員」を引いて再計算対象とする(付与予定の計算と同じ定義。過去月に1回だけ割り当てて
 * 今も有効な社員を含み、当月より後にだけ割当がある社員は含まない)。
 */
class RecalculateScheduleOnWorkStyleChangedReactor extends Reactor
{
    /**
     * `GrantCategoryClassifier`の判定に使われる列のみを対象にする。それ以外の属性変更
     * (name/code/default_start_time等)ではSchedule再計算をトリガーしない。
     *
     * @var array<int, string>
     */
    private const CLASSIFICATION_RELEVANT_ATTRIBUTES = [
        'weekly_scheduled_days',
        'annual_scheduled_days',
        'is_shift_based',
        'agreed_scheduled_days_per_year',
        'prescribed_weekly_minutes',
    ];

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly ScheduleCandidateGenerator $generator,
    ) {}

    public function onUserWorkStyleAssignedForMonth(UserWorkStyleAssignedForMonth $event): void
    {
        $this->recalculate($event->userId, 'work_style_id割当の変更');
    }

    public function onWorkStyleUpdated(WorkStyleUpdated $event): void
    {
        $changedRelevantAttribute = collect(self::CLASSIFICATION_RELEVANT_ATTRIBUTES)
            ->contains(fn (string $attribute) => array_key_exists($attribute, $event->attributes));

        if (! $changedRelevantAttribute) {
            return;
        }

        $userIds = $this->generator->userIdsCurrentlyOn($event->aggregateRootUuid(), Carbon::today());

        foreach ($userIds as $userId) {
            $this->recalculate($userId, 'WorkStyleの区分判定関連列の変更');
        }
    }

    private function recalculate(string $userId, string $reason): void
    {
        $user = User::find($userId);

        if ($user === null) {
            return;
        }

        $from = Carbon::today();
        $candidates = $this->generator->candidatesFor($user, $from, $from->copy()->addYear());

        $this->commandBus->dispatch(new RecalculateFutureSchedule(
            userId: $userId,
            candidates: $candidates,
            reason: $reason,
            fromDate: $from->toDateString(),
        ));
    }
}
