<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\Notification\Commands\ConfirmNotification;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * UC-N001: 自分宛ての通知の一覧・既読管理。
 */
#[OA\Tag(name: '通知', description: '自分宛て通知の一覧・確認')]
class NotificationController extends Controller
{
    #[OA\Get(
        path: '/notifications/mine',
        operationId: 'notifications.mine',
        summary: '自分宛ての通知一覧を取得する',
        description: 'status(unread/read)で絞り込んでページングして返す。meta.status_counts(all/unread/read)は自分宛て通知の件数で、statusに影響されない。',
        tags: ['通知'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['unread', 'read']))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexMine(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:unread,read'],
        ]);
        $userId = $request->user()->id;

        $notifications = Notification::query()
            ->where('recipient_user_id', $userId)
            ->when(($data['status'] ?? null) === 'unread', fn ($query) => $query->whereNull('confirmed_at'))
            ->when(($data['status'] ?? null) === 'read', fn ($query) => $query->whereNotNull('confirmed_at'))
            ->latest('queued_at')
            ->paginate(20);

        return NotificationResource::collection($notifications)->additional([
            'meta' => ['status_counts' => $this->statusCounts($userId)],
        ]);
    }

    /**
     * 通知の状態別件数(all/unread/read)。statusは適用しない(タブの件数に使う)。
     *
     * @return array{all: int, unread: int, read: int}
     */
    private function statusCounts(string $userId): array
    {
        $row = Notification::query()
            ->where('recipient_user_id', $userId)
            ->selectRaw(
                'COUNT(*) AS all_count, '
                .'SUM(CASE WHEN confirmed_at IS NULL THEN 1 ELSE 0 END) AS unread_count, '
                .'SUM(CASE WHEN confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS read_count',
            )
            ->first();

        return [
            'all' => (int) $row->all_count,
            'unread' => (int) $row->unread_count,
            'read' => (int) $row->read_count,
        ];
    }

    #[OA\Post(
        path: '/notifications/{notification}/confirm',
        operationId: 'notifications.confirm',
        summary: '通知を確認済みにする',
        tags: ['通知'],
        parameters: [new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function confirm(Request $request, Notification $notification, CommandBus $commandBus): NotificationResource
    {
        $commandBus->dispatch(new ConfirmNotification(
            notificationId: $notification->id,
            confirmedByUserId: $request->user()->id,
        ));

        return new NotificationResource($notification->refresh());
    }
}
