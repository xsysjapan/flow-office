<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Models\ExpenseEntryPreset;
use Illuminate\Database\Seeder;

/**
 * 「経費精算機能 設計・実装指示書」26: システム標準プリセットの初期データ。
 * 交通費は移動区間テンプレートを廃止しプリセット入力に一本化したため、まずは
 * 交通費区分の代表的な利用パターンを中心に用意する。
 */
class ExpenseEntryPresetSeeder extends Seeder
{
    public function run(): void
    {
        $categoryIdByCode = ExpenseCategory::query()->pluck('id', 'code');
        if (! isset($categoryIdByCode['transportation'])) {
            return;
        }

        $transportationId = $categoryIdByCode['transportation'];

        $presets = [
            [
                'name' => '電車による顧客訪問',
                'description' => '顧客先への訪問で利用した電車代を記録します。',
                'preset_type' => ExpenseEntryPreset::TYPE_SINGLE_ITEM,
                'definition' => [
                    ['category_id' => $transportationId, 'description' => '顧客訪問', 'payment_bearer' => 'employee'],
                ],
            ],
            [
                'name' => '電車往復',
                'description' => '往復分の電車代をまとめて記録します。',
                'preset_type' => ExpenseEntryPreset::TYPE_SINGLE_ITEM,
                'definition' => [
                    ['category_id' => $transportationId, 'description' => '往復(電車)', 'payment_bearer' => 'employee'],
                ],
            ],
            [
                'name' => '新幹線による日帰り出張',
                'description' => '日帰り出張の新幹線代を記録します。',
                'preset_type' => ExpenseEntryPreset::TYPE_SINGLE_ITEM,
                'definition' => [
                    ['category_id' => $transportationId, 'description' => '日帰り出張(新幹線)', 'payment_bearer' => 'employee'],
                ],
            ],
            [
                'name' => '国内1泊出張',
                'description' => '往路・宿泊・復路をまとめて下書きします。',
                'preset_type' => ExpenseEntryPreset::TYPE_MULTIPLE_ITEMS,
                'definition' => array_values(array_filter([
                    ['category_id' => $transportationId, 'description' => '出張・往路', 'content' => '出張・往路', 'payment_bearer' => 'employee'],
                    isset($categoryIdByCode['lodging'])
                        ? ['category_id' => $categoryIdByCode['lodging'], 'description' => '出張・宿泊', 'content' => '出張・宿泊', 'payment_bearer' => 'employee']
                        : null,
                    ['category_id' => $transportationId, 'description' => '出張・復路', 'content' => '出張・復路', 'payment_bearer' => 'employee'],
                ])),
            ],
            [
                'name' => 'タクシー利用',
                'description' => '公共交通機関の利用が難しい場合のタクシー代を記録します。',
                'preset_type' => ExpenseEntryPreset::TYPE_SINGLE_ITEM,
                'definition' => [
                    ['category_id' => $transportationId, 'description' => 'タクシー利用', 'payment_bearer' => 'employee'],
                ],
            ],
            [
                'name' => '深夜タクシー',
                'description' => '業務終了後の深夜帰宅時のタクシー代を記録します。領収書の添付を忘れずに。',
                'preset_type' => ExpenseEntryPreset::TYPE_SINGLE_ITEM,
                'definition' => [
                    ['category_id' => $transportationId, 'description' => '深夜タクシー(業務終了後の帰宅)', 'payment_bearer' => 'employee'],
                ],
            ],
            [
                'name' => 'コインパーキング',
                'description' => '顧客訪問・出張時の駐車場代を記録します。',
                'preset_type' => ExpenseEntryPreset::TYPE_SINGLE_ITEM,
                'definition' => [
                    ['category_id' => $transportationId, 'description' => 'コインパーキング', 'payment_bearer' => 'employee'],
                ],
            ],
            [
                'name' => '高速道路料金',
                'description' => '業務移動時の高速道路料金を記録します。',
                'preset_type' => ExpenseEntryPreset::TYPE_SINGLE_ITEM,
                'definition' => [
                    ['category_id' => $transportationId, 'description' => '高速道路料金', 'payment_bearer' => 'employee'],
                ],
            ],
        ];

        if (isset($categoryIdByCode['meal'])) {
            $presets[] = [
                'name' => '顧客との会食',
                'description' => '接待・関係構築目的の会食費を記録します。',
                'preset_type' => ExpenseEntryPreset::TYPE_SINGLE_ITEM,
                'definition' => [
                    ['category_id' => $categoryIdByCode['meal'], 'description' => '顧客との会食', 'content' => '顧客との会食', 'payment_bearer' => 'employee'],
                ],
            ];
        }

        if (isset($categoryIdByCode['supplies'])) {
            $presets[] = [
                'name' => '文房具購入',
                'description' => '業務に必要な文房具・消耗品の購入費を記録します。',
                'preset_type' => ExpenseEntryPreset::TYPE_SINGLE_ITEM,
                'definition' => [
                    ['category_id' => $categoryIdByCode['supplies'], 'description' => '文房具購入', 'content' => '文房具購入', 'payment_bearer' => 'employee'],
                ],
            ];
        }

        foreach ($presets as $preset) {
            ExpenseEntryPreset::query()->firstOrCreate(
                ['visibility' => ExpenseEntryPreset::VISIBILITY_SYSTEM, 'name' => $preset['name']],
                [
                    'description' => $preset['description'],
                    'preset_type' => $preset['preset_type'],
                    'definition' => $preset['definition'],
                    'is_active' => true,
                ],
            );
        }
    }
}
