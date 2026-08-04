<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\EventStore;
use App\Domain\Export\Events\ExportCreated;
use App\Domain\Export\Services\AttendanceCsv\AttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceCsv\FreeeAttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceCsv\GenericAttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceCsv\GenericSjisAttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceCsv\GenericTsvAttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceCsv\MoneyForwardAttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceExcelBuilder;
use App\Http\Controllers\Controller;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\BackOfficeTask;
use App\Models\ExpenseClaim;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * UC-E001: 勤怠CSVを出力する / UC-E002: 経費CSVを出力する / UC-B004 step5 (会計・振込CSV)。
 */
#[OA\Tag(name: 'CSV出力', description: '勤怠・経費CSV出力')]
class ExportController extends Controller
{
    /**
     * UC-E001: 勤怠CSVを出力する。承認済み(UC-A009)・締め済み(UC-A011)の月次勤怠が対象。
     * 締め処理はバックオフィスタスク側の個別操作に変わったため、バックオフィス担当者が
     * 締める前でも承認済みの時点でCSV/帳票を出力できる必要がある。
     */
    #[OA\Get(
        path: '/exports/attendance',
        operationId: 'exports.attendance',
        summary: '勤怠CSVを出力する',
        tags: ['CSV出力'],
        parameters: [new OA\Parameter(name: 'year_month', in: 'query', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid')), style: 'form', explode: true), new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['generic', 'generic_tsv', 'generic_sjis', 'moneyforward', 'freee']))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function attendance(Request $request, EventStore $eventStore): StreamedResponse
    {
        $data = $this->validateAttendanceExportRequest($request);
        $formatKey = $data['format'] ?? 'generic';
        $format = $this->resolveAttendanceCsvFormat($formatKey);
        $months = $this->resolveAttendanceMonths($data);

        $eventStore->append(
            aggregateType: 'export',
            aggregateId: (string) Str::uuid(),
            event: new ExportCreated(
                exportType: 'attendance_csv',
                params: $data,
                requestedByUserId: $request->user()->id,
                rowCount: $months->count(),
            ),
        );

        $filename = $formatKey === 'generic'
            ? 'attendance_'.$data['year_month'].'.csv'
            : 'attendance_'.$data['year_month'].'_'.$formatKey.'.'.$format->fileExtension();

        return response()->streamDownload(function () use ($months, $data, $format) {
            $handle = fopen('php://temp', 'w+');
            fputcsv($handle, $format->header(), $format->delimiter());

            foreach ($months as $month) {
                fputcsv($handle, $format->row($month, $data['year_month']), $format->delimiter());
            }

            rewind($handle);
            $contents = stream_get_contents($handle);
            fclose($handle);

            if ($format->encoding() !== 'UTF-8') {
                $contents = mb_convert_encoding($contents, $format->encoding(), 'UTF-8');
            }

            echo $contents;
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * UC-E001: 勤怠実績をExcel(.xlsx)で出力する。attendance()と同じ対象月抽出ロジック
     * (承認済み・締め済みのみ)・権限チェックを使い、見た目を整えた月次サマリ+日別明細の
     * 2シート構成で出力する。対象社員が2名以上の場合は各人の.xlsxをZIPにまとめて返す。
     */
    #[OA\Get(
        path: '/exports/attendance.xlsx',
        operationId: 'exports.attendanceExcel',
        summary: '勤怠実績Excelを出力する',
        tags: ['CSV出力'],
        parameters: [new OA\Parameter(name: 'year_month', in: 'query', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid')), style: 'form', explode: true)],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function attendanceExcel(Request $request, EventStore $eventStore, AttendanceExcelBuilder $builder): Response
    {
        $data = $this->validateAttendanceExportRequest($request);
        $months = $this->resolveAttendanceMonths($data);

        if ($months->count() <= 1) {
            $spreadsheet = $months->isEmpty()
                ? $builder->buildEmpty($data['year_month'])
                : $builder->buildForMonth($months->first(), $data['year_month']);

            $writer = new Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $contents = ob_get_clean();

            $eventStore->append(
                aggregateType: 'export',
                aggregateId: (string) Str::uuid(),
                event: new ExportCreated(
                    exportType: 'attendance_xlsx',
                    params: $data,
                    requestedByUserId: $request->user()->id,
                    rowCount: $months->count(),
                ),
            );

            return response($contents, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="attendance_'.$data['year_month'].'.xlsx"',
            ]);
        }

        $response = $this->buildAttendanceExcelZip($months, $data['year_month'], $builder);

        $eventStore->append(
            aggregateType: 'export',
            aggregateId: (string) Str::uuid(),
            event: new ExportCreated(
                exportType: 'attendance_xlsx_zip',
                params: $data,
                requestedByUserId: $request->user()->id,
                rowCount: $months->count(),
            ),
        );

        return $response;
    }

    /**
     * @param  Collection<int, AttendanceMonth>  $months
     */
    private function buildAttendanceExcelZip(Collection $months, string $yearMonth, AttendanceExcelBuilder $builder): Response
    {
        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $xlsxPaths = [];
        $zipPath = $tmpDir.'/attendance_'.$yearMonth.'_'.Str::uuid().'.zip';

        try {
            $zip = new ZipArchive;
            $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($openResult !== true) {
                throw new \RuntimeException("ZIPファイルの作成に失敗しました(エラーコード: {$openResult})。");
            }

            foreach ($months as $month) {
                $spreadsheet = $builder->buildForMonth($month, $yearMonth);
                $xlsxPath = $tmpDir.'/'.$month->user_id.'_'.$yearMonth.'_'.Str::uuid().'.xlsx';

                // save()が失敗した場合でも部分的に書き出されたファイルをfinallyで削除できるよう、
                // save()の前にパスを登録しておく。
                $xlsxPaths[] = $xlsxPath;
                $writer = new Xlsx($spreadsheet);
                $writer->save($xlsxPath);

                $zip->addFile($xlsxPath, $month->user_id.'_'.$yearMonth.'.xlsx');
            }

            $zip->close();

            $contents = file_get_contents($zipPath);

            return response($contents, 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="attendance_'.$yearMonth.'.zip"',
            ]);
        } finally {
            foreach ($xlsxPaths as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    #[OA\Get(
        path: '/exports/expenses',
        operationId: 'exports.expenses',
        summary: '経費CSVを出力する',
        tags: ['CSV出力'],
        parameters: [new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function expenses(Request $request, EventStore $eventStore): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        // UC-X012: 経費精算専用ドメイン(expense_claims)の承認済みバックオフィスタスクのみが
        // 対象。旧汎用ワークフロー方式(request_types.export_amount_field)は廃止した
        // (docs/30-usecases-expense.md)。
        $tasks = BackOfficeTask::query()
            ->with(['source.employee'])
            ->where('source_type', 'expense_claim')
            ->whereIn('status', ['payment_scheduled', 'completed'])
            ->whereBetween('created_at', [
                Carbon::parse($data['from'])->startOfDay(),
                Carbon::parse($data['to'])->endOfDay(),
            ])
            ->get()
            ->filter(fn (BackOfficeTask $task) => $task->source instanceof ExpenseClaim)
            ->values();

        $eventStore->append(
            aggregateType: 'export',
            aggregateId: (string) Str::uuid(),
            event: new ExportCreated(
                exportType: 'expenses_csv',
                params: $data,
                requestedByUserId: $request->user()->id,
                rowCount: $tasks->count(),
            ),
        );

        return response()->streamDownload(function () use ($tasks) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['task_id', 'title', 'employee_name', 'amount', 'status', 'created_at']);

            foreach ($tasks as $task) {
                /** @var ExpenseClaim|null $claim */
                $claim = $task->source;

                fputcsv($handle, [
                    $task->id,
                    $task->title,
                    $claim?->employee?->name,
                    $claim?->total_amount ?? '',
                    $task->status,
                    $task->created_at->toDateString(),
                ]);
            }

            fclose($handle);
        }, 'expenses_'.$data['from'].'_'.$data['to'].'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{year_month: string, user_id?: array<int, string>, format?: string}
     */
    private function validateAttendanceExportRequest(Request $request): array
    {
        return $request->validate([
            'year_month' => ['required', 'date_format:Y-m'],
            'user_id' => ['nullable', 'array'],
            'user_id.*' => ['string', 'exists:users,id'],
            'format' => ['nullable', 'string', 'in:generic,generic_tsv,generic_sjis,moneyforward,freee'],
        ]);
    }

    private function resolveAttendanceCsvFormat(string $formatKey): AttendanceCsvFormat
    {
        return match ($formatKey) {
            'generic' => new GenericAttendanceCsvFormat,
            'generic_tsv' => new GenericTsvAttendanceCsvFormat,
            'generic_sjis' => new GenericSjisAttendanceCsvFormat,
            'moneyforward' => new MoneyForwardAttendanceCsvFormat,
            'freee' => new FreeeAttendanceCsvFormat,
            default => throw ValidationException::withMessages(['format' => ['サポートされていないフォーマットです。']]),
        };
    }

    /**
     * attendance()・attendanceExcel()共通の対象月抽出ロジック。承認済み(UC-A009)・
     * 締め済み(UC-A011)の月次勤怠のみを対象とする(docs/14-usecases-export.md)。
     *
     * @param  array{year_month: string, user_id?: array<int, string>}  $data
     * @return Collection<int, AttendanceMonth>
     */
    private function resolveAttendanceMonths(array $data): Collection
    {
        return AttendanceMonth::query()
            ->with('user')
            ->where('year_month', $data['year_month'])
            ->whereIn('status', [AttendanceMonthStatus::APPROVED, AttendanceMonthStatus::CLOSED])
            ->when($data['user_id'] ?? null, fn ($query, $userIds) => $query->whereIn('user_id', $userIds))
            ->orderBy('user_id')
            ->get();
    }
}
