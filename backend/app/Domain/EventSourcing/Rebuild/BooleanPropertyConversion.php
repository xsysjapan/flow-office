<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 1行分の変換結果(`BooleanPropertyConverter::convert()`)。
 *
 * - newJson: 変換後の event_properties(変更が無ければnull)
 * - changes: 変更の説明(例: `allowOffline=false → allowsOffline=false`、`(なし) → isOffline=false(補完)`)
 * - filled: 旧キーが無く既定値で新キーを補ったか
 * - unfillable: 補う値の無い必須の新キー(トップレベル)が、旧キーも無いため欠けている場合の説明(変換しない。人の判断に回す)
 * - conflict: 旧キーと新キーが両方ある場合の説明(あれば変換しない)
 */
final class BooleanPropertyConversion
{
    /** @param  list<string>  $changes */
    public function __construct(
        public readonly ?string $newJson,
        public readonly array $changes,
        public readonly bool $filled,
        public readonly ?string $conflict,
        public readonly ?string $unfillable = null,
    ) {}

    /** 書き換えが必要な行(変換するか、止める必要がある)。 */
    public function needsAttention(): bool
    {
        return $this->newJson !== null || $this->conflict !== null || $this->unfillable !== null;
    }
}
