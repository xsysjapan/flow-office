<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 設置備品の設置/保管場所の現在値+履歴 (spec 論点5)。`ended_at`がnullの行が現在有効な
 * 場所を表す区間。設置・移設・撤去のたびに現在行を終了させ新しい行を追加する。
 */
#[Fillable(['asset_id', 'location_text', 'started_at', 'ended_at', 'started_by_user_id', 'ended_by_user_id'])]
class AssetPlacement extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
