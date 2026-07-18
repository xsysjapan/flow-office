<?php

namespace App\Http\Controllers\Api;

use App\Domain\Device\Commands\ExchangeDevicePairingCode;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * UC-D002: 端末アプリがペアリングコードをSanctumトークンへ交換する。この時点では端末は
 * まだトークンを持たないため、既存のSSOトークン交換フロー(AuthController::token)と同様
 * auth:sanctumの外側で提供する(ペアリングコード自体が一度きりの短命な認証材料)。
 */
#[OA\Tag(name: '端末管理', description: '共有端末・個人端末・外部端末の登録・ペアリング・停止/失効')]
class DevicePairingController extends Controller
{
    #[OA\Post(
        path: '/devices/pairing/exchange',
        operationId: 'devices.pairing.exchange',
        summary: 'ペアリングコードをトークンに交換する(UC-D002)',
        tags: ['端末管理'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['device_id', 'pairing_code'], properties: [new OA\Property(property: 'device_id', type: 'integer'), new OA\Property(property: 'pairing_code', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function exchange(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'pairing_code' => ['required', 'string'],
        ]);

        $result = $commandBus->dispatch(new ExchangeDevicePairingCode(
            deviceId: $data['device_id'],
            pairingCode: $data['pairing_code'],
        ));

        return response()->json([
            'device' => new DeviceResource($result['device']),
            'token' => $result['plainTextToken'],
        ]);
    }
}
