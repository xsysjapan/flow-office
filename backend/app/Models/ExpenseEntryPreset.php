<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 「経費精算機能 設計・実装指示書」9〜10: 入力プリセット。visibilityの違いのみで
 * personal/company/systemを表現し、テーブル・振る舞いを分けない。
 */
#[Fillable([
    'visibility', 'owner_user_id', 'name', 'description', 'preset_type', 'definition',
    'is_active', 'usage_count', 'last_used_at', 'created_by',
])]
class ExpenseEntryPreset extends Model
{
    public const VISIBILITY_PERSONAL = 'personal';

    public const VISIBILITY_COMPANY = 'company';

    public const VISIBILITY_SYSTEM = 'system';

    public const TYPE_SINGLE_ITEM = 'single_item';

    public const TYPE_MULTIPLE_ITEMS = 'multiple_items';

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
