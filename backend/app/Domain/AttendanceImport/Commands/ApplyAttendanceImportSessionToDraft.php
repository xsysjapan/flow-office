<?php

namespace App\Domain\AttendanceImport\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-R001手順8: 差異のない日を一括で下書きへ反映する。差異のある日は反映するが、
 * field_provenanceは未確認(ai_inferred)のまま残す(docs/26「不明点の確認」)。
 */
class ApplyAttendanceImportSessionToDraft implements Command
{
    public function __construct(
        public readonly int $sessionId,
        public readonly ?int $draftId,
        public readonly int $appliedByUserId,
    ) {}
}
