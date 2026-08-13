<?php

namespace App\Domain\Export\Services;

use App\Domain\Attendance\Services\WorkStyleFallbackResolver;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** 添付見本に合わせた、社員1名・1か月・1シートの勤怠管理表を生成する。 */
class AttendanceExcelBuilder
{
    private const FONT_COLOR = '000000';

    private const HEADER_FILL_COLOR = 'D9D9D9';

    private const SUBHEADER_FILL_COLOR = 'EEEEEE';

    private const BORDER_COLOR = '000000';

    private const TIME_FORMAT = '[h]:mm';

    private const WEEKDAY_LABELS = ['日', '月', '火', '水', '木', '金', '土'];

    public function __construct(private readonly WorkStyleFallbackResolver $workStyleResolver) {}

    public function buildForMonth(AttendanceMonth $month, string $yearMonth): Spreadsheet
    {
        $month->loadMissing('user');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('勤怠管理表 '.$yearMonth.' '.($month->user?->name ?? $month->user_id))
            ->setCreator('flow-office');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('勤怠管理表');
        $this->buildReportSheet($sheet, $month, $yearMonth);

        return $spreadsheet;
    }

    public function buildEmpty(string $yearMonth): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('勤怠管理表 '.$yearMonth)->setCreator('flow-office');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('勤怠管理表');
        $this->configurePage($sheet);
        $sheet->mergeCells('A2:K3');
        $sheet->setCellValue('A2', $this->yearMonthTitle($yearMonth).' 勤怠管理表');
        $sheet->getStyle('A1:K3')->getFont()->getColor()->setRGB(self::FONT_COLOR);

        return $spreadsheet;
    }

    private function buildReportSheet(Worksheet $sheet, AttendanceMonth $month, string $yearMonth): void
    {
        $this->configurePage($sheet);
        $days = AttendanceDay::query()
            ->where('user_id', $month->user_id)
            ->whereBetween('work_date', [$yearMonth.'-01', Carbon::parse($yearMonth.'-01')->endOfMonth()->toDateString()])
            ->with(['calculation', 'breaks', 'calendarEntry'])
            ->orderBy('work_date')
            ->get()
            ->keyBy(fn (AttendanceDay $day) => $day->work_date->day);

        $snapshot = $month->snapshot_json ?? [];
        $workStyle = $this->workStyleResolver->resolveForUser($month->user_id, Carbon::parse($yearMonth.'-01'));
        $shifts = EmployeeCalendarEntry::query()
            ->where('user_id', $month->user_id)
            ->whereBetween('work_date', [$yearMonth.'-01', Carbon::parse($yearMonth.'-01')->endOfMonth()->toDateString()])
            ->where('is_published', true)
            ->get();

        $lateCount = $days->filter(fn (AttendanceDay $day) => $this->isLate($day))->count();
        $earlyCount = $days->filter(fn (AttendanceDay $day) => $this->isEarly($day))->count();
        $requiredDays = $shifts->isNotEmpty()
            ? $shifts->where('is_working_day', true)->count()
            : $days->filter(fn (AttendanceDay $day) => ($day->calculation?->prescribed_work_minutes ?? 0) > 0)->count();
        $actualDays = $days->filter(fn (AttendanceDay $day) => ($day->calculation?->work_minutes ?? 0) > 0)->count();
        $paidLeaveDays = (float) ($snapshot['paid_leave_days'] ?? 0);
        $monthEnd = Carbon::parse($yearMonth.'-01')->endOfMonth();
        $paidLeaveBalance = PaidLeaveGrant::query()
            ->where('user_id', $month->user_id)
            ->whereDate('granted_on', '<=', $monthEnd)
            ->whereDate('expires_on', '>=', $monthEnd)
            ->withSum([
                'usages as used_days_at_month_end' => fn ($query) => $query
                    ->where('is_confirmed', true)
                    ->whereDate('used_on', '<=', $monthEnd),
            ], 'used_days')
            ->get()
            ->sum(fn (PaidLeaveGrant $grant): float => max(
                0,
                (float) $grant->granted_days - (float) ($grant->used_days_at_month_end ?? 0),
            ));

        $sheet->mergeCells('A2:F3');
        $sheet->setCellValue('A2', $this->yearMonthTitle($yearMonth).' 勤怠管理表');
        $sheet->getStyle('A2:F3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => self::FONT_COLOR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $employeeRows = [
            1 => ['社員番号', $month->user?->employee_number ?: $month->user_id],
            2 => ['勤務形態', $workStyle?->name ?? '未設定'],
            3 => ['所属部署', $month->user?->department ?? '未設定'],
            4 => ['氏名', $month->user?->name ?? ''],
        ];
        foreach ($employeeRows as $row => [$label, $value]) {
            $sheet->mergeCells("G{$row}:H{$row}");
            $sheet->mergeCells("I{$row}:K{$row}");
            $sheet->setCellValue("G{$row}", $label);
            $sheet->setCellValue("I{$row}", $value);
            $this->styleLabel($sheet, "G{$row}:H{$row}");
            $this->applyBorders($sheet, "G{$row}:K{$row}");
        }

        $this->writeSummaryRow($sheet, 6, 7, [
            ['必要出勤日数', $requiredDays],
            ['実出勤日数', $actualDays],
            ['欠勤日数', (int) ($snapshot['absence_days'] ?? 0)],
            ['遅刻', $lateCount],
            ['早退', $earlyCount],
            ['有給', $paidLeaveDays],
            ['休日出勤日数', (int) (($snapshot['work_days_prescribed_holiday'] ?? 0) + ($snapshot['work_days_legal_holiday'] ?? 0))],
        ]);

        $overtimeMinutes = (int) ($snapshot['statutory_excess_overtime_minutes'] ?? 0);
        $this->writeSummaryRow($sheet, 9, 10, [
            ['時間外労働時間合計', $this->minutesToExcelTime($overtimeMinutes), true],
            ['休日労働時間合計', $this->minutesToExcelTime((int) (($snapshot['legal_holiday_work_minutes'] ?? 0) + ($snapshot['prescribed_holiday_work_minutes'] ?? 0))), true],
            ['月45時間超確認', $overtimeMinutes > 2700 ? '有' : '無'],
            ['有給残日数', $this->formatDays($paidLeaveBalance)],
            ['年5日取得確認', $paidLeaveDays >= 5 ? '済' : '未'],
        ]);

        $headerRow = 12;
        $headers = ['日', '曜日', '始業時間', '終業時間', '所定内', '時間外', '休憩', '遅刻', '早退', '欠勤', '備考'];
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $sheet->getStyle("A{$headerRow}:K{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::FONT_COLOR]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL_COLOR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $daysInMonth = Carbon::parse($yearMonth.'-01')->daysInMonth;
        for ($dayNumber = 1; $dayNumber <= $daysInMonth; $dayNumber++) {
            $row = $headerRow + $dayNumber;
            /** @var AttendanceDay|null $day */
            $day = $days->get($dayNumber);
            $date = Carbon::parse(sprintf('%s-%02d', $yearMonth, $dayNumber));
            $calculation = $day?->calculation;
            $breakMinutes = $day?->breaks->sum(function ($break): int {
                if ($break->break_start_at === null || $break->break_end_at === null) {
                    return 0;
                }

                return (int) $break->break_start_at->diffInMinutes($break->break_end_at);
            }) ?? 0;
            $overtime = ($calculation?->statutory_within_overtime_minutes ?? 0)
                + ($calculation?->statutory_excess_overtime_minutes ?? 0);

            $sheet->fromArray([
                $dayNumber,
                self::WEEKDAY_LABELS[$date->dayOfWeek],
                $day?->actual_start_at?->format('H:i') ?? '',
                $day?->actual_end_at?->format('H:i') ?? '',
                $calculation ? $this->minutesToExcelTime((int) $calculation->prescribed_work_minutes) : '',
                $calculation ? $this->minutesToExcelTime((int) $overtime) : '',
                $day ? $this->minutesToExcelTime($breakMinutes) : '',
                $day && $this->isLate($day) ? '○' : '',
                $day && $this->isEarly($day) ? '○' : '',
                $calculation && $calculation->absence_minutes > 0 ? '○' : '',
                $this->dayNote($day),
            ], null, "A{$row}");

            if ($date->isWeekend()) {
                $sheet->getStyle("A{$row}:K{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SUBHEADER_FILL_COLOR);
            }
        }

        $totalRow = $headerRow + $daysInMonth + 1;
        $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", '労働時間合計');
        $sheet->setCellValue("E{$totalRow}", $this->minutesToExcelTime((int) ($snapshot['prescribed_work_minutes'] ?? 0)));
        $sheet->setCellValue("F{$totalRow}", $this->minutesToExcelTime($overtimeMinutes));
        $sheet->getStyle("A{$totalRow}:K{$totalRow}")->getFont()->setBold(true);

        $sheet->getStyle("E13:G{$totalRow}")->getNumberFormat()->setFormatCode(self::TIME_FORMAT);
        $sheet->getStyle('A1:K'.$totalRow)->getFont()->getColor()->setRGB(self::FONT_COLOR);
        $sheet->getStyle("A{$headerRow}:K{$totalRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A{$headerRow}:J{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->applyBorders($sheet, "A{$headerRow}:K{$totalRow}");
        $sheet->freezePane('A13');
        $sheet->getPageSetup()->setPrintArea('A1:K'.$totalRow);
    }

    /** @param array<int, array{0: string, 1: mixed, 2?: bool}> $items */
    private function writeSummaryRow(Worksheet $sheet, int $labelRow, int $valueRow, array $items): void
    {
        $itemCount = count($items);
        $columns = $itemCount === 7
            ? [['A', 'C'], ['D', 'E'], ['F', 'F'], ['G', 'G'], ['H', 'H'], ['I', 'I'], ['J', 'K']]
            : [['A', 'C'], ['D', 'E'], ['F', 'G'], ['H', 'I'], ['J', 'K']];

        foreach ($items as $index => $item) {
            [$label, $value] = $item;
            $isTime = $item[2] ?? false;
            [$start, $end] = $columns[$index];
            $sheet->mergeCells("{$start}{$labelRow}:{$end}{$labelRow}");
            $sheet->mergeCells("{$start}{$valueRow}:{$end}{$valueRow}");
            $sheet->setCellValue("{$start}{$labelRow}", $label);
            $sheet->setCellValue("{$start}{$valueRow}", $value);
            $this->styleLabel($sheet, "{$start}{$labelRow}:{$end}{$labelRow}");
            $sheet->getStyle("{$start}{$valueRow}:{$end}{$valueRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            if ($isTime) {
                $sheet->getStyle("{$start}{$valueRow}")->getNumberFormat()->setFormatCode(self::TIME_FORMAT);
            }
            $this->applyBorders($sheet, "{$start}{$labelRow}:{$end}{$valueRow}");
        }
    }

    private function styleLabel(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::FONT_COLOR]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL_COLOR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
    }

    private function configurePage(Worksheet $sheet): void
    {
        foreach (['A' => 4, 'B' => 5, 'C' => 10, 'D' => 10, 'E' => 10, 'F' => 10, 'G' => 9, 'H' => 6, 'I' => 6, 'J' => 6, 'K' => 30] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getDefaultRowDimension()->setRowHeight(18);
        $sheet->getParent()?->getDefaultStyle()->getFont()->setName('Yu Gothic')->setSize(9)->getColor()->setRGB(self::FONT_COLOR);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(1)
            ->setHorizontalCentered(true)
            ->setVerticalCentered(true);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.3)->setRight(0.3);
        $sheet->setShowGridlines(false);
    }

    private function applyBorders(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]],
            ],
        ]);
    }

    private function isLate(AttendanceDay $day): bool
    {
        return $day->actual_start_at !== null
            && $day->calendarEntry?->planned_start_at !== null
            && $day->actual_start_at->greaterThan($day->calendarEntry->planned_start_at);
    }

    private function isEarly(AttendanceDay $day): bool
    {
        return $day->actual_end_at !== null
            && $day->calendarEntry?->planned_end_at !== null
            && $day->actual_end_at->lessThan($day->calendarEntry->planned_end_at);
    }

    private function dayNote(?AttendanceDay $day): string
    {
        if ($day === null) {
            return '';
        }

        $leave = match (true) {
            (float) ($day->calculation?->paid_leave_days ?? 0) > 0 => '有給休暇',
            (float) ($day->calculation?->special_leave_days ?? 0) > 0 => '特別休暇',
            default => null,
        };

        return collect([$leave, $day->note])->filter()->implode(' / ');
    }

    private function yearMonthTitle(string $yearMonth): string
    {
        [$year, $month] = explode('-', $yearMonth);

        return sprintf('%d年%d月度', (int) $year, (int) $month);
    }

    private function minutesToExcelTime(int $minutes): float
    {
        return $minutes / 1440;
    }

    private function formatDays(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.').'日';
    }
}
