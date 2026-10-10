<?php

namespace Tests\Unit\CompensatoryLeaveAccount;

use App\Domain\CompensatoryLeaveAccount\Support\CompensatoryLeaveGrantConversion;
use Tests\TestCase;

/**
 * CompensatoryLeaveGrantConversionの単体テスト(純粋関数。Eloquent・SystemSettingは使わない)。
 * 移植元: App\Domain\CompensatoryLeave\Services\CompensatoryLeaveGrantCalculator::resolveGrantedAmount
 */
class CompensatoryLeaveGrantConversionTest extends TestCase
{
    private CompensatoryLeaveGrantConversion $conversion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversion = new CompensatoryLeaveGrantConversion;
    }

    public function test_daily_unit_grants_one_day_regardless_of_minutes(): void
    {
        $this->assertSame([1.0, null], $this->conversion->resolve('daily', null, 480));
        $this->assertSame([1.0, null], $this->conversion->resolve('daily', 240, 60));
    }

    public function test_unset_unit_is_treated_as_daily(): void
    {
        $this->assertSame([1.0, null], $this->conversion->resolve(null, null, 480));
    }

    public function test_unknown_unit_is_treated_as_daily(): void
    {
        $this->assertSame([1.0, null], $this->conversion->resolve('unknown', 240, 60));
    }

    public function test_half_day_at_threshold_grants_half_day(): void
    {
        // 半日しきい値ちょうどは「超える」を満たさないため0.5日。
        $this->assertSame([0.5, null], $this->conversion->resolve('half_day', 240, 240));
    }

    public function test_half_day_just_above_threshold_grants_full_day(): void
    {
        $this->assertSame([1.0, null], $this->conversion->resolve('half_day', 240, 241));
    }

    public function test_half_day_just_below_threshold_grants_half_day(): void
    {
        $this->assertSame([0.5, null], $this->conversion->resolve('half_day', 240, 239));
    }

    public function test_half_day_without_threshold_treats_threshold_as_zero(): void
    {
        $this->assertSame([1.0, null], $this->conversion->resolve('half_day', null, 1));
        $this->assertSame([0.5, null], $this->conversion->resolve('half_day', null, 0));
    }

    public function test_hourly_unit_grants_minutes_only(): void
    {
        $this->assertSame([0.0, 90], $this->conversion->resolve('hourly', null, 90));
    }

    public function test_zero_minutes(): void
    {
        $this->assertSame([1.0, null], $this->conversion->resolve('daily', null, 0));
        $this->assertSame([0.5, null], $this->conversion->resolve('half_day', 240, 0));
        $this->assertSame([0.0, 0], $this->conversion->resolve('hourly', null, 0));
    }
}
