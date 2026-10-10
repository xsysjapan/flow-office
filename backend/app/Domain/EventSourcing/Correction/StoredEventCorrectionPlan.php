<?php

namespace App\Domain\EventSourcing\Correction;

use InvalidArgumentException;

/**
 * 1回の直接修正の計画: 補正キー(冪等性と補正ログの単位)と、対象のstored_events行への操作の一覧。
 *
 * 補正キーは同じ計画を再実行しても修正済みの行を再修正しないための識別子(例: 'leave-correction-candidate-3-20261010')。
 */
final class StoredEventCorrectionPlan
{
    /**
     * @param  list<StoredEventRewrite>  $rewrites
     */
    public function __construct(
        public readonly string $correctionKey,
        public readonly string $description,
        public readonly array $rewrites,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/', $correctionKey) !== 1) {
            throw new InvalidArgumentException('補正キーは英小文字・数字・._- の64文字以内で指定してください。');
        }
        if (trim($description) === '') {
            throw new InvalidArgumentException('補正の説明は必須です。');
        }

        $ids = [];
        foreach ($rewrites as $rewrite) {
            if (! $rewrite instanceof StoredEventRewrite) {
                throw new InvalidArgumentException('rewritesはStoredEventRewriteの一覧で指定してください。');
            }
            if (isset($ids[$rewrite->storedEventId])) {
                throw new InvalidArgumentException("同じstored_events.id={$rewrite->storedEventId}を複数回指定しています。");
            }
            $ids[$rewrite->storedEventId] = true;
        }
    }
}
