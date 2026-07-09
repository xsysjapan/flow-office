<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\EventStore;
use App\Domain\Export\Events\ExportCreated;
use App\Http\Controllers\Controller;
use App\Models\BackOfficeTask;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UC-E002: 経費CSVを出力する / UC-B004 step5 (会計・振込CSV)。
 */
class ExportController extends Controller
{
    public function expenses(Request $request, EventStore $eventStore): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $tasks = BackOfficeTask::query()
            ->with(['source.applicant', 'source.requestType'])
            ->where('task_type', 'expense_reimbursement')
            ->whereIn('status', ['payment_scheduled', 'completed'])
            ->whereBetween('created_at', [
                Carbon::parse($data['from'])->startOfDay(),
                Carbon::parse($data['to'])->endOfDay(),
            ])
            ->get();

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
                fputcsv($handle, [
                    $task->id,
                    $task->title,
                    $task->source?->applicant?->name,
                    $task->source?->form_data['amount'] ?? '',
                    $task->status,
                    $task->created_at->toDateString(),
                ]);
            }

            fclose($handle);
        }, 'expenses_'.$data['from'].'_'.$data['to'].'.csv', ['Content-Type' => 'text/csv']);
    }
}
