<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Domain\Attendance\Projectors\AttendanceDailyCalculationProjector;
use App\Domain\Attendance\Projectors\AttendanceWeeklyOvertimeAllocationProjector;
use App\Domain\Attendance\Projectors\WorkStyleProjector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

#[AdminExecutable(label: '勤怠計算Projection再構築')]
final class RebuildAttendanceCalculationProjectionsCommand extends Command
{
    protected $signature = 'attendance:rebuild-calculation-projections';

    protected $description = '履歴補正後に勤務形態・日次計算・週40時間超配賦のProjectionだけを再構築する';

    public function handle(): int
    {
        $projectors = [
            WorkStyleProjector::class,
            AttendanceDailyCalculationProjector::class,
            AttendanceWeeklyOvertimeAllocationProjector::class,
        ];

        foreach ($projectors as $projector) {
            $this->info("{$projector} を再構築します。");
            $exitCode = Artisan::call('event-sourcing:replay', [
                'projector' => [$projector],
                '--force' => true,
            ], $this->output);
            if ($exitCode !== self::SUCCESS) {
                $this->error("{$projector} の再構築に失敗しました。");

                return self::FAILURE;
            }
        }

        $this->info('勤怠計算Projectionの再構築が完了しました。');

        return self::SUCCESS;
    }
}
