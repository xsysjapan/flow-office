<?php

namespace Database\Seeders;

use App\Domain\Attendance\Commands\CreateCompanyCalendar;
use App\Domain\EventSourcing\CommandBus;
use App\Models\CompanyCalendar;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * docs/08-usecases-calendar-shift.md UC-C011: 会社カレンダー本体が1件も無い状態を作らない。
 * `DefaultWorkStyleSeeder`と同じ考え方(既にデフォルトが設定済みの環境では何もしない、
 * ユーザーが1人も存在しない場合も何もしない)。年度の生成はこのSeederでは行わず、
 * UC-C014の定期バッチ(`calendar:generate-years`)または管理者の操作に委ねる。
 */
class DefaultCompanyCalendarSeeder extends Seeder
{
    public function run(): void
    {
        if (CompanyCalendar::query()->where('is_default', true)->exists()) {
            return;
        }

        $creator = User::query()->orderBy('id')->first();

        if ($creator === null) {
            return;
        }

        app(CommandBus::class)->dispatch(new CreateCompanyCalendar(
            name: '標準カレンダー',
            weekStartsOn: 1,
            fiscalYearStartMonth: 4,
            fiscalYearStartDay: 1,
            createdByUserId: $creator->id,
        ));
    }
}
