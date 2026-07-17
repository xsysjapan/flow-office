<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 特別休暇の名前付き種別マスタ(例: 誕生日休暇、慶弔休暇)。
 * 有効な種別が1件も無い場合、フロントエンドの特別休暇メニューは表示しない。
 */
#[Fillable(['name', 'is_active'])]
class SpecialLeaveType extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
