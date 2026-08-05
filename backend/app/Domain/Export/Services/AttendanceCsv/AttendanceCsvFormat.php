<?php

namespace App\Domain\Export\Services\AttendanceCsv;

use App\Models\AttendanceMonth;

/**
 * UC-E001: 勤怠CSV出力フォーマットの共通インターフェース。
 * ExportController::attendance()はformatクエリパラメータで解決したこの実装を使って
 * ヘッダー行・データ行を組み立てる。
 */
interface AttendanceCsvFormat
{
    /**
     * @return array<int, string>
     */
    public function header(): array;

    /**
     * @return array<int, string|int|float>
     */
    public function row(AttendanceMonth $month, string $yearMonth): array;

    public function delimiter(): string;

    /** 'UTF-8' または 'SJIS-win'。fputcsvで書いたCSV文字列に対してmb_convert_encodingする際に使う。 */
    public function encoding(): string;

    public function fileExtension(): string;
}
