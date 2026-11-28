<?php

namespace App\Domain\EventSourcing;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 一括のHandlerが使う補助。対象のIDを並べ替えて1件ずつ実行し、件の失敗(業務ルール違反・検証・
 * 対象なし・認可の拒否)だけを集める。QueryException・DeadlockException・Error等はそのまま投げ直す。
 * 追加のトランザクションは張らない(各件はCommandBusのdispatch=セーブポイントの中で行う想定)。
 * 失敗が1件でもあればBulkOperationRejectedを投げる(全体はHandlerのトランザクションごと戻る)。
 *
 * 使い方:
 *   $result = BulkItemRunner::run($ids, function ($id) use ($bus) {
 *       if ($alreadyDone) { return false; }   // 連鎖で済んだ件は成功として飛ばす
 *       $bus->dispatch(new XxxCommand($id));
 *   }, fn ($id) => $titles[$id] ?? null);
 *
 * @phpstan-type Id int|string
 */
class BulkItemRunner
{
    /**
     * @param  iterable<int|string>  $ids  重複は除く
     * @param  callable(int|string): mixed  $callback  falseを返すと「飛ばした件」(成功扱い)
     * @param  (callable(int|string): ?string)|null  $labelResolver  失敗の表示名(任意)
     * @return array{processed: list<int|string>, skipped: list<int|string>}
     */
    public static function run(iterable $ids, callable $callback, ?callable $labelResolver = null): array
    {
        $sorted = self::sortIds($ids);
        $processed = [];
        $skipped = [];
        $failures = [];

        foreach ($sorted as $id) {
            try {
                $returned = $callback($id);
            } catch (DomainRuleException|ValidationException|ModelNotFoundException|AuthorizationException $e) {
                $failure = ['id' => $id];
                $label = $labelResolver !== null ? $labelResolver($id) : null;
                if ($label !== null) {
                    $failure['label'] = $label;
                }
                $failure['message'] = self::messageOf($e);
                $failures[] = $failure;

                continue;
            }

            if ($returned === false) {
                $skipped[] = $id;
            } else {
                $processed[] = $id;
            }
        }

        if ($failures !== []) {
            throw new BulkOperationRejected($failures);
        }

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * 重複を除き、数値のIDは数値順、それ以外は文字列順に並べる(ロックの順序をそろえる)。
     *
     * @param  iterable<int|string>  $ids
     * @return list<int|string>
     */
    public static function sortIds(iterable $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $unique[(string) $id] = $id;
        }
        $list = array_values($unique);

        usort($list, function (int|string $a, int|string $b): int {
            $aNumeric = is_int($a) || ctype_digit($a);
            $bNumeric = is_int($b) || ctype_digit($b);
            if ($aNumeric && $bNumeric) {
                return (int) $a <=> (int) $b;
            }

            return strcmp((string) $a, (string) $b);
        });

        return $list;
    }

    private static function messageOf(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $messages = [];
            foreach ($e->errors() as $fieldMessages) {
                foreach ($fieldMessages as $message) {
                    $messages[] = $message;
                }
            }

            return $messages !== [] ? implode(' ', $messages) : $e->getMessage();
        }
        if ($e instanceof ModelNotFoundException) {
            return '対象が見つかりません。';
        }
        if ($e instanceof AuthorizationException) {
            return $e->getMessage() !== '' ? $e->getMessage() : 'この操作を行う権限がありません。';
        }

        return $e->getMessage();
    }
}
