<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 交代制勤務のローテーションパターン(指示書 8.4節)。A勤・B勤・C勤・休のような
 * 繰り返し周期を1つの働き方の中でまとめて管理する。
 */
#[Fillable(['work_style_id', 'name', 'cycle_length'])]
class RotationPattern extends Model
{
    /**
     * @return BelongsTo<WorkStyle, $this>
     */
    public function workStyle(): BelongsTo
    {
        return $this->belongsTo(WorkStyle::class);
    }

    /**
     * @return HasMany<RotationPatternItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RotationPatternItem::class)->orderBy('sequence');
    }
}
