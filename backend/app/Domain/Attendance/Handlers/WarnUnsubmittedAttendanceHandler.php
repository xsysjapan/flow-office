<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\WarnUnsubmittedAttendance;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Jobs\SendNotificationJob;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\AttendanceSubmissionReminderExclusion;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * UC-N001「勤怠未提出」: 前月分の勤怠がまだ提出されていない社員に警告する。
 * 提出期限(`system_settings.attendance_submission_deadline_day`、当月の日)を過ぎても
 * 前月分が未提出(差戻し中も含む)の在籍社員を対象に、解消するまで実行のたびに通知する
 * (状態を記録して警告を1回に絞る仕組みは持たない。docs/13-usecases-notification.md参照)。
 * 利用開始日が未設定、または利用開始日・入社日より前の月(まだ本システムの利用や在籍を
 * 開始していない期間)はフォロー対象外とする(利用開始日が未設定のまま誤って対象に
 * 含めてしまう事故を避けるため、未設定は「無制限」ではなく「対象外」として扱う)。
 * また、`attendance_submission_reminder_exclusions`に個別に
 * 除外登録された社員×対象月の組み合わせもフォロー対象外とする(誤ってその月を提出対象に
 * してしまった場合等の例外的対応。ExcludeAttendanceSubmissionReminderHandler参照)。
 *
 * @implements CommandHandler<WarnUnsubmittedAttendance>
 */
class WarnUnsubmittedAttendanceHandler implements CommandHandler
{
    /**
     * @return int 警告を発行した件数
     */
    public function handle(Command $command): int
    {
        assert($command instanceof WarnUnsubmittedAttendance);

        $today = $command->asOf !== null ? Carbon::parse($command->asOf) : Carbon::today();
        $deadlineDay = SystemSetting::current()->attendance_submission_deadline_day;

        if ($today->day < $deadlineDay) {
            return 0;
        }

        $targetMonth = $today->copy()->subMonthNoOverflow();
        $targetYearMonth = $targetMonth->format('Y-m');
        // Carbon::createFromFormat('Y-m', ...)は日付部分を「実行時点の日」で補完するため、
        // 対象月より日数が少ない月(例: 6月)を31日に実行すると7月扱いに繰り上がってしまう。
        // 文字列を再パースせず$targetMonthから直接endOfMonth()を求めることでこれを避ける。
        $targetMonthEnd = $targetMonth->copy()->endOfMonth()->toDateString();

        $submittedUserIds = AttendanceMonth::query()
            ->where('year_month', $targetYearMonth)
            ->whereIn('status', [AttendanceMonthStatus::SUBMITTED, AttendanceMonthStatus::APPROVED, AttendanceMonthStatus::CLOSED])
            ->pluck('user_id');

        $excludedUserIds = AttendanceSubmissionReminderExclusion::query()
            ->where('year_month', $targetYearMonth)
            ->pluck('user_id');

        $unsubmittedUsers = User::query()
            ->where('employment_status', 'active')
            ->whereNotIn('id', $submittedUserIds)
            ->whereNotIn('id', $excludedUserIds)
            ->whereNotNull('usage_start_date')
            ->whereDate('usage_start_date', '<=', $targetMonthEnd)
            ->where(fn ($query) => $query->whereNull('hire_date')->orWhereDate('hire_date', '<=', $targetMonthEnd))
            ->get(['id', 'name', 'email']);

        foreach ($unsubmittedUsers as $user) {
            SendNotificationJob::enqueue(
                recipient: $user,
                title: '勤怠未提出',
                summary: "{$user->name}さんの{$targetYearMonth}分の勤怠がまだ提出されていません。",
                detailUrl: null,
            );
        }

        return $unsubmittedUsers->count();
    }
}
