<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rule_id', 'grant_category', 'continuous_service_months', 'grant_days'])]
class PaidLeaveGrantRuleStep extends Model
{
    public const CATEGORY_NORMAL = 'normal';

    public const CATEGORY_PROPORTIONAL_4 = 'proportional_4';

    public const CATEGORY_PROPORTIONAL_3 = 'proportional_3';

    public const CATEGORY_PROPORTIONAL_2 = 'proportional_2';

    public const CATEGORY_PROPORTIONAL_1 = 'proportional_1';

    /**
     * 付与日数の表の区分(通常と、比例付与の週所定日数4/3/2/1日)。
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        self::CATEGORY_NORMAL,
        self::CATEGORY_PROPORTIONAL_4,
        self::CATEGORY_PROPORTIONAL_3,
        self::CATEGORY_PROPORTIONAL_2,
        self::CATEGORY_PROPORTIONAL_1,
    ];

    /**
     * @return BelongsTo<PaidLeaveGrantRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(PaidLeaveGrantRule::class, 'rule_id');
    }
}
