<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 本番で実際に発生したデプロイ障害の再発防止テスト。
 *
 * `main`には元々、`2026_09_09_*`のタイムスタンプでこのドメインの旧スキーマ(通常/比例/
 * 時効の各法定ポリシー・Schedule Entryのテーブル)が既にデプロイ済みだった。その後、
 * PR#112(別実装)とのコンフリクト解消時に、これらの旧マイグレーションファイルを誤って
 * 削除してしまい、PR#112側の同名テーブルを作る新マイグレーション(`2026_09_13_*`)だけが
 * 残った状態でmainへマージされた。本番は`migrations`テーブルに旧ファイル名が実行済みとして
 * 記録済み・実テーブルも旧スキーマのまま存在するため、新マイグレーションが
 * `CREATE TABLE ... already exists`で本番デプロイ時に失敗した。
 *
 * 対応として、旧マイグレーションファイルを復元し、新マイグレーション
 * (`2026_09_13_000000/000001/000002/000004/000005`)を「旧テーブルを退避→新スキーマで
 * 作成→旧データを新形式へ変換してコピー→旧テーブル削除」という構成に書き換えた。
 * `RefreshDatabase`を使う通常のテストは常にまっさらなDBから全マイグレーションを流すため、
 * 退避元の旧テーブルには1行もデータが無く、データ移行ロジック自体は一度も実行されない
 * (コピー対象のforeachが0回で終わるだけ)。このテストは、本番で実際に起こりうる
 * 「旧スキーマに実データが入っている状態」を明示的に再現し、新マイグレーションの
 * データ移行ロジックが正しく動くことを検証する。
 */
class LegacySchemaMigrationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigrationUp(string $path): void
    {
        /** @var Migration $migration */
        $migration = require database_path($path);
        $migration->up();
    }

    public function test_grant_policies_legacy_data_is_converted_and_preserved(): void
    {
        Schema::dropIfExists('paid_leave_grant_policies');
        Schema::create('paid_leave_grant_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('continuous_service_months');
            $table->decimal('grant_days', 4, 1);
            $table->timestamps();
            $table->unique(['version', 'continuous_service_months'], 'paid_leave_grant_policies_unique');
        });
        DB::table('paid_leave_grant_policies')->insert([
            ['version' => 1, 'continuous_service_months' => 6, 'grant_days' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['version' => 1, 'continuous_service_months' => 18, 'grant_days' => 11, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->runMigrationUp('migrations/2026_09_13_000000_create_paid_leave_grant_policies_table.php');

        $this->assertFalse(Schema::hasTable('paid_leave_grant_policies_legacy'));
        $rows = DB::table('paid_leave_grant_policies')->orderBy('continuous_service_months')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('v1', $rows[0]->version);
        $this->assertSame(6, (int) $rows[0]->continuous_service_months);
        $this->assertSame(10.0, (float) $rows[0]->grant_days);
        $this->assertNull($rows[0]->effective_from);
        $this->assertTrue((bool) $rows[0]->is_active);
    }

    public function test_proportional_grant_policies_legacy_data_is_converted_and_preserved(): void
    {
        Schema::dropIfExists('paid_leave_proportional_grant_policies');
        Schema::create('paid_leave_proportional_grant_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version');
            $table->unsignedTinyInteger('weekly_scheduled_days');
            $table->unsignedSmallInteger('continuous_service_months');
            $table->decimal('grant_days', 4, 1);
            $table->timestamps();
            $table->unique(['version', 'weekly_scheduled_days', 'continuous_service_months'], 'paid_leave_prop_policy_unique');
        });
        DB::table('paid_leave_proportional_grant_policies')->insert([
            'version' => 1, 'weekly_scheduled_days' => 4, 'continuous_service_months' => 6, 'grant_days' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigrationUp('migrations/2026_09_13_000001_create_paid_leave_proportional_grant_policies_table.php');

        $this->assertFalse(Schema::hasTable('paid_leave_proportional_grant_policies_legacy'));
        $row = DB::table('paid_leave_proportional_grant_policies')->first();
        $this->assertSame('v1', $row->version);
        $this->assertSame('4', $row->weekly_scheduled_days_category);
        $this->assertSame(7.0, (float) $row->grant_days);
    }

    public function test_grant_expiry_policy_legacy_data_is_converted_and_preserved(): void
    {
        Schema::dropIfExists('paid_leave_grant_expiry_policy');
        Schema::create('paid_leave_grant_expiry_policy', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->unsignedTinyInteger('expiry_years');
            $table->timestamps();
        });
        DB::table('paid_leave_grant_expiry_policy')->insert([
            'version' => 1, 'expiry_years' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigrationUp('migrations/2026_09_13_000002_create_paid_leave_grant_expiry_policy_table.php');

        $this->assertFalse(Schema::hasTable('paid_leave_grant_expiry_policy_legacy'));
        $row = DB::table('paid_leave_grant_expiry_policy')->first();
        $this->assertSame('v1', $row->version);
        $this->assertSame(2, (int) $row->expiry_years);
    }

    public function test_schedule_entries_and_assessments_legacy_data_is_converted_and_preserved(): void
    {
        Schema::dropIfExists('paid_leave_schedule_assessments');
        Schema::dropIfExists('paid_leave_schedule_entries');
        Schema::create('paid_leave_schedule_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->date('scheduled_on');
            $table->string('category', 32);
            $table->decimal('candidate_grant_days', 4, 1);
            $table->string('status', 32);
            $table->text('manual_override_reason')->nullable();
            $table->foreignUuid('manual_override_by_user_id')->nullable()->constrained('users');
            $table->timestamp('manual_override_at')->nullable();
            $table->date('assessment_period_start')->nullable();
            $table->date('assessment_period_end')->nullable();
            $table->unsignedSmallInteger('assessment_denominator_days')->nullable();
            $table->unsignedSmallInteger('assessment_attendance_days')->nullable();
            $table->unsignedSmallInteger('assessment_excluded_days')->nullable();
            $table->decimal('assessment_attendance_rate', 5, 2)->nullable();
            $table->string('assessment_policy_version', 32)->nullable();
            $table->string('assessment_automatic_result', 32)->nullable();
            $table->string('assessment_final_result', 32)->nullable();
            $table->text('assessment_override_reason')->nullable();
            $table->foreignUuid('granted_paid_leave_grant_id')->nullable()->constrained('paid_leave_grants');
            $table->timestamps();
            $table->index(['user_id', 'scheduled_on']);
            $table->index('status');
        });

        $user = User::factory()->create();
        $entryWithAssessment = (string) Str::uuid();
        $entryWithoutAssessment = (string) Str::uuid();

        DB::table('paid_leave_schedule_entries')->insert([
            [
                'id' => $entryWithAssessment,
                'user_id' => $user->id,
                'scheduled_on' => '2026-03-01',
                'category' => 'normal',
                'candidate_grant_days' => 10.0,
                'status' => 'Eligible',
                'manual_override_reason' => null,
                'manual_override_by_user_id' => null,
                'manual_override_at' => null,
                'assessment_period_start' => '2025-09-01',
                'assessment_period_end' => '2026-02-28',
                'assessment_denominator_days' => 120,
                'assessment_attendance_days' => 110,
                'assessment_excluded_days' => 0,
                'assessment_attendance_rate' => 91.67,
                'assessment_policy_version' => 'v1',
                'assessment_automatic_result' => 'Eligible',
                'assessment_final_result' => 'Eligible',
                'assessment_override_reason' => null,
                'granted_paid_leave_grant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $entryWithoutAssessment,
                'user_id' => $user->id,
                'scheduled_on' => '2027-03-01',
                'category' => 'normal',
                'candidate_grant_days' => 11.0,
                'status' => 'Scheduled',
                'manual_override_reason' => null,
                'manual_override_by_user_id' => null,
                'manual_override_at' => null,
                'assessment_period_start' => null,
                'assessment_period_end' => null,
                'assessment_denominator_days' => null,
                'assessment_attendance_days' => null,
                'assessment_excluded_days' => null,
                'assessment_attendance_rate' => null,
                'assessment_policy_version' => null,
                'assessment_automatic_result' => null,
                'assessment_final_result' => null,
                'assessment_override_reason' => null,
                'granted_paid_leave_grant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->runMigrationUp('migrations/2026_09_13_000004_create_paid_leave_schedule_entries_table.php');
        $this->runMigrationUp('migrations/2026_09_13_000005_create_paid_leave_schedule_assessments_table.php');

        $this->assertFalse(Schema::hasTable('paid_leave_schedule_entries_legacy'));

        $entries = DB::table('paid_leave_schedule_entries')->orderBy('scheduled_on')->get();
        $this->assertCount(2, $entries);

        $withAssessment = $entries->firstWhere('id', $entryWithAssessment);
        $this->assertNotNull($withAssessment->latest_assessment_id);
        $this->assertSame(10.0, (float) $withAssessment->candidate_grant_days);

        $withoutAssessment = $entries->firstWhere('id', $entryWithoutAssessment);
        $this->assertNull($withoutAssessment->latest_assessment_id);

        $assessments = DB::table('paid_leave_schedule_assessments')->get();
        $this->assertCount(1, $assessments);
        $this->assertSame($entryWithAssessment, $assessments->first()->schedule_entry_id);
        $this->assertSame(120, (int) $assessments->first()->denominator_days);
        $this->assertSame('Eligible', $assessments->first()->final_result);
        $this->assertSame($withAssessment->latest_assessment_id, $assessments->first()->id);
    }
}
