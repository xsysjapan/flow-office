<?php

namespace App\Models;

/**
 * request_center_items.request_type の許容値。
 */
final class RequestCenterItemType
{
    public const PAID_LEAVE = 'paid_leave';

    public const COMPENSATORY_LEAVE = 'compensatory_leave';

    public const EXPENSE_CLAIM = 'expense_claim';

    public const WORKFLOW = 'workflow';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::PAID_LEAVE, self::COMPENSATORY_LEAVE, self::EXPENSE_CLAIM, self::WORKFLOW];
    }
}
