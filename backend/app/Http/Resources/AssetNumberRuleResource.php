<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 管理番号自動採番ルール(カテゴリ別 or デフォルト)のレスポンス整形。
 */
class AssetNumberRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'prefix' => $this->prefix,
            'digit_count' => $this->digit_count,
            'next_number' => $this->next_number,
            'enabled' => $this->enabled,
            'is_default' => $this->is_default,
        ];
    }
}
