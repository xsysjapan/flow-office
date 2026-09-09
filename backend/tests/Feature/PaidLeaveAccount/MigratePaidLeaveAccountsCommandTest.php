<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Models\PaidLeaveGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 最終Phase(データ移行)。`paid-leave:migrate-accounts` artisanコマンドの
 * 部分失敗許容(1行の不正データがバッチ全体を落とさない)を検証する
 * (docs/changesets/20260906-paid-leave-domain-redesign/spec.md「実装対象」参照)。
 */
class MigratePaidLeaveAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_import_continues_after_a_bad_row_and_reports_both(): void
    {
        $goodUser = User::factory()->create();
        $badUser = User::factory()->create();

        // 事前にGrantを1件作っておき、2行目のmigrateGrants呼び出しを失敗させる
        // (「Grantが既に存在する口座への移行は拒否」で意図的に不正行を作る)。
        \App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate::retrieve($badUser->id)
            ->grant('existing', '2025-04-01', '2027-04-01', 5.0, null, 'manual')
            ->persist();

        $payload = [
            [
                'user_id' => $goodUser->id,
                'cutover_date' => '2026-04-01',
                'grants' => [
                    [
                        'remaining_days_at_cutover' => 4.0,
                        'expires_on' => '2027-04-01',
                        'mode' => 'C',
                    ],
                ],
            ],
            [
                'user_id' => $badUser->id,
                'cutover_date' => '2026-04-01',
                'grants' => [
                    [
                        'remaining_days_at_cutover' => 2.0,
                        'expires_on' => '2027-04-01',
                        'mode' => 'C',
                    ],
                ],
            ],
        ];

        $path = tempnam(sys_get_temp_dir(), 'migration_test_').'.json';
        File::put($path, json_encode($payload));

        try {
            $this->artisan('paid-leave:migrate-accounts', ['file' => $path])
                ->assertExitCode(1); // 1件失敗があるため非0
        } finally {
            File::delete($path);
        }

        // 成功した行(goodUser)はGrantが作成されている。
        $this->assertTrue(PaidLeaveGrant::query()->where('user_id', $goodUser->id)->exists());

        // 失敗した行(badUser)は既存のGrant1件のままで、追加のmigration Grantは作られていない。
        $this->assertEquals(1, PaidLeaveGrant::query()->where('user_id', $badUser->id)->count());
    }

    public function test_dry_run_validates_without_dispatching(): void
    {
        $user = User::factory()->create();

        $payload = [
            [
                'user_id' => $user->id,
                'cutover_date' => '2026-04-01',
                'grants' => [
                    ['remaining_days_at_cutover' => 4.0, 'expires_on' => '2027-04-01', 'mode' => 'C'],
                ],
            ],
        ];

        $path = tempnam(sys_get_temp_dir(), 'migration_test_').'.json';
        File::put($path, json_encode($payload));

        try {
            $this->artisan('paid-leave:migrate-accounts', ['file' => $path, '--dry-run' => true])
                ->assertExitCode(0);
        } finally {
            File::delete($path);
        }

        $this->assertFalse(PaidLeaveGrant::query()->where('user_id', $user->id)->exists());
    }
}
