<?php

namespace Database\Seeders;

use App\Domain\Attendance\Commands\CreateDefaultWorkStyle;
use App\Domain\EventSourcing\CommandBus;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Database\Seeder;

/**
 * 指示書 3.1節: 「通常勤務」を明示的なデフォルト働き方として自動作成する。
 * 既にデフォルトが設定済みの環境(本番等、DefaultWorkStyleSeeder導入前から運用中)では
 * 何もしない。ユーザーが1人も存在しない場合(初回migrate直後でDatabaseSeeder未実行)も
 * 何もせず、後でオンボーディング画面から管理者が作成できるようにする。
 */
class DefaultWorkStyleSeeder extends Seeder
{
    public function run(): void
    {
        if (WorkStyle::query()->where('is_default', true)->exists()) {
            return;
        }

        $creator = User::query()->orderBy('id')->first();

        if ($creator === null) {
            return;
        }

        app(CommandBus::class)->dispatch(new CreateDefaultWorkStyle(
            overrides: [],
            createdByUserId: $creator->id,
        ));
    }
}
