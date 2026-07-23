<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoredEventResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UC-M003: 監査ログを確認する。EventStore(stored_events)を正の記録として直接検索する。
 * Projectionを別に持たない(EventStore自体が既に検索可能なテーブルであるため)。
 *
 * spatie/laravel-event-sourcingへの移行が完了するまでの間、未移行ドメインは
 * legacy_stored_eventsに書き込まれるが、本番リリース前の移行期間中であるため
 * ここでは追わず、移行済みドメイン(stored_events)のみを検索対象にする
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
#[OA\Tag(name: '監査ログ', description: 'EventStoreの監査ログ検索')]
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * ペイロード中でユーザーIDを表す代表的なキー(event_propertiesのcamelCase表記に変換して使う)。
     *
     * @var array<int, string>
     */
    private const ACTOR_KEYS = [
        'user_id', 'applicant_user_id', 'approver_user_id', 'approved_by_user_id',
        'submitted_by_user_id', 'returned_by_user_id', 'cancelled_by_user_id',
        'changed_by_user_id', 'assigned_by_user_id', 'assigned_user_id', 'edited_by_user_id',
        'closed_by_user_id', 'requested_by_user_id',
        'owner_user_id', 'registered_by_user_id', 'issued_by_user_id', 'disabled_by_user_id',
        'revoked_by_user_id', 'granted_by_user_id', 'actor_user_id', 'created_by_user_id',
        'updated_by_user_id', 'applied_by_user_id', 'confirmed_by_user_id', 'reissued_by_user_id',
    ];

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

        $query = EloquentStoredEvent::query();

        if (isset($data['aggregate_type'])) {
            $query->where('event_class', 'like', $data['aggregate_type'].'.%');
        }

        if (isset($data['aggregate_id'])) {
            $query->where('aggregate_uuid', $data['aggregate_id']);
        }

        if (isset($data['event_type'])) {
            $query->where('event_class', $data['event_type']);
        }

        if (isset($data['from'])) {
            $query->where('created_at', '>=', $data['from']);
        }

        if (isset($data['to'])) {
            $query->where('created_at', '<=', $data['to']);
        }

        if (isset($data['user_id'])) {
            $userId = (int) $data['user_id'];
            $query->where(function (Builder $sub) use ($userId) {
                foreach (self::ACTOR_KEYS as $key) {
                    $sub->orWhere('event_properties', 'like', '%"'.Str::camel($key).'":'.$userId.'%');
                }
            });
        }

        return $query->orderByDesc('id')->get()
            ->map(fn (EloquentStoredEvent $event) => (object) [
                'id' => $event->id,
                'event_id' => (string) $event->id,
                'aggregate_type' => Str::before($event->event_class, '.'),
                'aggregate_id' => $event->aggregate_uuid,
                'version' => $event->aggregate_version,
                'event_type' => $event->event_class,
                'payload' => $event->event_properties,
                'occurred_at' => Carbon::parse($event->created_at),
            ]);
    }
}
