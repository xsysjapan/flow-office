<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WEB画面の出退勤操作(UC-A001〜A004)が端末等と共通の`RecordAttendancePunch`/
 * `AttendanceDayPunchSyncer`に一本化され、`attendance_days.source`は今後
 * 'punch'のみが書き込まれるようになった。旧実装が書き込んでいた'live'は
 * 'punch'と全く同じ意味(打刻ログから組み立てられた実績)になったため、
 * 既存データも揃えておく。
 *
 * この移行を行わなくても既存の'live'行は`AttendanceDayPunchSyncer`のガード
 * (「source !== punch の日は打刻で上書きしない」)により壊れることはないが、
 * 今後その日に対して打刻の追記・訂正・削除が行われても日次勤怠へ反映されなく
 * なる('live'は既に確定した日として扱われ続ける)。念のため'punch'に揃え、
 * 通常通り打刻ログから再同期される状態に戻す。
 *
 * stored_events(イベントストア)は対象外。過去に記録された
 * `attendance.clocked_in`等のイベントは追記のみの原則により書き換えず、
 * そのままstored_eventsに残す(docs/17-events.md参照)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('attendance_days')->where('source', 'live')->update([
            'source' => 'punch',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // データ変換のみのため、どの行がもともと'live'だったかは復元できない。
    }
};
