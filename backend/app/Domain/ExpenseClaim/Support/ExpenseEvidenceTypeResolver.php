<?php

namespace App\Domain\ExpenseClaim\Support;

use App\Models\ExpenseCategory;

/**
 * UC-X001 手順3〜4: 経費区分の証憑タイプ既定値とレシート必須しきい値から、
 * 明細1件ごとの証憑タイプを決定する。しきい値を超える金額は、区分の既定値が
 * receipt_optionalであってもreceipt_requiredへ引き上げる(区分マスタの設定のみで
 * 判定し、コード側に金額基準をハードコードしない)。
 */
final class ExpenseEvidenceTypeResolver
{
    public static function resolve(ExpenseCategory $category, int $amount, ?string $requestedEvidenceType): string
    {
        $evidenceType = $requestedEvidenceType ?? $category->evidence_type_default;

        if (
            $category->receipt_required_threshold !== null
            && $amount > $category->receipt_required_threshold
            && $evidenceType !== ExpenseCategory::EVIDENCE_FACT_REFERENCE_AVAILABLE
        ) {
            return ExpenseCategory::EVIDENCE_RECEIPT_REQUIRED;
        }

        return $evidenceType;
    }
}
