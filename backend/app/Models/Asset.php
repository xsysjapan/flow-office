<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 備品(貸出品・設置品) の Read Model (docs/26-usecases-asset-management.md 予定)。
 *
 * `management_type` により貸出品/設置品を分岐し、`lending_status`/`installation_status`は
 * 片方のみ有効な値を持つ(もう片方はnull)。主キーはUUID(HasUuids)。集約ID
 * (aggregate_id)としてstored_eventsに書き込まれるため、DB採番だと確定前に
 * AssetProjectorが行を作成できない(.claude/skills/add-projection「集約ルートのUUID化」参照)。
 */
#[Fillable([
    'id', 'asset_no', 'name', 'category', 'serial_number', 'management_type',
    'lending_status', 'installation_status', 'lending_method', 'default_location_text',
    'qr_token', 'current_loan_id', 'notes',
])]
class Asset extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public function loans(): HasMany
    {
        return $this->hasMany(AssetLoan::class);
    }

    public function defaultLocationChanges(): HasMany
    {
        return $this->hasMany(AssetDefaultLocationChange::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(AssetPlacement::class);
    }

    public function currentPlacement(): ?AssetPlacement
    {
        return $this->placements()->whereNull('ended_at')->latest('started_at')->first();
    }
}
