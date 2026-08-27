<?php

namespace App\Domain\Export\Services\ExpenseCsv;

use App\Models\BackOfficeTask;
use App\Models\ExpenseClaim;

/**
 * UC-X012: 経費CSV出力フォーマットの共通インターフェース。AttendanceCsvFormatと同じ形。
 * ExportController::expenses()はformatクエリパラメータで解決したこの実装を使って
 * ヘッダー行・データ行を組み立てる。
 *
 * 1つの支払予定/完了タスク(経費精算1件)につき、明細(ExpenseItem)ごとに0件以上の行を
 * 返せるよう rows() は配列を返す(会計取込CSVは通常「明細=仕訳1行」のため)。
 */
interface ExpenseCsvFormat
{
    /**
     * @return array<int, string>
     */
    public function header(): array;

    /**
     * @return array<int, array<int, string|int|float>>
     */
    public function rows(BackOfficeTask $task, ExpenseClaim $claim): array;

    public function delimiter(): string;

    /** 'UTF-8' または 'SJIS-win'。fputcsvで書いたCSV文字列に対してmb_convert_encodingする際に使う。 */
    public function encoding(): string;

    public function fileExtension(): string;
}
