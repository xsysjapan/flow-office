<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoredEventResource;
use App\Models\StoredEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UC-M003: 監査ログを確認する。EventStore(stored_events)を正の記録として直接検索する。
 * Projectionを別に持たない(stored_events自体が既に検索可能なテーブルであるため)。
 */
class AuditLogController extends Controller
{
    /**
     * ペイロード中でユーザーIDを表す代表的なキー。JSON列に対する厳密検索がDBに依らず
     * 使えるよう、'"key":<id>' 形式のLIKE検索で近似する(完全な保証はしない簡易実装)。
     *
     * @var array<int, string>
     */
    private const ACTOR_KEYS = [
        'user_id', 'applicant_user_id', 'approver_user_id', 'approved_by_user_id',
        'submitted_by_user_id', 'returned_by_user_id', 'cancelled_by_user_id',
        'changed_by_user_id', 'assigned_by_user_id', 'assigned_user_id', 'edited_by_user_id',
        'closed_by_user_id', 'requested_by_user_id',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $events = $this->filteredQuery($request)
            ->orderByDesc('occurred_at')
            ->paginate(50);

        return StoredEventResource::collection($events);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $events = $this->filteredQuery($request)->orderByDesc('occurred_at')->get();

        return response()->streamDownload(function () use ($events) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['occurred_at', 'aggregate_type', 'aggregate_id', 'event_type', 'payload']);

            foreach ($events as $event) {
                fputcsv($handle, [
                    $event->occurred_at->toIso8601String(),
                    $event->aggregate_type,
                    $event->aggregate_id,
                    $event->event_type,
                    json_encode($event->payload),
                ]);
            }

            fclose($handle);
        }, 'audit_log.csv', ['Content-Type' => 'text/csv']);
    }

    private function filteredQuery(Request $request)
    {
        $data = $request->validate([
            'aggregate_type' => ['nullable', 'string'],
            'aggregate_id' => ['nullable', 'string'],
            'event_type' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = StoredEvent::query();

        if (! empty($data['aggregate_type'])) {
            $query->where('aggregate_type', $data['aggregate_type']);
        }

        if (! empty($data['aggregate_id'])) {
            $query->where('aggregate_id', $data['aggregate_id']);
        }

        if (! empty($data['event_type'])) {
            $query->where('event_type', $data['event_type']);
        }

        if (! empty($data['from'])) {
            $query->where('occurred_at', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->where('occurred_at', '<=', $data['to']);
        }

        if (! empty($data['user_id'])) {
            $userId = (int) $data['user_id'];
            $query->where(function ($sub) use ($userId) {
                foreach (self::ACTOR_KEYS as $key) {
                    $sub->orWhere('payload', 'like', '%"'.$key.'":'.$userId.'%');
                }
                $sub->orWhere(fn ($q) => $q->where('aggregate_type', 'user')->where('aggregate_id', (string) $userId));
            });
        }

        return $query;
    }
}
