<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\EventStore;
use App\Domain\Export\Events\ExportCreated;
use App\Http\Controllers\Controller;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\BackOfficeTask;
use App\Models\WorkflowRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use OpenApi\Attributes as OA;

/**
 * UC-E001: 勤怠CSVを出力する / UC-E002: 経費CSVを出力する / UC-B004 step5 (会計・振込CSV)。
 */
#[OA\Tag(name: 'CSV出力', description: '勤怠・経費CSV出力')]
class ExportController extends Controller
{
    /**
     * UC-E001: 勤怠CSVを出力する。締め後(UC-A011)の月次勤怠のみが対象。
     */
    #[OA\Get(
        path: '/exports/attendance',
        operationId: 'exports.attendance',
        summary: '勤怠CSVを出力する',
        tags: ['CSV出力'],
        parameters: [new OA\Parameter(name: 'year_month', in: 'query', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer')), style: 'form', explode: true)],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function attendance(Request $request, EventStore $eventStore): StreamedResponse
    {
        $data = $request->validate([
            'year_month' => ['required', 'date_format:Y-m'],
            'user_id' => ['nullable', 'array'],
            'user_id.*' => ['integer', 'exists:users,id'],
        ]);

        $months = AttendanceMonth::query()
            ->with('user')
            ->where('year_month', $data['year_month'])
            ->where('status', AttendanceMonthStatus::CLOSED)
            ->when($data['user_id'] ?? null, fn ($query, $userIds) => $query->whereIn('user_id', $userIds))
            ->orderBy('user_id')
            ->get();

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

        return response()->streamDownload(function () use ($months) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'user_id', 'user_name', 'year_month', 'actual_work_minutes', 'prescribed_work_minutes',
                'non_statutory_overtime_minutes', 'statutory_overtime_minutes', 'late_night_minutes',
                'legal_holiday_work_minutes', 'company_holiday_work_minutes',
            ]);

            foreach ($months as $month) {
                $snapshot = $month->snapshot_json ?? [];
                fputcsv($handle, [
                    $month->user_id,
                    $month->user?->name,
                    $month->year_month,
                    $snapshot['actual_work_minutes'] ?? 0,
                    $snapshot['prescribed_work_minutes'] ?? 0,
                    $snapshot['non_statutory_overtime_minutes'] ?? 0,
                    $snapshot['statutory_overtime_minutes'] ?? 0,
                    $snapshot['late_night_minutes'] ?? 0,
                    $snapshot['legal_holiday_work_minutes'] ?? 0,
                    $snapshot['company_holiday_work_minutes'] ?? 0,
                ]);
            }

            fclose($handle);
        }, 'attendance_'.$data['year_month'].'.csv', ['Content-Type' => 'text/csv']);
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

        // どのrequest_typeを会計/振込CSVの対象にするかはハードコードせず、
        // request_types.export_amount_field(金額として扱うform_dataのキー)の設定有無で判定する。
        $tasks = BackOfficeTask::query()
            ->with(['source.applicant', 'source.requestType'])
            ->whereIn('status', ['payment_scheduled', 'completed'])
            ->whereBetween('created_at', [
                Carbon::parse($data['from'])->startOfDay(),
                Carbon::parse($data['to'])->endOfDay(),
            ])
            ->get()
            ->filter(fn (BackOfficeTask $task) => $task->source instanceof WorkflowRequest
                && $task->source->requestType?->export_amount_field !== null)
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
            fputcsv($handle, ['task_id', 'title', 'applicant_name', 'amount', 'status', 'created_at']);

            foreach ($tasks as $task) {
                $amountField = $task->source?->requestType?->export_amount_field;

                fputcsv($handle, [
                    $task->id,
                    $task->title,
                    $task->source?->applicant?->name,
                    $amountField !== null ? ($task->source?->form_data[$amountField] ?? '') : '',
                    $task->status,
                    $task->created_at->toDateString(),
                ]);
            }

            fclose($handle);
        }, 'expenses_'.$data['from'].'_'.$data['to'].'.csv', ['Content-Type' => 'text/csv']);
    }
}
