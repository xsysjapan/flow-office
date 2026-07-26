<?php

namespace App\Models;

/**
 * expense_claims.status の許容値 (docs/30-usecases-expense.md「申請・承認」節)。
 */
final class ExpenseClaimStatus
{
    public const DRAFT = 'draft';

    public const IN_REVIEW = 'in_review';

    public const RETURNED = 'returned';

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    /**
     * 明細の追加・修正・削除ができるステータス(下書き、または差戻し後の再編集)。
     *
     * @return array<int, string>
     */
    public static function editable(): array
    {
        return [self::DRAFT, self::RETURNED];
    }

    /**
     * 取消可能なステータス。
     *
     * @return array<int, string>
     */
    public static function cancellable(): array
    {
        return [self::DRAFT, self::IN_REVIEW];
    }
}
