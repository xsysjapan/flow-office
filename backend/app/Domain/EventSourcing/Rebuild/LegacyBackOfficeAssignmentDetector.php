<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Support\Facades\DB;

/**
 * 旧形式の backoffice_task.assigned(payload に previousAssignedUserId キーが無い)の件数を数える
 * (docs/changesets/20261122-backoffice-assignment-migration-admin/spec.md 論点5)。
 * event_class の行だけを読み、PHPで array_key_exists する(JSON式はDBの違いがあるため使わない)。読み取りのみ。
 */
final class LegacyBackOfficeAssignmentDetector
{
    private const CHUNK = 500;

    public function count(): int
    {
        $count = 0;
        DB::table('stored_events')
            ->where('event_class', BackOfficeTaskAssignmentSplitter::ASSIGNED)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$count): void {
                foreach ($chunk as $row) {
                    $payload = json_decode((string) $row->event_properties, true);
                    if (! is_array($payload) || ! array_key_exists('previousAssignedUserId', $payload)) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
