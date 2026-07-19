<?php

namespace Tests\Feature\User;

use App\Domain\User\Ms365ConfigResolver;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `system_settings.m365_mock_enabled`は初回オンボーディング(未認証で呼べるPOST /onboarding)
 * からも書き込めるDB値であり、本番・検証環境で誤って(または悪意を持って)trueにされる
 * 可能性を排除できない。この値が破壊的な開発専用エンドポイント(DevDatabaseResetController:
 * DB全体を初期化する)を露出させてしまわないよう、`APP_ENV`による強制ガードを確認する。
 */
class Ms365MockGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_enabled_is_true_in_testing_environment_when_db_flag_is_true(): void
    {
        SystemSetting::current()->update(['m365_mock_enabled' => true]);

        $this->assertTrue(Ms365ConfigResolver::mockEnabled());
    }

    public function test_mock_enabled_is_forced_false_outside_local_and_testing_even_if_db_flag_is_true(): void
    {
        SystemSetting::current()->update(['m365_mock_enabled' => true]);

        $this->app->detectEnvironment(fn () => 'production');

        $this->assertFalse(Ms365ConfigResolver::mockEnabled());
    }

    public function test_dev_endpoints_stay_unreachable_in_production_even_if_db_flag_is_true(): void
    {
        SystemSetting::current()->update(['m365_mock_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->getJson('/api/dev/mock-users')->assertNotFound();
        $this->postJson('/api/dev/reset-database')->assertNotFound();
    }
}
