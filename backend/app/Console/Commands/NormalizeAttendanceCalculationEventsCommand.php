<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

#[AdminExecutable(
    label: '勤怠計算イベント履歴補正',
    rules: [
        'apply' => ['nullable', 'boolean'],
        'backup-table' => ['nullable', 'regex:/\A[a-z][a-z0-9_]{0,55}\z/'],
    ],
    ui: [
        'apply' => ['control' => 'checkbox'],
        'backup-table' => ['control' => 'text'],
    ],
)]
class NormalizeAttendanceCalculationEventsCommand extends Command
{
    protected $signature = 'attendance:normalize-calculation-events
        {--apply : バックアップを作成してイベントを補正する}
        {--backup-table=stored_events_backup_attendance_calculation_v2 : 補正前イベントのバックアップテーブル名}';

    protected $description = '日次勤怠計算イベントを新しい5区分へ直接変換する';

    public function handle(): int
    {
        $count = DB::table('stored_events')->where('event_class', 'attendance_day.calculated')->count();
        $this->info("対象イベント: {$count}件");
        if (! $this->option('apply')) {
            $this->info('ドライランです。--applyでバックアップ後に変換します。');

            return self::SUCCESS;
        }
        $backup = (string) $this->option('backup-table');
        if (preg_match('/\A[a-z][a-z0-9_]{0,55}\z/', $backup) !== 1 || Schema::hasTable($backup)) {
            throw new RuntimeException('バックアップテーブル名が不正、または既に存在します。');
        }
        Schema::create($backup, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->uuid('aggregate_uuid')->nullable();
            $table->unsignedBigInteger('aggregate_version')->nullable();
            $table->unsignedTinyInteger('event_version')->default(1);
            $table->string('event_class');
            $table->json('event_properties');
            $table->json('meta_data');
            $table->timestamp('created_at');
        });
        $events = ['attendance_day.calculated', 'work_style.created', 'work_style.updated'];
        DB::table('stored_events')->whereIn('event_class', $events)->orderBy('id')->chunk(500, fn ($rows) => DB::table($backup)->insert($rows->map(fn ($row) => (array) $row)->all()));
        DB::transaction(function () use ($events): void {
            DB::table('stored_events')->whereIn('event_class', $events)->orderBy('id')->each(function ($row): void {
                $properties = json_decode($row->event_properties, true, flags: JSON_THROW_ON_ERROR);
                if ($row->event_class === 'attendance_day.calculated') {
                    $c = $properties['calculation'] ?? [];
                    $legacyPrescribedWithin = max(0,
                        (int) ($c['work_minutes'] ?? 0)
                        - (int) ($c['statutory_within_overtime_minutes'] ?? 0)
                        - (int) ($c['statutory_excess_overtime_minutes'] ?? 0)
                        - (int) ($c['legal_holiday_work_minutes'] ?? 0)
                        - (int) ($c['prescribed_holiday_work_minutes'] ?? 0),
                    );
                    $c += [
                        'prescribed_statutory_within_work_minutes' => $legacyPrescribedWithin,
                        'non_prescribed_statutory_within_work_minutes' => (int) (($c['prescribed_holiday_work_minutes'] ?? 0) > 0
                            ? $c['prescribed_holiday_work_minutes']
                            : ($c['statutory_within_overtime_minutes'] ?? 0)),
                        'prescribed_statutory_excess_work_minutes' => 0,
                        'non_prescribed_statutory_excess_work_minutes' => (int) ($c['statutory_excess_overtime_minutes'] ?? 0),
                        'late_night_prescribed_statutory_within_work_minutes' => (int) ($c['late_night_prescribed_work_minutes'] ?? 0),
                        'late_night_non_prescribed_statutory_within_work_minutes' => (int) ($c['late_night_statutory_within_overtime_minutes'] ?? 0),
                        'late_night_prescribed_statutory_excess_work_minutes' => 0,
                        'late_night_non_prescribed_statutory_excess_work_minutes' => (int) ($c['late_night_statutory_excess_overtime_minutes'] ?? 0),
                    ];
                    $properties['calculation'] = $c;
                } else {
                    $properties['attributes']['workday_boundary_type'] ??= 'work_date';
                    $properties['attributes']['workday_boundary_time'] ??= null;
                }
                DB::table('stored_events')->where('id', $row->id)->update(['event_properties' => json_encode($properties, JSON_THROW_ON_ERROR)]);
            });
        });
        $this->info("変換完了。バックアップ: {$backup}");
        $this->warn('続けてProjection再生と月次スナップショット再計算を実行してください。');

        return self::SUCCESS;
    }
}
