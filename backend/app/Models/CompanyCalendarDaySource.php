<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 会社カレンダー日の生成元履歴 (docs/16-database-schema.md company_calendar_day_sources、
 * UC-C010)。1つのcompany_calendar_dayに複数の生成元履歴が残りうる
 * (標準生成→祝日同期→手動変更、等)。書き込み(祝日同期・一括操作)は次のタスクで実装する。
 */
#[Fillable(['id', 'company_calendar_day_id', 'source_type', 'source_ref', 'applied_at', 'applied_by_user_id'])]
class CompanyCalendarDaySource extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CompanyCalendarDay, $this>
     */
    public function companyCalendarDay(): BelongsTo
    {
        return $this->belongsTo(CompanyCalendarDay::class, 'company_calendar_day_id');
    }
}
