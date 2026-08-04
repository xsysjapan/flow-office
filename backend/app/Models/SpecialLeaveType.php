<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 特別休暇の名前付き種別マスタ(例: 誕生日休暇、慶弔休暇、代休)。
 * 有効な種別が1件も無い場合、フロントエンドの特別休暇メニューは表示しない。
 *
 * `requires_grant`がfalseの種別(忌引・代休等)は、事前の付与(残数)が無くても申請できる
 * (`RequestSpecialLeaveHandler`参照)。
 */
#[Fillable(['name', 'is_active', 'requires_grant'])]
class SpecialLeaveType extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_grant' => 'boolean',
        ];
    }
}
