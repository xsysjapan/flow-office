<?php

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\Events\WorkStyleUpdated;
use App\Domain\Attendance\Projectors\WorkStyleProjector;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkStyleProjectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_calendar_id_is_mapped_and_retired_attributes_are_ignored(): void
    {
        $workStyle = WorkStyle::query()->create([
            'id' => (string) Str::uuid(),
            'code' => 'legacy-style',
            'name' => '変更前',
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'company_calendar_id' => null,
        ]);
        $event = (new WorkStyleUpdated([
            'name' => '変更後',
            'calendar_id' => null,
            'retired_attribute' => 'ignored',
        ], (string) Str::uuid()))->setAggregateRootUuid($workStyle->id);

        app(WorkStyleProjector::class)->onWorkStyleUpdated($event);

        $workStyle->refresh();
        $this->assertSame('変更後', $workStyle->name);
        $this->assertNull($workStyle->company_calendar_id);
    }
}
