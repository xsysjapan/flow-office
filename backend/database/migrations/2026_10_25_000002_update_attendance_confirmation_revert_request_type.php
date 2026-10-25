<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'attendance_confirmation_revert';

    /**
     * 承認の時点で月次勤怠が未提出に戻るため、バックオフィスタスクを作らないようにする。
     * 行が無ければ何もしない。
     */
    public function up(): void
    {
        DB::table('request_types')->where('code', self::CODE)->update([
            'requires_backoffice_task' => false,
            'backoffice_task_type' => null,
            'backoffice_department' => null,
            'description' => '承認されると、締め前(承認済み)の月次勤怠が未提出に戻り、日次実績を修正できるようになる依頼',
        ]);
    }

    public function down(): void
    {
        DB::table('request_types')->where('code', self::CODE)->update([
            'requires_backoffice_task' => true,
            'backoffice_task_type' => 'attendance_confirmation_revert',
            'backoffice_department' => '人事部',
            'description' => '締め前(承認済み)の月次勤怠の確定を取り消し、日次実績を修正できる状態に戻す依頼',
        ]);
    }
};
