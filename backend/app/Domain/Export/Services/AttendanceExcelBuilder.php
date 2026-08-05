<?php

namespace App\Domain\Export\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\DayClassification;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * UC-E001: 勤怠実績Excel出力(docs/14-usecases-export.md)。
 * ExportController::attendance()と同じデータソース(AttendanceMonth.snapshot_json /
 * AttendanceDay + AttendanceDailyCalculation)、同じ対象月抽出ロジック・権限チェックを流用し、
 * 見た目を整えた.xlsxファイルとして組み立てる。
 *
 * 対象社員1名分のワークブックは常に「月次サマリ」(1行)+「日別明細」の2シート構成
 * (buildForMonth())。対象社員が複数の場合、ExportController側でbuildForMonth()を
 * 社員ごとに呼び出し、ZIPにまとめて返す。
 */
class AttendanceExcelBuilder
{
    private const HEADER_FILL_COLOR = '1F2937';

    private const HEADER_FONT_COLOR = 'FFFFFF';

    private const ZEBRA_FILL_COLOR = 'F3F4F6';

    private const BORDER_COLOR = 'D1D5DB';

    /** Excelの経過時間表示(24時間を超えても「◯時間◯分」で表示する)。 */
    private const TIME_FORMAT = '[h]"時間"mm"分"';

    private const WEEKDAY_LABELS = ['日', '月', '火', '水', '木', '金', '土'];

    private const DAY_CLASSIFICATION_LABELS = [
        DayClassification::WORKING_DAY => '平日',
        DayClassification::PRESCRIBED_HOLIDAY => '所定休日',
        DayClassification::LEGAL_HOLIDAY => '法定休日',
    ];

    /**
     * 対象社員1名分のワークブックを組み立てる。「月次サマリ」(対象社員1行)+
     * 「日別明細」(対象月のAttendanceDay+AttendanceDailyCalculationを日付順に1日1行)の
     * 2シート構成。対象社員が複数いる場合はExportController側で社員ごとにこのメソッドを
     * 呼び出し、ZIPにまとめる。
     */
    public function buildForMonth(AttendanceMonth $month, string $yearMonth): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('勤怠実績 '.$yearMonth.' '.($month->user?->name ?? $month->user_id))
            ->setCreator('flow-office');

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('月次サマリ');
        $this->buildSummarySheet($summarySheet, collect([$month]), $yearMonth);

        $detailSheet = $spreadsheet->createSheet();
        $detailSheet->setTitle('日別明細');
        $this->buildDetailSheet($detailSheet, $month, $yearMonth);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * 対象月次が0件の場合(該当社員なし)に、空の「月次サマリ」シートのみのワークブックを
     * 返す。
     */
    public function buildEmpty(string $yearMonth): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('勤怠実績 '.$yearMonth)
            ->setCreator('flow-office');

        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('月次サマリ');
        $this->buildSummarySheet($summarySheet, collect(), $yearMonth);

        return $spreadsheet;
    }

    /**
     * @param  Collection<int, AttendanceMonth>  $months
     */
    private function buildSummarySheet(Worksheet $sheet, Collection $months, string $yearMonth): void
    {
        $headers = [
            '社員ID', '社員名', '対象月', '所定労働時間', '実労働時間',
            '法定内残業', '法定外残業', '深夜労働時間', '法定休日労働', '所定休日労働',
        ];
        $timeColumns = ['D', 'E', 'F', 'G', 'H', 'I', 'J'];

        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($sheet, 'A1:J1');

        $row = 2;
        foreach ($months as $month) {
            $snapshot = $month->snapshot_json ?? [];

            $sheet->setCellValue("A{$row}", $month->user_id);
            $sheet->setCellValue("B{$row}", $month->user?->name);
            $sheet->setCellValue("C{$row}", $yearMonth);
            $sheet->setCellValue("D{$row}", $this->minutesToExcelTime($snapshot['prescribed_work_minutes'] ?? 0));
            $sheet->setCellValue("E{$row}", $this->minutesToExcelTime($snapshot['work_minutes'] ?? 0));
            $sheet->setCellValue("F{$row}", $this->minutesToExcelTime($snapshot['statutory_within_overtime_minutes'] ?? 0));
            $sheet->setCellValue("G{$row}", $this->minutesToExcelTime($snapshot['statutory_excess_overtime_minutes'] ?? 0));
            $sheet->setCellValue("H{$row}", $this->minutesToExcelTime($snapshot['late_night_work_minutes'] ?? 0));
            $sheet->setCellValue("I{$row}", $this->minutesToExcelTime($snapshot['legal_holiday_work_minutes'] ?? 0));
            $sheet->setCellValue("J{$row}", $this->minutesToExcelTime($snapshot['prescribed_holiday_work_minutes'] ?? 0));
            $row++;
        }

        $lastRow = max($row - 1, 2);
        $sheet->getStyle('D2:J'.$lastRow)->getNumberFormat()->setFormatCode(self::TIME_FORMAT);
        foreach ($timeColumns as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        $this->applyZebraStripes($sheet, 2, $lastRow, 'A', 'J');
        $this->applyBorders($sheet, 'A1:J'.$lastRow);
        $this->autoSizeColumns($sheet, 'A', 'J');

        $sheet->setAutoFilter('A1:J1');
        $sheet->freezePane('A2');
    }

    private function buildDetailSheet(Worksheet $sheet, AttendanceMonth $month, string $yearMonth): void
    {
        $headers = ['日付', '曜日', '区分', '出勤時刻', '退勤時刻', '実働時間', '法定内残業', '法定外残業', '深夜時間', '休日労働', '備考'];
        $sheet->fromArray($headers, null, 'A1');
        $this->styleHeaderRow($sheet, 'A1:K1');

        $days = AttendanceDay::query()
            ->where('user_id', $month->user_id)
            ->whereBetween('work_date', [$yearMonth.'-01', date('Y-m-t', strtotime($yearMonth.'-01'))])
            ->with('calculation')
            ->orderBy('work_date')
            ->get();

        $row = 2;
        foreach ($days as $day) {
            $calculation = $day->calculation;
            $workDate = $day->work_date;

            $sheet->setCellValue("A{$row}", $workDate->toDateString());
            $sheet->setCellValue("B{$row}", self::WEEKDAY_LABELS[$workDate->dayOfWeek]);
            $sheet->setCellValue("C{$row}", self::DAY_CLASSIFICATION_LABELS[$day->day_classification] ?? ($day->work_type ?? ''));
            $sheet->setCellValue("D{$row}", $day->actual_start_at?->format('H:i') ?? '');
            $sheet->setCellValue("E{$row}", $day->actual_end_at?->format('H:i') ?? '');
            $sheet->setCellValue("F{$row}", $this->minutesToExcelTime($calculation?->work_minutes ?? 0));
            $sheet->setCellValue("G{$row}", $this->minutesToExcelTime($calculation?->statutory_within_overtime_minutes ?? 0));
            $sheet->setCellValue("H{$row}", $this->minutesToExcelTime($calculation?->statutory_excess_overtime_minutes ?? 0));
            $sheet->setCellValue("I{$row}", $this->minutesToExcelTime($calculation?->late_night_work_minutes ?? 0));
            $sheet->setCellValue("J{$row}", $this->minutesToExcelTime(($calculation?->legal_holiday_work_minutes ?? 0) + ($calculation?->prescribed_holiday_work_minutes ?? 0)));
            $sheet->setCellValue("K{$row}", $day->note ?? '');
            $row++;
        }

        $lastRow = max($row - 1, 2);
        $sheet->getStyle('F2:J'.$lastRow)->getNumberFormat()->setFormatCode(self::TIME_FORMAT);
        foreach (['D', 'E', 'F', 'G', 'H', 'I', 'J'] as $column) {
            $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        $this->applyZebraStripes($sheet, 2, $lastRow, 'A', 'K');
        $this->applyBorders($sheet, 'A1:K'.$lastRow);
        $this->autoSizeColumns($sheet, 'A', 'K');

        $sheet->setAutoFilter('A1:K1');
        $sheet->freezePane('A2');
    }

    private function styleHeaderRow(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::HEADER_FONT_COLOR]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL_COLOR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);
    }

    private function applyZebraStripes(Worksheet $sheet, int $firstRow, int $lastRow, string $firstColumn, string $lastColumn): void
    {
        for ($row = $firstRow; $row <= $lastRow; $row++) {
            if ($row % 2 === 1) {
                $sheet->getStyle("{$firstColumn}{$row}:{$lastColumn}{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::ZEBRA_FILL_COLOR]],
                ]);
            }
        }
    }

    private function applyBorders(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]],
            ],
        ]);
    }

    private function autoSizeColumns(Worksheet $sheet, string $firstColumn, string $lastColumn): void
    {
        foreach (range($firstColumn, $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    private function minutesToExcelTime(int $minutes): float
    {
        return $minutes / 1440;
    }
}
