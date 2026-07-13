<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rotation_pattern_id', 'sequence', 'shift_pattern_id'])]
class RotationPatternItem extends Model
{
    /**
     * @return BelongsTo<RotationPattern, $this>
     */
    public function rotationPattern(): BelongsTo
    {
        return $this->belongsTo(RotationPattern::class);
    }

    /**
     * @return BelongsTo<ShiftPattern, $this>
     */
    public function shiftPattern(): BelongsTo
    {
        return $this->belongsTo(ShiftPattern::class);
    }
}
