<?php

namespace App\Domain\Attachment\Support;

/**
 * 添付ファイルの大きさ・拡張子の規則(添付の文脈の判定クラス)。ファイルを保存する前に、
 * 添付の単独のAPI(AttachmentController)と、経費の明細の追加の入口の両方から使う。
 * 違反の文言を返すだけで、例外・保存はしない(キーの付け方は入口が決める)。
 */
final class AttachmentFileRules
{
    /**
     * 申請種別マスタで大きさ・拡張子を設定できないownerの既定の上限(KB)。
     */
    public const DEFAULT_MAX_SIZE_KB = 10240;

    /**
     * UC-X005: 経費明細の領収書添付。expense_categoriesは申請種別マスタのような設定を持たないため、既定値のみを適用する。
     */
    public const EXPENSE_ITEM_ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    /**
     * @param  list<string>|null  $allowedExtensions  nullなら拡張子を制限しない
     * @return string|null 違反の文言。規則に合えばnull
     */
    public static function violation(int $maxSizeKb, ?array $allowedExtensions, int $fileSize, string $extension): ?string
    {
        if ($fileSize > $maxSizeKb * 1024) {
            return "ファイルサイズは{$maxSizeKb}KB以内にしてください。";
        }

        if ($allowedExtensions !== null
            && ! in_array(strtolower($extension), array_map('strtolower', $allowedExtensions), true)) {
            return '許可されていないファイル形式です(許可: '.implode(', ', $allowedExtensions).')。';
        }

        return null;
    }

    public static function expenseItemViolation(int $fileSize, string $extension): ?string
    {
        return self::violation(self::DEFAULT_MAX_SIZE_KB, self::EXPENSE_ITEM_ALLOWED_EXTENSIONS, $fileSize, $extension);
    }
}
