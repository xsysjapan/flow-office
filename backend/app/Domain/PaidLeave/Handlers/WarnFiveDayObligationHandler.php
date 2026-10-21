<?php

namespace App\Domain\PaidLeave\Handlers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeave\Commands\WarnFiveDayObligation;
use App\Domain\PaidLeaveAccount\Commands\RaisePaidLeaveGrantWarning;
use App\Jobs\SendNotificationJob;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveUsage;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * UC-P006: 年5日取得義務を警告する。社員×付与日(基準日)の組ごとに、付与日数の合計が10日以上の組を対象とし、
 * 取得義務期間(付与日から1年)の終了が近づいても取得日数(期間内の消化の合計)が5日未満の場合に、組に1件警告する。「承認者」は
 * 有給申請ごとに都度指定されるため固定の宛先を持たず、社員本人へ通知する。
 *
 * @implements CommandHandler<WarnFiveDayObligation>
 */
class WarnFiveDayObligationHandler implements CommandHandler
{
    private const MINIMUM_GRANT_DAYS_FOR_OBLIGATION = 10;

    private const REQUIRED_USED_DAYS = 5;

    private const WARNING_WINDOW_DAYS = 60;

    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return int 警告を発行した件数
     */
    public function handle(Command $command): int
    {
        assert($command instanceof WarnFiveDayObligation);

        // このハンドラは対象をDBレベルの日付条件なしに全件取得し、foreachループの中で
        // 日付判定するため、クエリをタイムゾーングループごとに分ける必要がない。
        // `asOf`指定時(テスト・手動実行)は全員に同じ`$today`を使い、未指定時(cronからの
        // 実運用)のみ、ループ内で各社員の`users.timezone`(NULLはsystem_settings.
        // default_timezoneにフォールバック)を使ってその都度「今日」を計算する。
        $asOfToday = $command->asOf !== null ? Carbon::parse($command->asOf) : null;
        $defaultTimezone = SystemSetting::current()->default_timezone;
        $warnedCount = 0;

        // 社員×付与日(基準日)の組ごとに判定する。取消済みの付与は除く。
        $groups = PaidLeaveGrant::query()
            ->with('user')
            ->whereNull('revoked_at')
            ->get()
            ->groupBy(fn (PaidLeaveGrant $grant) => $grant->user_id.'|'.$grant->granted_on->toDateString());

        foreach ($groups as $grants) {
            /** @var Collection<int, PaidLeaveGrant> $grants */
            $first = $grants->first();

            // 組の付与日数の合計が10日以上の組だけが義務の対象(比例付与の7日+8日なども対象)。
            if ((float) $grants->sum('granted_days') < self::MINIMUM_GRANT_DAYS_FOR_OBLIGATION) {
                continue;
            }

            // 警告済みの印は組の全付与に付けるため、1件でも警告済みなら組は警告済み。
            if ($grants->contains(fn (PaidLeaveGrant $grant) => $grant->five_day_obligation_warned_at !== null)) {
                continue;
            }

            $today = $asOfToday ?? Carbon::today($first->user->timezone ?? $defaultTimezone);
            // granted_onはDB上UTCのCarbonになる一方、todayは社員のタイムゾーンで生成される。
            // 日付同士の比較なので同じタイムゾーンの0時へ揃え、UTCとの9時間差等で
            // 60日境界が60.375日となって対象外になることを防ぐ。
            $baseDate = Carbon::parse($first->granted_on->toDateString(), $today->getTimezone())->startOfDay();
            $obligationDeadline = $baseDate->copy()->addYear();
            $daysUntilDeadline = $today->diffInDays($obligationDeadline, false);

            if ($daysUntilDeadline < 0 || $daysUntilDeadline > self::WARNING_WINDOW_DAYS) {
                continue;
            }

            // 取得日数: 消化記録(paid_leave_usages)のうち、使用日が[基準日, 基準日+1年)の確定済み・未取消・時間単位以外の
            // used_daysの合計。どの付与から充当したかは問わない(paid_leave_grant_idは使わない)。
            $usedDays = (float) PaidLeaveUsage::query()
                ->where('user_id', $first->user_id)
                ->where('confirmed', true)
                ->where('cancelled', false)
                ->where(fn ($query) => $query->whereNull('usage_type')->orWhere('usage_type', '!=', 'hourly'))
                ->whereDate('used_on', '>=', $baseDate->toDateString())
                ->whereDate('used_on', '<', $obligationDeadline->toDateString())
                ->sum('used_days');

            if ($usedDays >= self::REQUIRED_USED_DAYS) {
                continue;
            }

            $message = "{$first->user->name}さんは年5日の有給取得義務(期限: ".
                "{$obligationDeadline->toDateString()})に対し、現在{$usedDays}日しか取得していません。";

            SendNotificationJob::enqueue(
                recipient: $first->user,
                title: '有給休暇 年5日取得義務の警告',
                summary: $message,
                detailUrl: null,
            );

            foreach ($grants as $grant) {
                $this->commandBus->dispatch(new RaisePaidLeaveGrantWarning(
                    userId: $grant->user_id,
                    grantId: $grant->id,
                    warningType: 'five_day_obligation',
                    message: $message,
                ));
            }

            $warnedCount++;
        }

        return $warnedCount;
    }
}
