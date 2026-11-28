<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\Asset\Commands\ChangeAssetManagementType;
use App\Domain\Asset\Commands\InstallAsset;
use App\Domain\Asset\Commands\LendAsset;
use App\Domain\Asset\Commands\RegisterAsset;
use App\Domain\Asset\Commands\RelocateAsset;
use App\Domain\Asset\Commands\RemoveAssetFromInstallation;
use App\Domain\Asset\Commands\ReturnAsset;
use App\Domain\Asset\Commands\SetAssetDefaultLocation;
use App\Domain\Asset\Commands\DeleteAsset;
use App\Domain\Attendance\Commands\AllocateWeeklyOvertime;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Models\Asset;
use App\Models\AssetManagementType;
use App\Models\CompanyCalendarYear;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\EventSourcing\Concerns\BuildsReadModelRebuildFixtures;
use Tests\TestCase;

/**
 * 削除を含む操作の後に、ReadModelの作り直し(試し実行)で差分が出ないこと
 * (docs/changesets/20261123-rebuild-drift-fixes/spec.md 論点1・論点4)。
 * 作り直しの間は外部キーの検査が切れてcascadeOnDeleteが働かないため、削除イベントで子の行を明示的に消していないと、
 * 作り直した結果に孤児の子行が残ってライブと食い違う。`RefreshDatabase`ではSQLiteのPRAGMAが効かず再現できないので
 * `DatabaseMigrations`を使う。前提データはCommand/APIで作る。
 */
class ReadModelRebuildAfterDeletionScenarioTest extends TestCase
{
    use BuildsReadModelRebuildFixtures;
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->runRebuildDatabaseMigrations();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearRebuildHeartbeat();
        config(['read_model_rebuild.claim_wait_seconds' => 0]);
        $this->seedAccessControl();
    }

    protected function tearDown(): void
    {
        $this->clearRebuildHeartbeat();
        parent::tearDown();
    }

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    /**
     * `TestCase::actingAs`は、イベントを経ない役割の割当を直接書く(ReadModelの作り直しで差分になる)ため使わない。
     * 権限は、利用者の所属グループにイベントで付いた役割(シーダー + `createEventAdmin`)で決まる。
     */
    private function as(User $user): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    private function assertRebuildIsIdentical(): void
    {
        $summary = (new ReadModelRebuilder)->rebuild(true);

        $changed = array_map(
            fn (array $row) => $row['table'],
            array_filter($summary['tables'], fn (array $row) => $row['changed']),
        );
        $this->assertSame([], array_values($changed), '作り直しで差分が出た表');
        $this->assertSame(0, $summary['changed_tables']);
    }

    public function test_rebuild_matches_after_deleting_an_attendance_day_with_children(): void
    {
        $employee = $this->createEventUser('Employee A');

        $dayId = $this->as($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'reason' => '登録',
        ])->assertCreated()->json('id');
        $this->as($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-08-10T11:00:00+09:00',
            'actual_end_at' => '2026-08-10T18:00:00+09:00',
            'breaks' => [['start' => '2026-08-10T12:00:00+09:00', 'end' => '2026-08-10T13:00:00+09:00']],
            'leave_segments' => [
                ['start' => '2026-08-10T09:00:00+09:00', 'end' => '2026-08-10T11:00:00+09:00', 'note' => '寝坊のため'],
            ],
            'reason' => '遅刻を欠勤扱いにする',
        ])->assertOk();
        $this->bus()->dispatch(new AllocateWeeklyOvertime('2026-08-10', [['attendance_day_id' => $dayId, 'prescribed_minutes' => 0, 'non_prescribed_minutes' => 0, 'late_night_prescribed_minutes' => 0, 'late_night_non_prescribed_minutes' => 0]], $employee->id));

        $this->assertSame(1, DB::table('attendance_breaks')->where('attendance_day_id', $dayId)->count());
        $this->assertSame(1, DB::table('attendance_leave_segments')->where('attendance_day_id', $dayId)->count());
        $this->assertSame(1, DB::table('attendance_daily_calculations')->where('attendance_day_id', $dayId)->count());
        $this->assertSame(1, DB::table('attendance_weekly_overtime_allocations')->where('attendance_day_id', $dayId)->count());

        $this->as($employee)->deleteJson("/api/attendance/days/{$dayId}", ['reason' => '削除'])->assertOk();

        $this->assertSame(0, DB::table('attendance_breaks')->count());
        $this->assertRebuildIsIdentical();
    }

    public function test_rebuild_matches_after_deleting_a_company_calendar_and_a_year(): void
    {
        $admin = $this->createEventAdmin();

        // 先に作ったカレンダーが既定になる(既定は削除できない)。
        $this->as($admin)->postJson('/api/company-calendars', [
            'name' => '既定', 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->assertCreated();
        $keptId = $this->as($admin)->postJson('/api/company-calendars', [
            'name' => '残す', 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->assertCreated()->json('id');
        $deletedId = $this->as($admin)->postJson('/api/company-calendars', [
            'name' => '消す', 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->assertCreated()->json('id');
        $secondYearId = $this->as($admin)->postJson("/api/company-calendars/{$keptId}/years", [
            'fiscal_year' => 2027, 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31',
        ])->assertCreated()->json('id');
        $deletedYearIds = CompanyCalendarYear::query()->where('company_calendar_id', $deletedId)->pluck('id')->all();
        $this->assertNotSame([], $deletedYearIds);

        $this->as($admin)->deleteJson("/api/company-calendar-years/{$secondYearId}")->assertSuccessful();
        $this->as($admin)->deleteJson("/api/company-calendars/{$deletedId}")->assertSuccessful();

        $this->assertSame(0, DB::table('company_calendar_days')->whereIn('calendar_id', $deletedYearIds)->count());
        $this->assertSame(0, DB::table('company_calendar_days')->where('calendar_id', $secondYearId)->count());
        $this->assertRebuildIsIdentical();
    }

    public function test_rebuild_matches_after_a_holiday_source_is_synced_and_then_deleted(): void
    {
        $admin = $this->createEventAdmin();
        Http::fake([
            'https://example.com/holidays.ics' => Http::response(
                "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:uid-1\nDTSTART;VALUE=DATE:20260505\nSUMMARY:こどもの日\nEND:VEVENT\nEND:VCALENDAR\n",
                200,
            ),
        ]);
        $sourceId = $this->as($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => '祝日', 'ics_url' => 'https://example.com/holidays.ics',
        ])->assertCreated()->json('id');
        $calendarId = $this->as($admin)->postJson('/api/company-calendars', [
            'name' => '本社', 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'holiday_calendar_source_id' => $sourceId,
        ])->assertCreated()->json('id');

        $this->as($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();
        $this->assertGreaterThan(0, DB::table('company_calendar_day_sources')->where('source_type', 'holiday_sync')->count());
        $this->assertGreaterThan(0, DB::table('holiday_calendar_events')->count());

        // 使用中は削除できないため、カレンダー側の割当を解除してから削除する。
        $this->as($admin)->putJson("/api/company-calendars/{$calendarId}", [
            'name' => '本社', 'holiday_calendar_source_id' => null,
        ])->assertOk();
        $this->as($admin)->deleteJson("/api/holiday-calendar-sources/{$sourceId}")->assertNoContent();

        $this->assertSame(0, DB::table('holiday_calendar_events')->count());
        $this->assertRebuildIsIdentical();
    }

    public function test_rebuild_matches_after_a_holiday_sync_without_deletion(): void
    {
        // 自動採番のcompany_calendar_days.idを参照するcompany_calendar_day_sourcesが、作り直しでidが変わっても一致する(論点4)。
        $admin = $this->createEventAdmin();
        Http::fake([
            'https://example.com/holidays.ics' => Http::response(
                "BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:uid-1\nDTSTART;VALUE=DATE:20260505\nSUMMARY:こどもの日\nEND:VEVENT\nEND:VCALENDAR\n",
                200,
            ),
        ]);
        $sourceId = $this->as($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => '祝日', 'ics_url' => 'https://example.com/holidays.ics',
        ])->assertCreated()->json('id');
        $this->as($admin)->postJson('/api/company-calendars', [
            'name' => '本社', 'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'holiday_calendar_source_id' => $sourceId,
        ])->assertCreated();
        $this->as($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();

        $this->assertRebuildIsIdentical();
    }

    public function test_rebuild_matches_after_deleting_a_draft_expense_claim_with_items(): void
    {
        $employee = $this->createEventUser('Employee A');
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費',
            'evidence_type_default' => ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE,
        ]);
        $claimId = $this->as($employee)->postJson('/api/expense-claims')->json('id');
        $this->as($employee)->postJson("/api/expense-claims/{$claimId}/items", [
            'category_id' => $category->id, 'amount' => 500,
        ])->assertCreated();
        $this->assertSame(1, DB::table('expense_items')->where('claim_id', $claimId)->count());

        $this->as($employee)->deleteJson("/api/expense-claims/{$claimId}")->assertNoContent();

        $this->assertSame(0, DB::table('expense_items')->count());
        $this->assertRebuildIsIdentical();
    }

    public function test_rebuild_matches_after_deleting_an_asset_with_loans_placements_and_default_location_changes(): void
    {
        $admin = $this->createEventAdmin();
        $borrower = $this->createEventUser('Borrower');
        /** @var Asset $asset */
        $asset = $this->bus()->dispatch(new RegisterAsset(
            assetNo: 'EQ-REBUILD-1', name: 'ノートPC', category: 'PC', serialNumber: null,
            managementType: AssetManagementType::LENDING, lendingMethod: 'backoffice',
            defaultLocationText: null, notes: null, registeredByUserId: $admin->id,
        ));
        $this->bus()->dispatch(new SetAssetDefaultLocation($asset->id, '本社1F', $admin->id));
        $loaned = $this->bus()->dispatch(new LendAsset($asset->id, $borrower->id, $admin->id));
        $this->bus()->dispatch(new ReturnAsset($asset->id, $loaned->current_loan_id, $borrower->id));
        $this->bus()->dispatch(new ChangeAssetManagementType($asset->id, AssetManagementType::INSTALLATION, $admin->id));
        $this->bus()->dispatch(new InstallAsset($asset->id, '2F会議室', $admin->id));
        $this->bus()->dispatch(new RelocateAsset($asset->id, '3F倉庫', $admin->id));
        // 設置中のままでは削除できない。設置の履歴(asset_placements)は残る。
        $this->bus()->dispatch(new RemoveAssetFromInstallation($asset->id, $admin->id));

        $this->assertGreaterThan(0, DB::table('asset_loans')->where('asset_id', $asset->id)->count());
        $this->assertGreaterThan(0, DB::table('asset_placements')->where('asset_id', $asset->id)->count());
        $this->assertGreaterThan(0, DB::table('asset_default_location_changes')->where('asset_id', $asset->id)->count());

        $this->bus()->dispatch(new DeleteAsset($asset->id, $admin->id));

        $this->assertSame(0, DB::table('asset_loans')->count());
        $this->assertRebuildIsIdentical();
    }
}
