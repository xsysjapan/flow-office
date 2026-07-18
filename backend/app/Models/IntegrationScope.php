<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['integration_id', 'scope'])]
class IntegrationScope extends Model
{
    /**
     * @return BelongsTo<ApplicationIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(ApplicationIntegration::class, 'integration_id');
    }
}
