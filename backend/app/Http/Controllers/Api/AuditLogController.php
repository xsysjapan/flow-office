<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\Support\EventHistoryQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoredEventResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UC-M003: 監査ログを確認する。EventStore(legacy_stored_events / stored_events)を
 * 正の記録として直接検索する。Projectionを別に持たない(EventStore自体が既に検索可能な
 * テーブルであるため)。ドメインの移行状況によりテーブルが2系統に分かれているため、
 * 検索自体はApp\Domain\EventSourcing\Support\EventHistoryQueryに委ねる
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
#[OA\Tag(name: '監査ログ', description: 'EventStoreの監査ログ検索')]
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly EventHistoryQuery $historyQuery) {}

    #[OA\Get(
        path: '/audit-log',
        operationId: 'auditLog.index',
        summary: '監査ログを検索する',
        tags: ['監査ログ'],
        parameters: [new OA\Parameter(name: 'aggregate_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'aggregate_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'event_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $events = $this->search($request);

        $page = (int) $request->query('page', 1);
        $items = $events->forPage($page, self::PER_PAGE)->values();

        $paginator = new LengthAwarePaginator(
            $items,
            $events->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return StoredEventResource::collection($paginator);
    }

    #[OA\Get(
        path: '/audit-log/export',
        operationId: 'auditLog.exportCsv',
        summary: '監査ログCSVを出力する',
        tags: ['監査ログ'],
        parameters: [new OA\Parameter(name: 'aggregate_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'aggregate_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'event_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function exportCsv(Request $request): StreamedResponse
    {
        $events = $this->search($request);

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

    /**
     * @return Collection<int, object>
     */
    private function search(Request $request): Collection
    {
        $data = $request->validate([
            'aggregate_type' => ['nullable', 'string'],
            'aggregate_id' => ['nullable', 'string'],
            'event_type' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return $this->historyQuery->search(
            aggregateType: $data['aggregate_type'] ?? null,
            aggregateId: $data['aggregate_id'] ?? null,
            eventType: $data['event_type'] ?? null,
            userId: isset($data['user_id']) ? (int) $data['user_id'] : null,
            from: $data['from'] ?? null,
            to: $data['to'] ?? null,
        );
    }
}
