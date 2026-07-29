<?php

namespace App\Domain\ExpenseClaim\Support;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\ExpenseCategory;

/**
 * 「経費精算機能 設計・実装指示書」6.5/7.2: expense_items.attributesに保存できるキーは
 * expense_categories.field_definitionsで定義したものだけに限定する。任意の無秩序なJSONとして
 * 扱わないための最小限の検証(型までは見ず、キーの許可リストと必須キーの存在確認のみ)。
 */
final class ExpenseItemAttributesValidator
{
    /**
     * @param  array<string, mixed>|null  $attributes
     * @return array<string, mixed>|null
     */
    public static function validate(ExpenseCategory $category, ?array $attributes): ?array
    {
        $allowedKeys = $category->attributeKeys();

        if ($allowedKeys === null) {
            return $attributes;
        }

        $attributes ??= [];

        $unknownKeys = array_diff(array_keys($attributes), $allowedKeys);
        if ($unknownKeys !== []) {
            throw new DomainRuleException(
                '経費区分「'.$category->name.'」で許可されていない項目です: '.implode(', ', $unknownKeys),
            );
        }

        $missingKeys = array_filter(
            $category->requiredAttributeKeys(),
            fn (string $key) => ! array_key_exists($key, $attributes) || $attributes[$key] === null || $attributes[$key] === '',
        );
        if ($missingKeys !== []) {
            throw new DomainRuleException(
                '経費区分「'.$category->name.'」で必須の項目が未入力です: '.implode(', ', $missingKeys),
            );
        }

        return $attributes === [] ? null : $attributes;
    }
}
