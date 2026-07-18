<?php

namespace App\Http\Controllers\Api;

use App\Domain\AuthenticationKey\Services\AuthenticationKeyResolver;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * 外部端末(入退室管理端末等)が、打刻を伴わずに認証キーから社員本人を特定するための入口
 * (docs/23-usecases-devices.md UC-D004、スコープ`identity:resolve`)。
 * 勤怠イベントは記録しない(打刻とは別の関心事。docs/03-architecture.md 3.6)。
 */
#[OA\Tag(name: '端末打刻', description: '共有端末・個人端末からの打刻(docs/23-usecases-devices.md UC-D002)')]
class DeviceIdentityController extends Controller
{
    #[OA\Post(
        path: '/devices/identity/resolve',
        operationId: 'devices.identity.resolve',
        summary: '認証キーから社員本人を特定する(打刻は行わない)',
        tags: ['端末打刻'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['authentication_key_value'], properties: [new OA\Property(property: 'authentication_key_value', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function resolve(Request $request, AuthenticationKeyResolver $resolver): JsonResponse
    {
        $device = $request->user();
        abort_unless($device instanceof Device, 401);

        $data = $request->validate(['authentication_key_value' => ['required', 'string']]);

        $key = $resolver->resolve($data['authentication_key_value'], $device->id);
        $key->loadMissing('user');

        return response()->json([
            'user_id' => $key->user_id,
            'name' => $key->user->name,
            'authentication_key_id' => $key->id,
        ]);
    }
}
