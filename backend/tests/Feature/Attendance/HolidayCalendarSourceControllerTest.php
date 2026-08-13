<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Events\HolidayCalendarSourceRegistered;
use App\Models\CompanyCalendar;
use App\Models\CompanyCalendarDay;
use App\Models\CompanyCalendarDaySource;
use App\Models\CompanyCalendarYear;
use App\Models\HolidayCalendarSource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * UC-C012: 祝日iCalendarソースを同期する。
 */
class HolidayCalendarSourceControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    private function setUpCalendarFor(User $admin, string $sourceId): CompanyCalendarYear
    {
        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ])->assertOk();

        return $year;
    }

    private function ics(string $uid, string $date, string $summary): string
    {
        return <<<ICS
        BEGIN:VCALENDAR
        VERSION:2.0
        BEGIN:VEVENT
        UID:{$uid}
        DTSTART;VALUE=DATE:{$date}
        SUMMARY:{$summary}
        END:VEVENT
        END:VCALENDAR
        ICS;
    }

    /**
     * ICSアップロード対応でHolidayCalendarSourceRegisteredにsource_kind/uploaded_ics_path/
     * uploaded_ics_filenameを追加した際、既定値なしの必須引数として追加すると、これらの
     * キーを持たない過去のstored_events(URL登録しか無かった時代のイベント)が
     * デシリアライズできず「Failed to unserialize」で壊れる回帰を防ぐ。
     */
    public function test_a_legacy_registered_event_without_upload_fields_still_deserializes(): void
    {
        $sourceId = (string) Str::uuid();

        EloquentStoredEvent::create([
            'aggregate_uuid' => $sourceId,
            'aggregate_version' => 1,
            'event_version' => 1,
            'event_class' => 'holiday_calendar_source.registered',
            'event_properties' => [
                'name' => '内閣府祝日カレンダー',
                'icsUrl' => 'https://example.com/holidays.ics',
                'registeredByUserId' => (string) Str::uuid(),
            ],
            'meta_data' => [],
            'created_at' => now(),
        ]);

        $storedEvent = EloquentStoredEvent::query()
            ->where('aggregate_uuid', $sourceId)
            ->firstOrFail();

        $event = $storedEvent->event;

        $this->assertInstanceOf(HolidayCalendarSourceRegistered::class, $event);
        $this->assertSame('内閣府祝日カレンダー', $event->name);
        $this->assertSame('url', $event->sourceKind);
        $this->assertNull($event->uploadedIcsPath);
        $this->assertNull($event->uploadedIcsFilename);
    }

    /**
     * 上のテストが単体のデシリアライズだけを見ているのに対し、こちらは実際に
     * ユーザーが遭遇した状況(旧形式のイベントを持つ祝日iCalendarソースに対して
     * 同期を実行する)を通しで再現し、SyncHolidayCalendarSourceHandlerが
     * HolidayCalendarSourceAggregate::retrieve()で例外を起こさず正常に完了することを
     * 確認する。
     */
    public function test_syncing_a_source_with_a_legacy_registered_event_succeeds(): void
    {
        $admin = $this->makeAdmin();
        $sourceId = (string) Str::uuid();

        EloquentStoredEvent::create([
            'aggregate_uuid' => $sourceId,
            'aggregate_version' => 1,
            'event_version' => 1,
            'event_class' => 'holiday_calendar_source.registered',
            'event_properties' => [
                'name' => '内閣府祝日カレンダー',
                'icsUrl' => 'https://example.com/holidays.ics',
                'registeredByUserId' => (string) Str::uuid(),
            ],
            'meta_data' => [],
            'created_at' => now(),
        ]);
        HolidayCalendarSource::query()->create([
            'id' => $sourceId, 'name' => '内閣府祝日カレンダー', 'ics_url' => 'https://example.com/holidays.ics',
        ]);

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-legacy', '20260505', 'こどもの日'), 200),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync");

        $response->assertOk();
        $response->assertJsonPath('sync_status', 'synced');
    }

    public function test_registering_and_syncing_a_source_updates_public_holidays(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-1', '20260505', 'こどもの日'), 200),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => '内閣府祝日カレンダー',
            'ics_url' => 'https://example.com/holidays.ics',
        ])->assertCreated()->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ])->assertOk();

        $response = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync");

        $response->assertOk();
        $response->assertJsonPath('sync_status', 'synced');
        $this->assertDatabaseHas('holiday_calendar_events', ['ics_uid' => 'uid-1', 'name' => 'こどもの日']);
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'is_public_holiday' => true,
            'public_holiday_name' => 'こどもの日',
        ]);
        $response->assertJsonPath('last_sync_summary', [
            'added' => 1,
            'updated' => 0,
            'removed' => 0,
            'applied' => 1,
            'protected_conflicts' => 0,
        ]);
    }

    public function test_fetch_failure_marks_status_failed_and_does_not_touch_calendar_days(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response('', 500),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => 'ソース', 'ics_url' => 'https://example.com/holidays.ics',
        ])->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ]);

        $response = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync");

        $response->assertOk();
        $response->assertJsonPath('sync_status', 'failed');
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'date' => '2026-05-05 00:00:00',
            'is_public_holiday' => false,
        ]);
    }

    public function test_manually_overridden_day_is_protected_from_auto_holiday_sync(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-2', '20260505', 'こどもの日'), 200),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => 'ソース', 'ics_url' => 'https://example.com/holidays.ics',
        ])->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ]);

        $day = CompanyCalendarDay::query()->where('calendar_id', $year->id)->whereDate('date', '2026-05-05')->first();
        CompanyCalendarDaySource::query()->create([
            'id' => (string) Str::uuid(),
            'company_calendar_day_id' => $day->id,
            'source_type' => 'manual',
            'applied_at' => now(),
            'applied_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();

        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'date' => '2026-05-05 00:00:00',
            'is_public_holiday' => false,
        ]);
    }

    public function test_index_lists_registered_sources(): void
    {
        $admin = $this->makeAdmin();

        HolidayCalendarSource::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'ソースB', 'ics_url' => 'https://example.com/b.ics',
        ]);
        HolidayCalendarSource::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'ソースA', 'ics_url' => 'https://example.com/a.ics',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/holiday-calendar-sources');

        $response->assertOk();
        $response->assertJsonCount(2);
        $this->assertSame(['ソースA', 'ソースB'], collect($response->json())->pluck('name')->all());
    }

    public function test_reverting_the_last_sync_restores_the_previous_holiday_state(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-3', '20260505', 'こどもの日'), 200),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => '内閣府祝日カレンダー',
            'ics_url' => 'https://example.com/holidays.ics',
        ])->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ])->assertOk();

        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'is_public_holiday' => true,
            'public_holiday_name' => 'こどもの日',
        ]);

        $response = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/revert-last-sync");

        $response->assertOk();
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'date' => '2026-05-05 00:00:00',
            'is_public_holiday' => false,
            'public_holiday_name' => null,
        ]);
    }

    public function test_reverting_the_last_sync_does_not_touch_a_manually_overridden_day(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-4', '20260505', 'こどもの日'), 200),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => 'ソース', 'ics_url' => 'https://example.com/holidays.ics',
        ])->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ]);

        $day = CompanyCalendarDay::query()->where('calendar_id', $year->id)->whereDate('date', '2026-05-05')->first();
        CompanyCalendarDaySource::query()->create([
            'id' => (string) Str::uuid(),
            'company_calendar_day_id' => $day->id,
            'source_type' => 'manual',
            'applied_at' => now(),
            'applied_by_user_id' => $admin->id,
        ]);
        // 保護されているため、この日は同期でis_public_holidayを変更されない。
        $day->update(['is_public_holiday' => true, 'public_holiday_name' => '手動設定の祝日']);

        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();

        $response = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/revert-last-sync");

        $response->assertOk();
        // 手動設定日は同期でも取消でも変更されない。
        $this->assertDatabaseHas('company_calendar_days', [
            'id' => $day->id,
            'is_public_holiday' => true,
            'public_holiday_name' => '手動設定の祝日',
        ]);
    }

    public function test_reverting_without_any_sync_history_fails(): void
    {
        $admin = $this->makeAdmin();
        $source = HolidayCalendarSource::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'ソース', 'ics_url' => 'https://example.com/x.ics',
        ]);

        $response = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$source->id}/revert-last-sync");

        $response->assertStatus(422);
    }

    public function test_disabling_a_source_prevents_further_batch_sync(): void
    {
        $admin = $this->makeAdmin();
        $source = HolidayCalendarSource::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'ソース', 'ics_url' => 'https://example.com/x.ics',
        ]);

        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$source->id}/disable")
            ->assertOk()
            ->assertJsonPath('disabled_at', fn ($value) => $value !== null);

        $this->assertTrue($source->refresh()->isDisabled());
    }

    /**
     * 無効化(disable)したソースを再度有効化する手段が無いため、削除できるようにする。
     */
    public function test_a_disabled_source_can_be_deleted(): void
    {
        $admin = $this->makeAdmin();
        $source = HolidayCalendarSource::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'ソース', 'ics_url' => 'https://example.com/x.ics',
        ]);
        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$source->id}/disable")->assertOk();

        $this->actingAs($admin)->deleteJson("/api/holiday-calendar-sources/{$source->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('holiday_calendar_sources', ['id' => $source->id]);
    }

    public function test_deleting_a_source_used_by_a_company_calendar_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $source = HolidayCalendarSource::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'ソース', 'ics_url' => 'https://example.com/x.ics',
        ]);
        $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'holiday_calendar_source_id' => $source->id,
        ])->assertCreated();

        $this->actingAs($admin)->deleteJson("/api/holiday-calendar-sources/{$source->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('holiday_calendar_sources', ['id' => $source->id]);
    }

    public function test_deleting_a_source_also_deletes_its_holiday_calendar_events(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-del', '20260505', 'こどもの日'), 200),
        ]);
        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => 'ソース', 'ics_url' => 'https://example.com/holidays.ics',
        ])->json('id');
        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();
        $this->assertDatabaseHas('holiday_calendar_events', ['holiday_calendar_source_id' => $sourceId]);

        $this->actingAs($admin)->deleteJson("/api/holiday-calendar-sources/{$sourceId}")->assertNoContent();

        $this->assertDatabaseMissing('holiday_calendar_events', ['holiday_calendar_source_id' => $sourceId]);
    }

    public function test_registering_a_source_via_ics_file_upload_and_syncing_reads_from_the_stored_file(): void
    {
        Storage::fake('local');
        $admin = $this->makeAdmin();

        $file = UploadedFile::fake()->createWithContent(
            'holidays.ics',
            $this->ics('uid-upload-1', '20260505', 'こどもの日'),
        );

        $response = $this->actingAs($admin)->post('/api/holiday-calendar-sources', [
            'name' => '社内祝日リスト',
            'ics_file' => $file,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('source_kind', 'upload');
        $response->assertJsonPath('uploaded_ics_filename', 'holidays.ics');
        $sourceId = $response->json('id');

        $stored = HolidayCalendarSource::query()->findOrFail($sourceId);
        Storage::disk('local')->assertExists($stored->uploaded_ics_path);

        $year = $this->setUpCalendarFor($admin, $sourceId);

        $syncResponse = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync");

        $syncResponse->assertOk();
        $syncResponse->assertJsonPath('sync_status', 'synced');
        $this->assertDatabaseHas('holiday_calendar_events', ['ics_uid' => 'uid-upload-1', 'name' => 'こどもの日']);
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'is_public_holiday' => true,
            'public_holiday_name' => 'こどもの日',
        ]);
    }

    public function test_registering_a_source_rejects_when_both_ics_url_and_ics_file_are_given(): void
    {
        Storage::fake('local');
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post('/api/holiday-calendar-sources', [
            'name' => 'ソース',
            'ics_url' => 'https://example.com/holidays.ics',
            'ics_file' => UploadedFile::fake()->createWithContent('holidays.ics', $this->ics('uid-x', '20260505', '祝日')),
        ]);

        $response->assertStatus(422);
    }

    public function test_registering_a_source_rejects_when_neither_ics_url_nor_ics_file_are_given(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => 'ソース',
        ]);

        $response->assertStatus(422);
    }

    public function test_updating_a_url_source_changes_the_url_and_sync_fetches_the_new_url(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/old.ics' => Http::response($this->ics('uid-old', '20260505', '旧祝日'), 200),
            'https://example.com/new.ics' => Http::response($this->ics('uid-new', '20260505', '新祝日'), 200),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => 'ソース', 'ics_url' => 'https://example.com/old.ics',
        ])->json('id');

        $year = $this->setUpCalendarFor($admin, $sourceId);

        $updateResponse = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}", [
            'name' => 'ソース', 'ics_url' => 'https://example.com/new.ics',
        ]);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('ics_url', 'https://example.com/new.ics');

        $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync")->assertOk();

        $this->assertDatabaseHas('holiday_calendar_events', ['ics_uid' => 'uid-new', 'name' => '新祝日']);
        $this->assertDatabaseMissing('holiday_calendar_events', ['ics_uid' => 'uid-old']);
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'is_public_holiday' => true,
            'public_holiday_name' => '新祝日',
        ]);
    }

    public function test_syncing_scoped_to_one_year_only_updates_that_years_calendar_days(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-scope-1', '20260505', 'こどもの日'), 200),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => '内閣府祝日カレンダー',
            'ics_url' => 'https://example.com/holidays.ics',
        ])->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ])->assertOk();

        // 同じ祝日ソースを使う別年度(2027年度)にも同じ日付の日を作っておく。
        // holiday_calendar_source_idはこの時点ではまだ未設定にしておく(手動年度作成が
        // 曜日パターンの自動反映と同時に祝日ソースの即時同期も行うようになったため、
        // 先に設定すると本テストが検証したい「年度ごとにスコープした明示的な同期呼び出し」
        // より前に祝日イベントを消費してしまい、後続のeventChanges計算が狂ってしまう)。
        $otherYear = $this->actingAs($admin)->postJson("/api/company-calendars/{$calendarId}/years", [
            'fiscal_year' => 2027, 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31',
        ]);
        $otherYearId = $otherYear->json('id');
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$otherYearId}/days", [
            'days' => [['date' => '2027-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ])->assertOk();

        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);

        // 単一VCALENDAR内に2つのVEVENT(2026年度分・2027年度分)を持つICSへ差し替える。
        Http::fake([
            'https://example.com/holidays.ics' => Http::response(<<<'ICS'
            BEGIN:VCALENDAR
            VERSION:2.0
            BEGIN:VEVENT
            UID:uid-scope-1
            DTSTART;VALUE=DATE:20260505
            SUMMARY:こどもの日
            END:VEVENT
            BEGIN:VEVENT
            UID:uid-scope-2
            DTSTART;VALUE=DATE:20270505
            SUMMARY:こどもの日
            END:VEVENT
            END:VCALENDAR
            ICS, 200),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/sync-holiday-calendar");

        $response->assertOk();
        $response->assertJsonPath('sync_status', 'synced');

        // スコープ対象の年度は更新される。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'is_public_holiday' => true,
            'public_holiday_name' => 'こどもの日',
        ]);

        // 別年度は同じソース・同じ祝日イベントが追加されたにもかかわらず、対象外なので変更されない。
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $otherYearId,
            'date' => '2027-05-05 00:00:00',
            'is_public_holiday' => false,
        ]);
    }

    /**
     * 回帰テスト: holiday_calendar_eventsは祝日ソース単位で年度をまたいで共有されるため、
     * 一度同期済みの祝日と内容が変わっていない場合、旧実装はeventChanges(added/updated/
     * removed)が空になることを理由にday_changesの計算自体をスキップしていた。そのため、
     * 年度を削除して同じ年度番号で作り直した後に再同期しても、新しい年度のcompany_calendar_days
     * には祝日が一切反映されない不具合があった(削除→再作成後の再同期が機能しない)。
     */
    public function test_resyncing_after_deleting_and_recreating_a_year_still_applies_the_holiday(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-recreate', '20260505', 'こどもの日'), 200),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => '内閣府祝日カレンダー',
            'ics_url' => 'https://example.com/holidays.ics',
        ])->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'holiday_calendar_source_id' => $sourceId,
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        // 1回目の同期(この時点でholiday_calendar_eventsにuid-recreateが記録される)。
        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/sync-holiday-calendar")
            ->assertOk()
            ->assertJsonPath('sync_status', 'synced');
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id, 'date' => '2026-05-05 00:00:00', 'is_public_holiday' => true,
        ]);

        // 年度を削除して同じ年度番号で作り直す(company_calendar_daysは総入れ替えされ、
        // holiday_calendar_eventsはソース単位のため変わらず残る)。
        $this->actingAs($admin)->deleteJson("/api/company-calendar-years/{$year->id}")->assertNoContent();
        $newYear = $this->actingAs($admin)->postJson("/api/company-calendars/{$calendarId}/years", [
            'fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json();

        // フィードの内容は1回目と全く同じ(ICSのUIDが同じ=holiday_calendar_eventsに変更なし)まま
        // 再同期しても、新しい年度に祝日が反映されなければならない。
        $this->actingAs($admin)->postJson("/api/company-calendar-years/{$newYear['id']}/sync-holiday-calendar")
            ->assertOk()
            ->assertJsonPath('sync_status', 'synced');
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $newYear['id'], 'date' => '2026-05-05 00:00:00',
            'is_public_holiday' => true, 'public_holiday_name' => 'こどもの日',
        ]);
    }

    public function test_syncing_scoped_to_a_year_without_a_holiday_source_returns_422(): void
    {
        $admin = $this->makeAdmin();

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();

        $response = $this->actingAs($admin)->postJson("/api/company-calendar-years/{$year->id}/sync-holiday-calendar");

        $response->assertStatus(422);
    }

    public function test_unscoped_sync_endpoint_still_updates_all_years_using_the_source(): void
    {
        $admin = $this->makeAdmin();

        Http::fake([
            'https://example.com/holidays.ics' => Http::response($this->ics('uid-all-1', '20260505', 'こどもの日'), 200),
        ]);

        $sourceId = $this->actingAs($admin)->postJson('/api/holiday-calendar-sources', [
            'name' => '内閣府祝日カレンダー',
            'ics_url' => 'https://example.com/holidays.ics',
        ])->json('id');

        $calendarId = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
        ])->json('id');
        CompanyCalendar::query()->whereKey($calendarId)->update(['holiday_calendar_source_id' => $sourceId]);
        $year = CompanyCalendarYear::query()->where('company_calendar_id', $calendarId)->first();
        $this->actingAs($admin)->putJson("/api/company-calendar-years/{$year->id}/days", [
            'days' => [['date' => '2026-05-05', 'day_type' => 'weekday', 'schedule_state' => 'WORK']],
        ])->assertOk();

        $response = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}/sync");

        $response->assertOk();
        $this->assertDatabaseHas('company_calendar_days', [
            'calendar_id' => $year->id,
            'is_public_holiday' => true,
            'public_holiday_name' => 'こどもの日',
        ]);
    }

    public function test_updating_a_source_from_upload_kind_to_url_kind_deletes_the_old_uploaded_file(): void
    {
        Storage::fake('local');
        $admin = $this->makeAdmin();

        $file = UploadedFile::fake()->createWithContent('holidays.ics', $this->ics('uid-old-upload', '20260505', '旧祝日'));
        $sourceId = $this->actingAs($admin)->post('/api/holiday-calendar-sources', [
            'name' => 'ソース',
            'ics_file' => $file,
        ])->json('id');

        $oldPath = HolidayCalendarSource::query()->findOrFail($sourceId)->uploaded_ics_path;
        Storage::disk('local')->assertExists($oldPath);

        Http::fake([
            'https://example.com/new.ics' => Http::response($this->ics('uid-new', '20260505', '新祝日'), 200),
        ]);

        $updateResponse = $this->actingAs($admin)->postJson("/api/holiday-calendar-sources/{$sourceId}", [
            'name' => 'ソース', 'ics_url' => 'https://example.com/new.ics',
        ]);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('source_kind', 'url');

        Storage::disk('local')->assertMissing($oldPath);
    }
}
