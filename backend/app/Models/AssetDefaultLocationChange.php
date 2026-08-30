<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 貸出備品の「通常配置場所」の変更履歴 (spec 論点5)。現在値は`assets.default_location_text`
 * に持ち、このテーブルは変更時にのみ追記する履歴のみを持つ(区間概念は持たない)。
 */
#[Fillable(['asset_id', 'location_text', 'changed_by_user_id', 'changed_at'])]
class AssetDefaultLocationChange extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
