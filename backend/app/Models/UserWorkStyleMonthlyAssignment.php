<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ユーザーの月次働き方割当 (docs/08-usecases-calendar-shift.md)。
 * 月ごとに働き方(work_style)を切り替えても過去月の履歴が残るようにするための正データ。
 */
#[Fillable(['user_id', 'year_month', 'work_style_id', 'assigned_by_user_id'])]
class UserWorkStyleMonthlyAssignment extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<WorkStyle, $this>
     */
    public function workStyle(): BelongsTo
    {
        return $this->belongsTo(WorkStyle::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
