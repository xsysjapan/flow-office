<?php

namespace Tests\Unit\EventSourcing;

use App\Domain\EventSourcing\Rebuild\BooleanPropertyConverter;
use PHPUnit\Framework\TestCase;

/** 旧項目名 → 新項目名の変換(docs/changesets/20261123-boolean-column-naming/spec.md 論点5)。DBは使わない。 */
class BooleanPropertyConverterTest extends TestCase
{
    public function test_a_top_level_key_is_renamed_in_place_with_the_value_unchanged(): void
    {
        $conversion = (new BooleanPropertyConverter)->convert('device.settings_updated', '{"name":"A","allowOffline":false,"requireLocation":true,"autoDetectPunchType":true,"updatedByUserId":"u"}');

        $this->assertSame('{"name":"A","allowsOffline":false,"requiresLocation":true,"punchTypeAutoDetectionEnabled":true,"updatedByUserId":"u"}', $conversion->newJson);
        $this->assertFalse($conversion->filled);
        $this->assertNull($conversion->conflict);
    }

    public function test_keys_inside_arrays_are_renamed_and_other_keys_and_empty_objects_are_kept(): void
    {
        $conversion = (new BooleanPropertyConverter)->convert(
            'external_integration_connection.updated',
            '{"before":{"id":"x","enabled":true,"custom_settings":{}},"after":{"enabled":false,"custom_settings":[]},"actorUserId":"u","enabled":true}',
        );

        $this->assertSame(
            '{"before":{"id":"x","publishing_enabled":true,"custom_settings":{}},"after":{"publishing_enabled":false,"custom_settings":[]},"actorUserId":"u","enabled":true}',
            $conversion->newJson,
            '配列の外の同名のキー(enabled)は変えない',
        );
    }

    public function test_an_absent_array_or_missing_old_key_in_an_array_changes_nothing(): void
    {
        $converter = new BooleanPropertyConverter;

        $this->assertNull($converter->convert('external_integration_connection.created', '{"actorUserId":"u"}')->newJson);
        $this->assertNull($converter->convert('external_integration_connection.deleted', '{"before":null,"actorUserId":"u"}')->newJson);
        $this->assertNull($converter->convert('system_settings.updated', '{"before":{},"after":{"default_timezone":"UTC"},"actorUserId":"u"}')->newJson);
        $this->assertNull($converter->convert('work_style.created', '{"attributes":[],"createdByUserId":"u"}')->newJson);
    }

    public function test_missing_keys_are_filled_with_the_values_the_old_projector_used(): void
    {
        $converter = new BooleanPropertyConverter;

        $punch = $converter->convert('attendance_punch.recorded', '{"userId":"u","punchType":"clock_in"}');
        $this->assertSame('{"userId":"u","punchType":"clock_in","isOffline":false}', $punch->newJson);
        $this->assertTrue($punch->filled);

        foreach (['company_calendar.created', 'company_calendar.updated'] as $class) {
            $calendar = $converter->convert($class, '{"name":"c"}');
            $this->assertSame('{"name":"c","allowsDailyHolidayOverride":true}', $calendar->newJson);
            $this->assertTrue($calendar->filled);
        }
        $nullValue = $converter->convert('company_calendar.created', '{"name":"c","allowDailyHolidayOverride":null}');
        $this->assertSame('{"name":"c","allowsDailyHolidayOverride":true}', $nullValue->newJson, 'null は Projector の ?? true と同じ');

        $calculation = $converter->convert('attendance_day.calculated', '{"calculation":{"worked_minutes":480},"userId":"u"}');
        $this->assertSame('{"calculation":{"worked_minutes":480,"has_core_time_violation":false},"userId":"u"}', $calculation->newJson);
        $this->assertTrue($calculation->filled);
        // 配列そのものが無い行には補わない。
        $this->assertNull($converter->convert('attendance_day.calculated', '{"userId":"u"}')->newJson);
    }

    public function test_rows_that_already_have_the_new_key_are_left_alone(): void
    {
        $converter = new BooleanPropertyConverter;

        $this->assertNull($converter->convert('attendance_punch.recorded', '{"isOffline":true}')->newJson);
        $this->assertNull($converter->convert('company_calendar.updated', '{"allowsDailyHolidayOverride":false}')->newJson);
        $this->assertNull($converter->convert('attendance_day.calculated', '{"calculation":{"has_core_time_violation":true}}')->newJson);
        $this->assertNull($converter->convert('device.registered', '{"allowsOffline":true,"requiresLocation":false,"punchTypeAutoDetectionEnabled":false}')->newJson);
    }

    public function test_old_and_new_keys_together_are_a_conflict_and_not_converted(): void
    {
        $converter = new BooleanPropertyConverter;

        $top = $converter->convert('attendance_punch.recorded', '{"offline":true,"isOffline":true}');
        $this->assertNull($top->newJson);
        $this->assertSame('offline と isOffline が両方ある', $top->conflict);
        $this->assertTrue($top->needsAttention());

        $nested = $converter->convert('attendance_day.calculated', '{"calculation":{"core_time_violation":true,"has_core_time_violation":true}}');
        $this->assertNull($nested->newJson);
        $this->assertSame('calculation.core_time_violation と calculation.has_core_time_violation が両方ある', $nested->conflict);
    }

    public function test_a_required_top_level_key_missing_without_old_key_or_default_is_unfillable(): void
    {
        $converter = new BooleanPropertyConverter;

        $row = $converter->convert('device.registered', '{"name":"A","requireLocation":true,"autoDetectPunchType":false}');
        $this->assertNull($row->newJson);
        $this->assertSame('allowOffline も allowsOffline も無く、補う値が無い', $row->unfillable);
        $this->assertTrue($row->needsAttention());
        $this->assertSame('enabled も isActive も無く、補う値が無い', $converter->convert('asset_number_rule.configured', '{"prefix":"PC"}')->unfillable);
        $this->assertNull($converter->convert('attendance_punch.recorded', '{}')->unfillable, '補う値がある項目は unfillable ではない');
    }

    public function test_events_and_keys_outside_the_table_are_not_changed(): void
    {
        $converter = new BooleanPropertyConverter;

        $this->assertNull($converter->convert('user.paid_leave_auto_grant_enabled_set', '{"enabled":false,"changedByUserId":"u"}')->newJson);
        $this->assertNull($converter->convert('special_leave.auto_grant_enabled_set', '{"enabled":false}')->newJson);
        $this->assertNull($converter->convert('device.registered', 'not json')->newJson);
        $this->assertNull($converter->convert('device.registered', '[1,2]')->newJson);
        // 表に無いキー(別のイベントで使われる offline)は、対象外のイベントでは変えない。
        $this->assertNull($converter->convert('attendance_punch.corrected', '{"offline":true}')->newJson);
    }

    public function test_the_changes_describe_old_to_new_with_values(): void
    {
        $conversion = (new BooleanPropertyConverter)->convert('attendance_punch.recorded', '{"offline":true}');

        $this->assertSame(['offline=true → isOffline=true'], $conversion->changes);
    }
}
