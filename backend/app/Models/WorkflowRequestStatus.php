<?php

namespace App\Models;

/**
 * workflow_requests.status の許容値。
 */
final class WorkflowRequestStatus
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    /**
     * 却下(終端状態。編集・再提出不可)。spec 論点2-2。全申請種別で共通利用可能な
     * 汎用機能だが、現時点では備品貸出申請(asset_loan)のみが却下ボタンをUIに露出する。
     */
    public const REJECTED = 'rejected';

    /**
     * 取消可能なステータス (UC-W005)。REJECTEDは終端状態であり、却下という行為自体を
     * 取り消す操作は設けない(ルートCLAUDE.md「絶対に外してはいけない設計原則」13番)ため
     * 含めない。
     *
     * @return array<int, string>
     */
    public static function cancellable(): array
    {
        return [self::DRAFT, self::SUBMITTED, self::RETURNED];
    }
}
