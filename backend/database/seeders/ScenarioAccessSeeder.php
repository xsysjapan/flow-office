<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Commands\AssignFeatureToGroup;
use App\Domain\EventSourcing\CommandBus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 開発・E2Eシナリオ専用。製品初期状態とは分離して一般利用者の導線を明示的に開放する。
 *
 * 機能の割り当てはAssignFeatureToGroup(イベントを記録する)で作り、既にある行は飛ばす。
 * ALL_USERS/employeeのロールの割り当ては、AccessControlSeederがイベントで作る(同じグループ・ロール・scope)ため、ここでは作らない。
 */
class ScenarioAccessSeeder extends Seeder
{
    public function run(): void
    {
        $bus = app(CommandBus::class);
        $groupId = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        foreach (['attendance', 'attendance.clock', 'attendance.entry', 'attendance.timesheet', 'workflow', 'workflow.requests', 'paid_leave', 'paid_leave.requests', 'backoffice', 'backoffice.expenses'] as $code) {
            $this->assignFeature($bus, $groupId, $code);
        }
        $backofficeGroupId = DB::table('groups')->where('code', 'BACKOFFICE_USERS')->value('id');
        $this->assignFeature($bus, $backofficeGroupId, 'backoffice.tasks');
    }

    private function assignFeature(CommandBus $bus, string $groupId, string $featureCode): void
    {
        $featureId = (int) DB::table('features')->where('code', $featureCode)->value('id');
        if (DB::table('group_feature_assignments')->where('group_id', $groupId)->where('feature_id', $featureId)->exists()) {
            return;
        }
        $bus->dispatch(new AssignFeatureToGroup($groupId, $featureId, null));
    }
}
