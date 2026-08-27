<?php

namespace App\Http\Controllers\Api;

use App\Domain\Export\Aggregates\ExportAuditAggregate;
use App\Domain\Export\Services\ExternalIntegrationPublisherResolver;
use App\Domain\Export\Services\AttendanceCsv\AttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceCsv\FreeeAttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceCsv\GenericAttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceCsv\GenericSjisAttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceCsv\GenericTsvAttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceCsv\MoneyForwardAttendanceCsvFormat;
use App\Domain\Export\Services\AttendanceExcelBuilder;
use App\Domain\Export\Services\ExpenseCsv\ExpenseCsvFormat;
use App\Domain\Export\Services\ExpenseCsv\FreeeExpenseCsvFormat;
use App\Domain\Export\Services\ExpenseCsv\GenericExpenseCsvFormat;
use App\Domain\Export\Services\ExpenseCsv\MoneyForwardExpenseCsvFormat;
use App\Domain\Export\Services\ExpenseExcelBuilder;
use App\Domain\Export\Services\Publishers\InternalArchivePublisher;
use App\Http\Controllers\Controller;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\BackOfficeTask;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;
use App\Models\ExternalEmployeeMapping;
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
        parameters: [new OA\Parameter(name: 'year_month', in: 'query', required: true, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string')), style: 'form', explode: true), new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid')), style: 'form', explode: true), new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['generic', 'generic_tsv', 'generic_sjis', 'moneyforward', 'freee']))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function attendance(Request $request): StreamedResponse
    {
        $data = $this->validateAttendanceExportRequest($request);
        $formatKey = $data['format'] ?? 'generic';
        $format = $this->resolveAttendanceCsvFormat($formatKey);
        $months = $this->resolveAttendanceMonths($data);
        $label = $this->yearMonthLabel($data['year_month']);

        $this->recordExport('attendance_csv', $data, $request->user()->id, $months->count());

        $filename = $formatKey === 'generic'
            ? 'attendance_'.$label.'.csv'
            : 'attendance_'.$label.'_'.$formatKey.'.'.$format->fileExtension();

        return response()->streamDownload(function () use ($months, $format) {
            $handle = fopen('php://temp', 'w+');
            fputcsv($handle, $format->header(), $format->delimiter());

            foreach ($months as $month) {
                fputcsv($handle, $format->row($month, $month->year_month), $format->delimiter());
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
     * (承認済み・締め済みのみ)・権限チェックを使い、見た目を整えた勤怠管理表を出力する。
     * 対象が2件以上の場合は各人・各月の.xlsxをZIPにまとめて返す。
     */
    #[OA\Get(
        path: '/exports/attendance.xlsx',
        operationId: 'exports.attendanceExcel',
        summary: '勤怠実績Excelを出力する',
        tags: ['CSV出力'],
        parameters: [new OA\Parameter(name: 'year_month', in: 'query', required: true, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string')), style: 'form', explode: true), new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid')), style: 'form', explode: true)],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function attendanceExcel(Request $request, AttendanceExcelBuilder $builder): Response
    {
        $data = $this->validateAttendanceExportRequest($request);
        $months = $this->resolveAttendanceMonths($data);
        $label = $this->yearMonthLabel($data['year_month']);

        if ($months->count() <= 1) {
            $spreadsheet = $months->isEmpty()
                ? $builder->buildEmpty($label)
                : $builder->buildForMonth($months->first(), $months->first()->year_month);

            $writer = new Xlsx($spreadsheet);
            ob_start();
            $writer->save('php://output');
            $contents = ob_get_clean();

            $this->recordExport('attendance_xlsx', $data, $request->user()->id, $months->count());

            $filename = $months->isEmpty()
                ? '勤怠管理表_'.$this->yearMonthDisplayLabel($label).'.xlsx'
                : $this->attendanceExcelFilename($months->first());

            return response($contents, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => $this->attachmentContentDisposition($filename, 'attendance_'.$label.'.xlsx'),
            ]);
        }

        $response = $this->buildAttendanceExcelZip($months, $label, $builder);

        $this->recordExport('attendance_xlsx_zip', $data, $request->user()->id, $months->count());

        return $response;
    }

    /**
     * @param  Collection<int, AttendanceMonth>  $months
     */
    private function buildAttendanceExcelZip(Collection $months, string $label, AttendanceExcelBuilder $builder): Response
    {
        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $xlsxPaths = [];
        $zipPath = $tmpDir.'/attendance_'.$label.'_'.Str::uuid().'.zip';

        try {
            $zip = new ZipArchive;
            $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($openResult !== true) {
                throw new \RuntimeException("ZIPファイルの作成に失敗しました(エラーコード: {$openResult})。");
            }

            $usedEntryNames = [];
            foreach ($months as $month) {
                $spreadsheet = $builder->buildForMonth($month, $month->year_month);
                $xlsxPath = $tmpDir.'/'.$month->user_id.'_'.$month->year_month.'_'.Str::uuid().'.xlsx';

                // save()が失敗した場合でも部分的に書き出されたファイルをfinallyで削除できるよう、
                // save()の前にパスを登録しておく。
                $xlsxPaths[] = $xlsxPath;
                $writer = new Xlsx($spreadsheet);
                $writer->save($xlsxPath);

                $entryName = $this->uniqueZipEntryName($this->attendanceExcelFilename($month), $usedEntryNames);
                $usedEntryNames[] = $entryName;
                $zip->addFile($xlsxPath, $entryName);
            }

            $zip->close();

            $contents = file_get_contents($zipPath);
            $filename = $this->attendanceExcelZipFilename($months);

            return response($contents, 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => $this->attachmentContentDisposition($filename, 'attendance_'.$label.'.zip'),
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

    private function attendanceExcelFilename(AttendanceMonth $month): string
    {
        $employeeName = $this->safeFilenamePart($month->user?->name ?: $month->user_id);

        return '勤怠管理表_'.$employeeName.'_'.$this->yearMonthDisplayLabel($month->year_month).'.xlsx';
    }

    /** @param Collection<int, AttendanceMonth> $months */
    private function attendanceExcelZipFilename(Collection $months): string
    {
        $yearMonths = $months->pluck('year_month')->unique()->sort()->values();
        $periodLabel = $this->yearMonthDisplayLabel($yearMonths->first());
        if ($yearMonths->count() > 1) {
            $periodLabel .= '-'.$this->yearMonthDisplayLabel($yearMonths->last());
        }

        $userIds = $months->pluck('user_id')->unique();
        $employeeLabel = '';
        if ($userIds->count() === 1) {
            $employeeLabel = $this->safeFilenamePart($months->first()->user?->name ?: $months->first()->user_id).'_';
        }

        return '勤怠管理表_'.$employeeLabel.$periodLabel.'.zip';
    }

    private function yearMonthDisplayLabel(string $yearMonth): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $matches) === 1) {
            return $matches[1].'年'.$matches[2].'月';
        }

        return $this->safeFilenamePart($yearMonth);
    }

    /** 社員名の半角・全角空白と、Windowsでファイル名に使用できない文字を除去する。 */
    private function safeFilenamePart(string $value): string
    {
        $value = preg_replace('/[\s\x{3000}]+/u', '', $value) ?? '';
        $value = preg_replace('~[\\\\/:*?"<>|\x00-\x1F]~u', '', $value) ?? '';
        $value = trim($value, '.');

        return $value !== '' ? $value : '社員名未設定';
    }

    /** @param list<string> $usedNames */
    private function uniqueZipEntryName(string $filename, array $usedNames): string
    {
        if (! in_array($filename, $usedNames, true)) {
            return $filename;
        }

        $base = Str::beforeLast($filename, '.xlsx');
        for ($suffix = 2; ; $suffix++) {
            $candidate = $base.'_'.($suffix).'.xlsx';
            if (! in_array($candidate, $usedNames, true)) {
                return $candidate;
            }
        }
    }

    private function attachmentContentDisposition(string $utf8Filename, string $asciiFallback): string
    {
        return sprintf(
            "attachment; filename=\"%s\"; filename*=UTF-8''%s",
            $asciiFallback,
            rawurlencode($utf8Filename),
        );
    }

    /**
     * フェーズ2: 勤怠月次確定データ(承認済み・締め済み)をfreee/moneyforward等の外部APIへ
     * 送信する(docs/33-usecases-attendance-external-api.md)。従業員ごとに送信を試み、
     * 送信結果(成功/失敗)を配列で返す。失敗した従業員は自動リトライせず、手動で再実行する。
     * 冪等性キーで重複送信を検知できるよう、送信成功時のみexternal_integration.publishedを
     * stored_eventsへ記録する。
     */
    #[OA\Post(
        path: '/exports/attendance/external-publish',
        operationId: 'exports.attendanceExternalPublish',
        summary: '勤怠確定データを外部API(freee)へ送信する',
        tags: ['CSV出力'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function attendanceExternalPublish(Request $request, ExternalIntegrationPublisherResolver $resolver): \Illuminate\Http\JsonResponse
    {
        if (is_string($request->input('year_month'))) {
            $request->merge(['year_month' => [$request->input('year_month')]]);
        }

        $data = $request->validate([
            'year_month' => ['required', 'array', 'min:1'],
            'year_month.*' => ['string', 'date_format:Y-m'],
            'user_id' => ['nullable', 'array'],
            'user_id.*' => ['string', 'exists:users,id'],
            // 勤怠のAPIプッシュ連携はfreeeのみ対応する。MoneyForwardには外部から勤怠データを
            // プッシュする公開APIが存在しないため(docs/notes/moneyforward-api-investigation.md)、
            // MoneyForward向けの勤怠出力は引き続きCSVのみで案内する。
            'provider' => ['required', 'string', 'in:freee'],
        ]);

        $months = $this->resolveAttendanceMonths($data);

        try {
            [$publisher, $builder, $externalCompanyId] = $resolver->resolve($data['provider']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['provider' => [$e->getMessage()]]);
        }

        $mappings = ExternalEmployeeMapping::query()
            ->where('provider', $data['provider'])
            ->whereIn('user_id', $months->pluck('user_id')->unique())
            ->get()
            ->keyBy('user_id');

        $successes = [];
        $failures = [];

        foreach ($months as $month) {
            $mapping = $mappings->get($month->user_id);
            if ($mapping === null) {
                $failures[] = [
                    'user_id' => $month->user_id,
                    'year_month' => $month->year_month,
                    'reason' => 'employee_mapping_missing',
                    'message' => '外部連携先の従業員番号マッピングが未登録です。',
                ];

                continue;
            }

            try {
                $payload = $builder->build($month, $mapping->external_employee_code, $externalCompanyId);
                $filename = 'attendance_'.$data['provider'].'_'.$month->year_month.'_'.$mapping->external_employee_code.'.json';
                $publisher->publish(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $filename, $data);

                ExportAuditAggregate::retrieve((string) Str::uuid())
                    ->recordExternalPublish(
                        $data['provider'],
                        $month->id,
                        $month->year_month,
                        $month->user_id,
                        $mapping->external_employee_code,
                        $data,
                        $request->user()->id,
                    )
                    ->persist();

                $successes[] = [
                    'user_id' => $month->user_id,
                    'year_month' => $month->year_month,
                    'external_employee_code' => $mapping->external_employee_code,
                ];
            } catch (\Throwable $e) {
                $failures[] = [
                    'user_id' => $month->user_id,
                    'year_month' => $month->year_month,
                    'reason' => 'send_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'provider' => $data['provider'],
            'successes' => $successes,
            'failures' => $failures,
        ]);
    }

    #[OA\Get(
        path: '/exports/expenses',
        operationId: 'exports.expenses',
        summary: '経費CSVを出力する',
        tags: ['CSV出力'],
        parameters: [new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['generic', 'moneyforward', 'freee']))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function expenses(Request $request): StreamedResponse
    {
        $data = $this->validateExpenseExportRequest($request);
        $formatKey = $data['format'] ?? 'generic';
        $format = $this->resolveExpenseCsvFormat($formatKey);
        $tasks = $this->resolveExpenseBackOfficeTasks($data);

        $this->recordExport('expenses_csv', $data, $request->user()->id, $tasks->count());

        $filename = $formatKey === 'generic'
            ? 'expenses_'.$data['from'].'_'.$data['to'].'.csv'
            : 'expenses_'.$data['from'].'_'.$data['to'].'_'.$formatKey.'.'.$format->fileExtension();

        return response()->streamDownload(function () use ($tasks, $format) {
            $handle = fopen('php://temp', 'w+');
            fputcsv($handle, $format->header(), $format->delimiter());

            foreach ($tasks as $task) {
                /** @var ExpenseClaim|null $claim */
                $claim = $task->source;
                if (! $claim instanceof ExpenseClaim) {
                    continue;
                }

                foreach ($format->rows($task, $claim) as $row) {
                    fputcsv($handle, $row, $format->delimiter());
                }
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
     * UC-X012: 経費の証跡アーカイブExcelを内部保存する。ダウンロードもできるが、主目的は
     * InternalArchivePublisherを通じた内部保管であり、internal_archive.createdをstored_eventsへ
     * 記録する(冪等性キーは「対象データID+出力種別+実行回数」)。
     */
    #[OA\Get(
        path: '/exports/expenses.xlsx',
        operationId: 'exports.expensesExcel',
        summary: '経費証跡アーカイブExcelを出力する',
        tags: ['CSV出力'],
        parameters: [new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function expensesExcel(Request $request, ExpenseExcelBuilder $builder, InternalArchivePublisher $publisher): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $tasks = $this->resolveExpenseBackOfficeTasks($data)
            ->each(fn (BackOfficeTask $task) => $task->source?->loadMissing(['items.category', 'items.attachments']));

        $spreadsheet = $builder->build($tasks);
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $contents = ob_get_clean();

        $filename = '経費証跡アーカイブ_'.$data['from'].'_'.$data['to'].'.xlsx';
        $artifact = $publisher->publish($contents, $filename, ['from' => $data['from'], 'to' => $data['to']]);

        $subjectId = $data['from'].'_'.$data['to'];
        ExportAuditAggregate::retrieve((string) Str::uuid())
            ->recordInternalArchive('expenses_xlsx_archive', $subjectId, $data, $request->user()->id, $artifact->storedPath, $tasks->count())
            ->persist();

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => $this->attachmentContentDisposition($filename, 'expenses_'.$data['from'].'_'.$data['to'].'.xlsx'),
        ]);
    }

    /**
     * フェーズ3: 経費申請(承認済み)の確定データをfreee/moneyforward等の外部APIへ
     * 送信する(docs/30-usecases-expense.md UC-X012)。attendanceExternalPublish()と同じ
     * 権限ゲート方式・失敗時レスポンス形式(successes/failures)。承認済み(expense_claims.status
     * = approved)でない申請は送信対象から除外する。送信成功時のみexternal_integration.published
     * をstored_eventsへ記録し、冪等性キーで重複送信を検知する。
     */
    #[OA\Post(
        path: '/exports/expenses/external-publish',
        operationId: 'exports.expensesExternalPublish',
        summary: '経費確定データを外部API(freee/moneyforward)へ送信する',
        tags: ['CSV出力'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function expensesExternalPublish(Request $request, ExternalIntegrationPublisherResolver $resolver): \Illuminate\Http\JsonResponse
    {
        if (is_string($request->input('year_month'))) {
            $request->merge(['year_month' => [$request->input('year_month')]]);
        }

        $data = $request->validate([
            'year_month' => ['required', 'array', 'min:1'],
            'year_month.*' => ['string', 'date_format:Y-m'],
            'employee_id' => ['nullable', 'array'],
            'employee_id.*' => ['string', 'exists:users,id'],
            'provider' => ['required', 'string', 'in:freee,moneyforward'],
        ]);

        $claims = $this->resolveApprovedExpenseClaims($data);
        $periodLabel = $this->yearMonthLabel($data['year_month']);

        try {
            [$publisher, $builder] = $resolver->resolveExpense($data['provider']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['provider' => [$e->getMessage()]]);
        }

        $mappings = ExternalEmployeeMapping::query()
            ->where('provider', $data['provider'])
            ->whereIn('user_id', $claims->pluck('employee_id')->unique())
            ->get()
            ->keyBy('user_id');

        $successes = [];
        $failures = [];

        foreach ($claims as $claim) {
            $mapping = $mappings->get($claim->employee_id);
            if ($mapping === null) {
                $failures[] = [
                    'employee_id' => $claim->employee_id,
                    'expense_claim_id' => $claim->id,
                    'reason' => 'employee_mapping_missing',
                    'message' => '外部連携先の従業員番号マッピングが未登録です。',
                ];

                continue;
            }

            try {
                $payload = $builder->build($claim, $mapping->external_employee_code);
                $filename = 'expense_'.$data['provider'].'_'.$claim->id.'_'.$mapping->external_employee_code.'.json';
                $publisher->publish(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $filename, $data);

                ExportAuditAggregate::retrieve((string) Str::uuid())
                    ->recordExpenseExternalPublish(
                        $data['provider'],
                        $claim->id,
                        $periodLabel,
                        $claim->employee_id,
                        $mapping->external_employee_code,
                        $data,
                        $request->user()->id,
                    )
                    ->persist();

                $successes[] = [
                    'employee_id' => $claim->employee_id,
                    'expense_claim_id' => $claim->id,
                    'external_employee_code' => $mapping->external_employee_code,
                ];
            } catch (\Throwable $e) {
                $failures[] = [
                    'employee_id' => $claim->employee_id,
                    'expense_claim_id' => $claim->id,
                    'reason' => 'send_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'provider' => $data['provider'],
            'successes' => $successes,
            'failures' => $failures,
        ]);
    }

    /**
     * expensesExternalPublish()の対象抽出ロジック。承認済み(expense_claims.status = approved)
     * の申請のみを対象とし、対象月はapproved_at(未承認/未確定ならsubmitted_at・period_from)を
     * 基準に year_month(配列)へ一致するものを抽出する。
     *
     * @param  array{year_month: array<int, string>, employee_id?: array<int, string>}  $data
     * @return Collection<int, ExpenseClaim>
     */
    private function resolveApprovedExpenseClaims(array $data): Collection
    {
        $yearMonths = $data['year_month'];

        return ExpenseClaim::query()
            ->with(['items.category', 'employee'])
            ->where('status', ExpenseClaimStatus::APPROVED)
            ->when($data['employee_id'] ?? null, fn ($query, $employeeIds) => $query->whereIn('employee_id', $employeeIds))
            ->get()
            ->filter(function (ExpenseClaim $claim) use ($yearMonths) {
                $basis = $claim->approved_at ?? $claim->submitted_at ?? $claim->period_from;

                return $basis !== null && in_array($basis->format('Y-m'), $yearMonths, true);
            })
            ->values();
    }

    /**
     * @return array{from: string, to: string, format?: string}
     */
    private function validateExpenseExportRequest(Request $request): array
    {
        return $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['nullable', 'string', 'in:generic,moneyforward,freee'],
        ]);
    }

    private function resolveExpenseCsvFormat(string $formatKey): ExpenseCsvFormat
    {
        return match ($formatKey) {
            'generic' => new GenericExpenseCsvFormat,
            'moneyforward' => new MoneyForwardExpenseCsvFormat,
            'freee' => new FreeeExpenseCsvFormat,
            default => throw ValidationException::withMessages(['format' => ['サポートされていないフォーマットです。']]),
        };
    }

    /**
     * expenses()・expensesExcel()共通の対象タスク抽出ロジック。UC-X012: 経費精算専用ドメイン
     * (expense_claims)の承認済みバックオフィスタスクのみが対象。旧汎用ワークフロー方式
     * (request_types.export_amount_field)は廃止した(docs/30-usecases-expense.md)。
     *
     * @param  array{from: string, to: string}  $data
     * @return Collection<int, BackOfficeTask>
     */
    private function resolveExpenseBackOfficeTasks(array $data): Collection
    {
        return BackOfficeTask::query()
            ->with(['source.employee', 'source.items.category', 'source.items.attachments'])
            ->where('source_type', 'expense_claim')
            ->whereIn('status', ['payment_scheduled', 'completed'])
            ->whereBetween('created_at', [
                Carbon::parse($data['from'])->startOfDay(),
                Carbon::parse($data['to'])->endOfDay(),
            ])
            ->get()
            ->filter(fn (BackOfficeTask $task) => $task->source instanceof ExpenseClaim)
            ->values();
    }

    /**
     * @return array{year_month: array<int, string>, user_id?: array<int, string>, format?: string}
     */
    private function validateAttendanceExportRequest(Request $request): array
    {
        // year_monthは複数月をまとめて出力できるよう配列を基本とするが、
        // 単一の`year_month=2026-06`形式(旧仕様・後方互換)も引き続き受け付けるため、
        // 文字列で来た場合は配列に正規化してから検証する。
        if (is_string($request->query('year_month'))) {
            $request->merge(['year_month' => [$request->query('year_month')]]);
        }

        return $request->validate([
            'year_month' => ['required', 'array', 'min:1'],
            'year_month.*' => ['string', 'date_format:Y-m'],
            'user_id' => ['nullable', 'array'],
            'user_id.*' => ['string', 'exists:users,id'],
            'format' => ['nullable', 'string', 'in:generic,generic_tsv,generic_sjis,moneyforward,freee'],
        ]);
    }

    /** @param array<string, mixed> $params */
    private function recordExport(string $exportType, array $params, string $requestedByUserId, int $rowCount): void
    {
        ExportAuditAggregate::retrieve((string) Str::uuid())
            ->record($exportType, $params, $requestedByUserId, $rowCount)
            ->persist();
    }

    /**
     * @param  array<int, string>  $yearMonths
     */
    private function yearMonthLabel(array $yearMonths): string
    {
        $sorted = collect($yearMonths)->unique()->sort()->values();

        return $sorted->count() === 1 ? $sorted->first() : $sorted->first().'_'.$sorted->last();
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
     * @param  array{year_month: array<int, string>, user_id?: array<int, string>}  $data
     * @return Collection<int, AttendanceMonth>
     */
    private function resolveAttendanceMonths(array $data): Collection
    {
        return AttendanceMonth::query()
            ->with('user')
            ->whereIn('year_month', $data['year_month'])
            ->whereIn('status', [AttendanceMonthStatus::APPROVED, AttendanceMonthStatus::CLOSED])
            ->when($data['user_id'] ?? null, fn ($query, $userIds) => $query->whereIn('user_id', $userIds))
            ->orderBy('year_month')
            ->orderBy('user_id')
            ->get();
    }
}
