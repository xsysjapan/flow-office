<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Support\Facades\DB;

/**
 * 旧項目名(または、改名で必須にした項目の欠落)のままの stored_events の行を数える
 * (docs/changesets/20261123-boolean-column-naming/spec.md 論点5)。変換が必要な行(`BooleanPropertyConverter`が書き換える行、
 * または旧キーと新キーが両方ある行)の件数。読み取りのみ(JSON式はDBの違いがあるため使わず、PHPで判定する)。
 */
final class LegacyBooleanPropertyDetector
{
    private const CHUNK = 500;

    public function count(): int
    {
        $converter = new BooleanPropertyConverter;
        $count = 0;
        DB::table('stored_events')
            ->whereIn('event_class', BooleanPropertyConverter::eventClasses())
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use ($converter, &$count): void {
                foreach ($chunk as $row) {
                    if ($converter->convert((string) $row->event_class, (string) $row->event_properties)->needsAttention()) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
