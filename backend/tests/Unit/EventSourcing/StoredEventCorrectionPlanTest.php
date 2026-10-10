<?php

namespace Tests\Unit\EventSourcing;

use App\Domain\EventSourcing\Correction\StoredEventCorrectionPlan;
use App\Domain\EventSourcing\Correction\StoredEventRewrite;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 直接修正の計画・操作の業務ルール(補正キーの形式、対象の重複、操作と書き換え内容の組み合わせ)の検証。
 * DBを使わない(枠組み全体の検証はtests/Feature/EventSourcing/StoredEventCorrectorTest.php)。
 */
class StoredEventCorrectionPlanTest extends TestCase
{
    public function test_a_rewrite_needs_the_new_payload_and_a_delete_must_not_have_one(): void
    {
        $rewrite = StoredEventRewrite::rewrite(1, ['workType' => null]);
        $this->assertSame(StoredEventRewrite::REWRITE, $rewrite->operation);
        $this->assertSame(['workType' => null], $rewrite->eventProperties);

        $delete = StoredEventRewrite::delete(2);
        $this->assertSame(StoredEventRewrite::DELETE, $delete->operation);
        $this->assertNull($delete->eventProperties);

        $this->expectException(InvalidArgumentException::class);
        new StoredEventRewrite(3, StoredEventRewrite::REWRITE, null);
    }

    public function test_a_delete_with_a_payload_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StoredEventRewrite(3, StoredEventRewrite::DELETE, ['x' => 1]);
    }

    public function test_an_unknown_operation_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StoredEventRewrite(3, 'insert', ['x' => 1]);
    }

    public function test_the_correction_key_must_be_a_short_slug(): void
    {
        $this->assertSame('leave-correction-1', (new StoredEventCorrectionPlan('leave-correction-1', '説明', []))->correctionKey);

        foreach (['', 'Upper', 'has space', str_repeat('a', 65)] as $key) {
            try {
                new StoredEventCorrectionPlan($key, '説明', []);
                $this->fail("補正キー '{$key}' は拒否されること");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_same_stored_event_cannot_be_targeted_twice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StoredEventCorrectionPlan('dup-target', '説明', [
            StoredEventRewrite::rewrite(7, ['a' => 1]),
            StoredEventRewrite::delete(7),
        ]);
    }

    public function test_a_plan_needs_a_description(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StoredEventCorrectionPlan('no-description', '  ', []);
    }
}
