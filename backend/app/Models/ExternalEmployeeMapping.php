<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * flow-officeのuser_idと外部連携先(freee/moneyforward)側の従業員番号の対応表。
 * docs/33-usecases-attendance-external-api.md参照。
 */
#[Fillable(['id', 'provider', 'user_id', 'external_employee_code'])]
class ExternalEmployeeMapping extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
