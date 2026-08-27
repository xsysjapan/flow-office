<?php

namespace App\Domain\Export\Services;

use App\Models\Attachment;
use App\Models\BackOfficeTask;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * UC-X012: 経費の証跡アーカイブ(内部保管用Excel)を生成する。AttendanceExcelBuilderと同じ
 * PhpSpreadsheetでのシート生成パターンを踏襲する。
 *
 * - Sheet1以降(一覧): 月次・申請者ごとに1シート(=改ページ)を作り、列は
 *   No/日付/区分/内容/支払先/金額(reimbursement_amount)/証憑No/合計 とする。
 * - 続くシート(証憑): 証憑を貼付する。画像(jpeg/png/gif/webp)はそのまま貼付するが、
 *   PDF証憑をラスタライズするために必要なライブラリ(Imagick等)が
 *   backend/composer.jsonに存在しないため、新規依存を追加せず、PDF証憑は画像化せず
 *   ファイル名のみをシートに記載する(フェーズ1の既知の制約。フェーズ2以降でImagick等の
 *   追加を検討する)。
 */
class ExpenseExcelBuilder
{
    private const FONT_COLOR = '000000';

    private const HEADER_FILL_COLOR = 'D9D9D9';

    private const BORDER_COLOR = '000000';

    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const ROWS_PER_EVIDENCE_ENTRY = 14;

    /**
     * @param  Collection<int, BackOfficeTask>  $tasks  source(ExpenseClaim)・items.category・
     *                                                   items.attachments をloadMissing済みで渡すこと
     */
    public function build(Collection $tasks): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('経費証跡アーカイブ')->setCreator('flow-office');
        $spreadsheet->removeSheetByIndex(0);

        $claimTasks = $tasks->filter(fn (BackOfficeTask $task) => $task->source instanceof ExpenseClaim)->values();

        $groups = $claimTasks->groupBy(function (BackOfficeTask $task) {
            /** @var ExpenseClaim $claim */
            $claim = $task->source;

            return $this->groupKey($claim);
        })->sortKeys();

        $evidenceEntries = [];
        $evidenceNo = 1;
        $usedSheetTitles = [];

        foreach ($groups as $groupKey => $groupTasks) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->uniqueSheetTitle($this->safeSheetTitle((string) $groupKey), $usedSheetTitles));
            $usedSheetTitles[] = $sheet->getTitle();
            [$evidenceEntries, $evidenceNo] = $this->writeListSheet($sheet, (string) $groupKey, $groupTasks, $evidenceEntries, $evidenceNo);
        }

        if ($spreadsheet->getSheetCount() === 0) {
            // 対象0件でも空のブックを返せるよう、案内のみの一覧シートを1枚用意する。
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('経費一覧');
            $this->configurePage($sheet);
            $sheet->setCellValue('A1', '対象期間内に証跡アーカイブの対象となる経費精算はありません。');
        }

        if ($evidenceEntries !== []) {
            $this->writeEvidenceSheets($spreadsheet, $evidenceEntries);
        }

        return $spreadsheet;
    }

    private function groupKey(ExpenseClaim $claim): string
    {
        $yearMonth = $claim->period_from?->format('Y-m') ?? $claim->created_at?->format('Y-m') ?? '';
        $employeeName = $claim->employee?->name ?? $claim->employee_id;

        return $yearMonth.'_'.$employeeName;
    }

    /**
     * @param  Collection<int, BackOfficeTask>  $groupTasks
     * @param  array<int, array{no: int, attachment: Attachment}>  $evidenceEntries
     * @return array{0: array<int, array{no: int, attachment: Attachment}>, 1: int}
     */
    private function writeListSheet(Worksheet $sheet, string $groupKey, Collection $groupTasks, array $evidenceEntries, int $evidenceNo): array
    {
        $this->configurePage($sheet);

        $titleRow = 1;
        $sheet->mergeCells("A{$titleRow}:H{$titleRow}");
        $sheet->setCellValue("A{$titleRow}", $this->groupTitle($groupKey));
        $sheet->getStyle("A{$titleRow}:H{$titleRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => self::FONT_COLOR]],
        ]);

        $headerRow = 3;
        $headers = ['No', '日付', '区分', '内容', '支払先', '金額', '証憑No'];
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $sheet->getStyle("A{$headerRow}:G{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::FONT_COLOR]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL_COLOR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $row = $headerRow + 1;
        $no = 1;
        $total = 0;

        foreach ($groupTasks as $task) {
            /** @var ExpenseClaim $claim */
            $claim = $task->source;

            /** @var ExpenseItem $item */
            foreach ($claim->items as $item) {
                $itemEvidenceNos = [];
                foreach ($item->attachments as $attachment) {
                    $itemEvidenceNos[] = $evidenceNo;
                    $evidenceEntries[] = ['no' => $evidenceNo, 'attachment' => $attachment];
                    $evidenceNo++;
                }

                $sheet->fromArray([
                    $no,
                    $item->usage_date?->format('Y/m/d') ?? '',
                    $item->category?->name ?? '',
                    $item->description ?? '',
                    (string) ($item->attributes['payee'] ?? ''),
                    $item->reimbursement_amount,
                    implode(',', $itemEvidenceNos),
                ], null, "A{$row}");

                $total += $item->reimbursement_amount;
                $no++;
                $row++;
            }
        }

        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->setCellValue("A{$row}", '合計');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->setCellValue("F{$row}", $total);
        $sheet->getStyle("F{$row}")->getFont()->setBold(true);

        $this->applyBorders($sheet, "A{$headerRow}:G{$row}");
        $sheet->getStyle("A1:H{$row}")->getFont()->getColor()->setRGB(self::FONT_COLOR);
        $sheet->getColumnDimension('D')->setWidth(30);
        $sheet->getColumnDimension('E')->setWidth(20);

        return [$evidenceEntries, $evidenceNo];
    }

    /**
     * @param  array<int, array{no: int, attachment: Attachment}>  $evidenceEntries
     */
    private function writeEvidenceSheets(Spreadsheet $spreadsheet, array $evidenceEntries): void
    {
        $chunks = array_chunk($evidenceEntries, 20);

        foreach ($chunks as $chunkIndex => $chunk) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('証憑'.($chunkIndex + 1));
            $this->configurePage($sheet);
            $sheet->getColumnDimension('A')->setWidth(60);

            $row = 1;
            foreach ($chunk as $entry) {
                /** @var Attachment $attachment */
                $attachment = $entry['attachment'];
                $sheet->setCellValue("A{$row}", '証憑No: '.$entry['no'].' / '.$attachment->file_name);
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                $row++;

                if ($this->isImageAttachment($attachment) && $this->attachmentExists($attachment)) {
                    $drawing = new Drawing;
                    $drawing->setPath(Storage::disk('local')->path($attachment->stored_path));
                    $drawing->setHeight(220);
                    $drawing->setCoordinates("A{$row}");
                    $drawing->setWorksheet($sheet);
                    $row += self::ROWS_PER_EVIDENCE_ENTRY;
                } else {
                    // PDF等: ラスタライズ用ライブラリが無いため画像化せず、ファイル名のみ記載する。
                    $sheet->setCellValue("A{$row}", 'PDF等の証憑は本フェーズでは画像化せず、ファイル名のみ記載しています。');
                    $row += 2;
                }
            }
        }
    }

    private function isImageAttachment(Attachment $attachment): bool
    {
        return in_array($attachment->mime_type, self::IMAGE_MIME_TYPES, true);
    }

    private function attachmentExists(Attachment $attachment): bool
    {
        return $attachment->stored_path !== null && Storage::disk('local')->exists($attachment->stored_path);
    }

    private function groupTitle(string $groupKey): string
    {
        [$yearMonth, $employeeName] = array_pad(explode('_', $groupKey, 2), 2, '');
        if (preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $matches) === 1) {
            $yearMonth = $matches[1].'年'.$matches[2].'月';
        }

        return trim($yearMonth.' '.$employeeName.' 経費証跡一覧');
    }

    private function safeSheetTitle(string $groupKey): string
    {
        [$yearMonth, $employeeName] = array_pad(explode('_', $groupKey, 2), 2, '');
        if (preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $matches) === 1) {
            $yearMonth = $matches[1].$matches[2];
        }
        $title = $yearMonth.'_'.$employeeName;
        // Excelシート名は31文字まで、一部記号は使用不可。
        $title = preg_replace('~[\\\\/:*?\[\]]~u', '', $title) ?? $title;

        return mb_substr($title !== '' ? $title : 'sheet', 0, 31);
    }

    /** @param  array<int, string>  $usedTitles */
    private function uniqueSheetTitle(string $title, array $usedTitles): string
    {
        if (! in_array($title, $usedTitles, true)) {
            return $title;
        }

        for ($suffix = 2; ; $suffix++) {
            $candidate = mb_substr($title, 0, 28).'_'.$suffix;
            if (! in_array($candidate, $usedTitles, true)) {
                return $candidate;
            }
        }
    }

    private function configurePage(Worksheet $sheet): void
    {
        foreach (['A' => 6, 'B' => 12, 'C' => 14, 'D' => 24, 'E' => 16, 'F' => 12, 'G' => 12] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getDefaultRowDimension()->setRowHeight(18);
        $sheet->getParent()?->getDefaultStyle()->getFont()->setName('Yu Gothic')->setSize(9)->getColor()->setRGB(self::FONT_COLOR);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.3)->setRight(0.3);
    }

    private function applyBorders(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]],
            ],
        ]);
    }
}
