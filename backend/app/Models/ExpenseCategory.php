<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * UC-X001: 経費区分マスタ。証憑タイプの既定値・レシート必須しきい値・承認省略しきい値を
 * ここで設定し、区分追加のためにコードを変更しない(docs/30-usecases-expense.md)。
 */
#[Fillable([
    'code', 'name', 'description', 'entry_mode', 'evidence_type_default',
    'receipt_required_threshold', 'approval_skip_threshold', 'is_active',
])]
class ExpenseCategory extends Model
{
    public const EVIDENCE_FACT_REFERENCE_AVAILABLE = 'fact_reference_available';

    public const EVIDENCE_RECEIPT_REQUIRED = 'receipt_required';

    public const EVIDENCE_RECEIPT_OPTIONAL = 'receipt_optional';

    public const ENTRY_MODE_BATCH = 'batch';

    public const ENTRY_MODE_SINGLE = 'single';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
