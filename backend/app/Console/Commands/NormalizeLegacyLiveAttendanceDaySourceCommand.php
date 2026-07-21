<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use Illuminate\Console\Command;

/**
 * WEB画面の出退勤操作(UC-A001〜A004)を端末等と共通のRecordAttendancePunch/
 * AttendanceDayPunchSyncerに一本化した際、以前のClockIn/ClockOutHandler等が
 * 書き込んでいた`attendance_days.source = 'live'`は`'punch'`と同じ意味になり、
 * 今後は書き込まれなくなった。
 *
 * 既存の'live'行のままだと、AttendanceDayPunchSyncerのガード(source!=punchの日は
 * 打刻で上書きしない)により、以降その日への打刻の追記・訂正・削除が日次勤怠へ
 * 反映されなくなる(既に確定した日として扱われ続ける)ため、手動で一度だけ実行して
 * 'punch'に揃える。何度実行しても安全(対象が無くなれば0件で終わる)。
 *
 * stored_events自体は対象外(イベントは追記のみの原則。docs/17-events.md参照)。
 */
class NormalizeLegacyLiveAttendanceDaySourceCommand extends Command
{
    protected $signature = 'attendance:normalize-legacy-live-source {--dry-run : 実際には更新せず、対象件数のみ確認する}';

    protected $description = "attendance_days.source='live'の行を'punch'に正規化する(手動実行用の一度きりのデータ移行)";

    public function handle(): int
    {
        $query = AttendanceDay::query()->where('source', AttendanceDaySource::LIVE);
        $count = $query->count();

        if ($count === 0) {
            $this->info('対象行はありません。');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("対象: {$count}件(--dry-runのため更新は行いません)。");

            return self::SUCCESS;
        }

        $updated = $query->update(['source' => AttendanceDaySource::PUNCH]);
        $this->info("{$updated}件を'punch'に更新しました。");

        return self::SUCCESS;
    }
}
