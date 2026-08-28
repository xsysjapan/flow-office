<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceMonth;
use App\Models\ExternalEmployeeMapping;
use App\Models\ExternalIntegrationConnection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * フェーズ2: 勤怠月次確定データをfreee/moneyforward等の外部APIへ送信する
 * (docs/33-usecases-attendance-external-api.md)。
 */
class AttendanceExternalApiPublishTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(): array
    {
        return [
            'work_minutes' => 9600,
            'prescribed_work_minutes' => 9600,
            'statutory_within_overtime_minutes' => 0,
            'statutory_excess_overtime_minutes' => 120,
            'late_night_work_minutes' => 60,
            'legal_holiday_work_minutes' => 0,
            'prescribed_holiday_work_minutes' => 0,
            'absence_days' => 0.0,
            'paid_leave_days' => 1.0,
        ];
    }

    public function test_freee_publish_sends_payload_and_records_stored_event(): void
    {
        Http::fake([
            'https://api.freee.co.jp/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => '締め済み社員']);
        $month = AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'closed',
            'snapshot_json' => $this->snapshot(),
        ]);

        ExternalIntegrationConnection::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'enabled' => true,
            'external_office_id' => '999',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
        ]);

        ExternalEmployeeMapping::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'user_id' => $employee->id,
            'external_employee_code' => '4001',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/exports/attendance/external-publish', [
            'year_month' => ['2026-06'],
            'provider' => 'freee',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'provider' => 'freee',
            'successes' => [[
                'user_id' => $employee->id,
                'year_month' => '2026-06',
                'external_employee_code' => '4001',
            ]],
            'failures' => [],
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.freee.co.jp/hr/api/v1/employees/4001/work_record_summaries/2026/6'
                && $request->method() === 'PUT'
                && $request->hasHeader('Authorization', 'Bearer valid-access-token')
                && $request['company_id'] === 999
                && $request['total_work_mins'] === 9600
                && $request['total_normal_work_mins'] === 9600
                && $request['total_overtime_work_mins'] === 120
                && $request['total_latenight_work_mins'] === 60
                && (float) $request['num_paid_holidays'] === 1.0
                && ! array_key_exists('_path', $request->data())
                && ! array_key_exists('employee_code', $request->data());
        });

        $this->assertSame(
            1,
            EloquentStoredEvent::query()->where('event_class', 'external_integration.published')->count(),
        );
    }

    /**
     * MoneyForwardには外部から勤怠データをプッシュする公開APIが存在しないことが判明したため
     * (docs/notes/moneyforward-api-investigation.md)、勤怠のAPIプッシュ連携はfreeeのみ対応する。
     * MoneyForward向けの勤怠出力は引き続きCSVのみで案内する(CSVフォーマット自体は変更なし)。
     */
    public function test_moneyforward_provider_is_rejected_for_attendance_external_publish(): void
    {
        Http::fake();

        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => '締め済み社員2']);
        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'closed',
            'snapshot_json' => $this->snapshot(),
        ]);

        $response = $this->actingAs($admin)->postJson('/api/exports/attendance/external-publish', [
            'year_month' => ['2026-06'],
            'provider' => 'moneyforward',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['provider']);

        Http::assertNothingSent();
        $this->assertSame(
            0,
            EloquentStoredEvent::query()->where('event_class', 'external_integration.published')->count(),
        );
    }

    public function test_missing_employee_mapping_is_reported_as_a_failure_without_sending(): void
    {
        Http::fake();

        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $employee = User::factory()->create(['name' => 'マッピング未登録社員']);
        AttendanceMonth::query()->create([
            'user_id' => $employee->id,
            'year_month' => '2026-06',
            'status' => 'closed',
            'snapshot_json' => $this->snapshot(),
        ]);

        ExternalIntegrationConnection::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'enabled' => true,
            'access_token' => 'token',
            'token_expires_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($admin)->postJson('/api/exports/attendance/external-publish', [
            'year_month' => ['2026-06'],
            'provider' => 'freee',
        ]);

        $response->assertSuccessful();
        $response->assertJsonCount(0, 'successes');
        $response->assertJsonCount(1, 'failures');
        $response->assertJsonPath('failures.0.reason', 'employee_mapping_missing');

        Http::assertNothingSent();
        $this->assertSame(
            0,
            EloquentStoredEvent::query()->where('event_class', 'external_integration.published')->count(),
        );
    }

    public function test_employee_without_export_permission_is_forbidden(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/exports/attendance/external-publish', [
            'year_month' => ['2026-06'],
            'provider' => 'freee',
        ]);

        $response->assertForbidden();
    }
}
