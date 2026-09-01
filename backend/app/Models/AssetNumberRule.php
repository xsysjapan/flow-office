<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 備品管理番号の自動採番ルール(カテゴリ別 or デフォルト)。
 * `category`がNULLの行はデフォルトルール(`is_default=true`)であり、最大1件のみ存在する
 * (アプリ層で保証。docs/changesets/20260831-asset-management-refinement/spec.md 論点10)。
 *
 * このマスタ自体は`stored_events`を正とする対象外(Eloquentモデルが正)だが、
 * 作成・変更・連番払い出しは監査目的で`App\Domain\AssetNumbering`配下のCommand/Event経由で
 * `stored_events`にも記録する(ルートCLAUDE.mdの設計原則1の例外規定)。
 */
#[Fillable(['category', 'prefix', 'digit_count', 'next_number', 'enabled', 'is_default'])]
class AssetNumberRule extends Model
{
    protected function casts(): array
    {
        return [
            'digit_count' => 'integer',
            'next_number' => 'integer',
            'enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
