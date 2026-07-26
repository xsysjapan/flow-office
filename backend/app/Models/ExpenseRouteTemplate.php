<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UC-X002/UC-X003: 個人・全社共有の移動区間テンプレート。scopeの違いだけで表現し、
 * テーブル構造・振る舞いを分けない(docs/30-usecases-expense.md)。
 */
#[Fillable([
    'scope', 'employee_id', 'name', 'origin', 'destination',
    'transport_type', 'amount', 'category_id', 'created_by', 'is_active',
])]
class ExpenseRouteTemplate extends Model
{
    public const SCOPE_PERSONAL = 'personal';

    public const SCOPE_COMPANY = 'company';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
