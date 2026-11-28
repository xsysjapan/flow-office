<?php

namespace Tests\Unit\EventSourcing;

use App\Domain\EventSourcing\BulkItemRunner;
use App\Domain\EventSourcing\BulkOperationRejected;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BulkItemRunnerTest extends TestCase
{
    public function test_all_succeed_in_sorted_distinct_order(): void
    {
        $seen = [];
        $result = BulkItemRunner::run([10, 2, 2, 1], function ($id) use (&$seen) {
            $seen[] = $id;
        });

        $this->assertSame([1, 2, 10], $seen);
        $this->assertSame([1, 2, 10], $result['processed']);
        $this->assertSame([], $result['skipped']);
    }

    public function test_sorts_uuid_strings(): void
    {
        $this->assertSame(['a', 'b', 'c'], BulkItemRunner::sortIds(['c', 'a', 'b', 'a']));
    }

    public function test_false_return_is_treated_as_skipped(): void
    {
        $result = BulkItemRunner::run([1, 2], fn ($id) => $id === 1 ? false : null);

        $this->assertSame([2], $result['processed']);
        $this->assertSame([1], $result['skipped']);
    }

    public function test_collects_all_item_failures_and_throws(): void
    {
        $executed = [];
        try {
            BulkItemRunner::run([1, 2, 3, 4, 5], function ($id) use (&$executed) {
                $executed[] = $id;
                match ($id) {
                    1 => throw new DomainRuleException('業務ルール違反'),
                    2 => throw ValidationException::withMessages(['a' => ['検証A', '検証B'], 'b' => ['検証C']]),
                    3 => throw new ModelNotFoundException,
                    4 => throw new AuthorizationException('権限なし'),
                    default => null,
                };
            }, fn ($id) => $id === 1 ? 'タスク1' : null);
            $this->fail('BulkOperationRejected expected');
        } catch (BulkOperationRejected $e) {
            $this->assertSame([1, 2, 3, 4, 5], $executed);
            $this->assertSame([
                ['id' => 1, 'label' => 'タスク1', 'message' => '業務ルール違反'],
                ['id' => 2, 'message' => '検証A 検証B 検証C'],
                ['id' => 3, 'message' => '対象が見つかりません。'],
                ['id' => 4, 'message' => '権限なし'],
            ], $e->failures());
        }
    }

    public function test_other_exceptions_are_rethrown(): void
    {
        $this->expectException(QueryException::class);

        BulkItemRunner::run([1, 2], function ($id) {
            if ($id === 1) {
                throw new DomainRuleException('x');
            }
            throw new QueryException('sqlite', 'select 1', [], new \Exception('deadlock'));
        });
    }

    public function test_errors_are_rethrown(): void
    {
        $this->expectException(\TypeError::class);

        BulkItemRunner::run([1], function () {
            throw new \TypeError('bug');
        });
    }
}
