<?php

namespace App\Domain\Workflow\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-W002: 社員が申請する(下書き保存)。
 *
 * 汎用申請(request_types由来)と、他ドメインを対象とする申請(月次勤怠・経費精算)の
 * どちらもこのコマンドで下書きを作る。呼び出し側は`requestTypeCode`か
 * `subjectType`/`subjectId`のどちらか一方だけを渡す想定。
 */
class DraftWorkflowRequest implements Command
{
    /**
     * @param  ?string  $requestTypeCode  汎用申請の申請種別コード。`subjectType`を指定した場合は
     *                                    無視され、申請種別の検索・申請権限チェックも行われない。
     * @param  array<string, mixed>  $formData
     * @param  ?string  $subjectType  他ドメインを対象とする申請の種別(`attendance_month` /
     *                                `expense_claim`)。指定時は`requestTypeCode`より優先される。
     * @param  ?string  $subjectId  対象ドメインの正データのID(attendance_months.id /
     *                              expense_claims.id)。
     */
    public function __construct(
        public readonly ?string $requestTypeCode,
        public readonly string $applicantUserId,
        public readonly string $title,
        public readonly array $formData,
        public readonly ?string $approverUserId = null,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
    ) {}
}
