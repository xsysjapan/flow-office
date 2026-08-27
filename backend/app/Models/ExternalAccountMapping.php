<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * MoneyForwardクラウド経費API(ex_transactions)向け: `expense_categories.account_code`/
 * `tax_category`から、MoneyForward内部の管理ID(ex_item_id/dr_excise_id)への対応表。
 * docs/notes/moneyforward-api-investigation.md参照。
 */
#[Fillable(['id', 'provider', 'mapping_type', 'source_code', 'external_id'])]
class ExternalAccountMapping extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const TYPE_EX_ITEM = 'ex_item';

    public const TYPE_DR_EXCISE = 'dr_excise';

    /**
     * 指定providerの account_code/tax_category → MoneyForward内部ID マッピングを
     * mapping_type別に取得する。
     *
     * @return array<string, string> source_code => external_id
     */
    public static function mapFor(string $provider, string $mappingType): array
    {
        return self::query()
            ->where('provider', $provider)
            ->where('mapping_type', $mappingType)
            ->get()
            ->pluck('external_id', 'source_code')
            ->all();
    }
}
