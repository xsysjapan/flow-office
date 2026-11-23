<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 真偽値の列の命名の統一と、paid_leave_usages の確定フラグの二重化の解消
 * (変更セット docs/changesets/20261123-boolean-column-naming 論点1・3・4)。
 *
 * - `paid_leave_usages.confirmed`を削除して`is_confirmed`の1列に統一し、`cancelled`を`is_cancelled`に改名する。
 *   削除前に`is_confirmed <> confirmed`の行数をログに出す(0件の想定。0件でなくても、
 *   ReadModelの作り直しで新しいProjectorの値に揃う)。
 * - 他の列は`renameColumn`でデータを保ったまま改名する(正データの3表を含む)。
 *
 * down は逆の改名と`confirmed`の再作成(`is_confirmed`の値で埋める)。
 */
return new class extends Migration
{
    /**
     * テーブル => [旧名 => 新名]
     *
     * @var array<string, array<string, string>>
     */
    private const RENAMES = [
        'paid_leave_usages' => ['cancelled' => 'is_cancelled'],
        'devices' => [
            'allow_offline' => 'allows_offline',
            'require_location' => 'requires_location',
            'auto_detect_punch_type' => 'punch_type_auto_detection_enabled',
        ],
        'attendance_punches' => ['offline' => 'is_offline'],
        'company_calendars' => ['allow_daily_holiday_override' => 'allows_daily_holiday_override'],
        'work_styles' => ['system_generated' => 'is_system_generated'],
        'attendance_daily_calculations' => ['core_time_violation' => 'has_core_time_violation'],
        'group_types' => ['primary_membership_required' => 'requires_primary_membership'],
        'role_assignments' => ['include_descendants' => 'includes_descendants'],
        'leave_attendance_rate_days' => ['attended' => 'has_attended'],
        'special_leave_attendance_rate_days' => ['attended' => 'has_attended'],
        'leave_attendance_rate_attendance_days' => ['clocked_out' => 'has_clocked_out'],
        'special_leave_attendance_rate_attendance_days' => ['clocked_out' => 'has_clocked_out'],
        'authentication_key_device_rules' => ['allow' => 'allows_use'],
        'external_integration_connections' => ['enabled' => 'publishing_enabled'],
        'asset_number_rules' => ['enabled' => 'is_active'],
        'system_settings' => ['prohibit_self_privileged_role_assignment' => 'prohibits_self_privileged_role_assignment'],
    ];

    public function up(): void
    {
        $mismatch = DB::table('paid_leave_usages')->whereColumn('is_confirmed', '<>', 'confirmed')->count();
        Log::info('paid_leave_usages.confirmed を削除します。is_confirmed と confirmed が食い違う行数: '.$mismatch);

        foreach (self::RENAMES as $table => $columns) {
            foreach ($columns as $from => $to) {
                Schema::table($table, function (Blueprint $blueprint) use ($from, $to) {
                    $blueprint->renameColumn($from, $to);
                });
            }
        }

        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->dropColumn('confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_usages', function (Blueprint $table) {
            $table->boolean('confirmed')->default(false)->after('usage_type');
        });
        DB::statement('UPDATE paid_leave_usages SET confirmed = is_confirmed');

        foreach (self::RENAMES as $table => $columns) {
            foreach ($columns as $from => $to) {
                Schema::table($table, function (Blueprint $blueprint) use ($from, $to) {
                    $blueprint->renameColumn($to, $from);
                });
            }
        }
    }
};
