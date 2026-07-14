<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\DesignateLegalHoliday;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LegalHolidayDesignationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * UC-C007: 法定休日「決めない方式」の週ごとの法定休日を指定する。
 * 本人または管理者が、申請時・月次確認時などいつでも指定・再指定できる
 * (月次が承認済み以降を除く。DesignateLegalHolidayHandler参照)。
 */
#[OA\Tag(name: '法定休日指定', description: '週ごとの法定休日指定')]
class LegalHolidayDesignationController extends Controller
{
    #[OA\Post(
        path: '/attendance/legal-holiday-designations',
        operationId: 'legalHolidayDesignations.store',
        summary: '週の法定休日を指定する',
        tags: ['法定休日指定'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'week_start_date', 'designated_date', 'reason'], properties: [new OA\Property(property: 'user_id', type: 'integer'), new OA\Property(property: 'week_start_date', type: 'string', format: 'date'), new OA\Property(property: 'designated_date', type: 'string', format: 'date'), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'week_start_date' => ['required', 'date'],
            'designated_date' => ['required', 'date'],
            'reason' => ['required', 'string'],
        ]);

        $this->abortUnlessOwnerOrAdmin($request, $data['user_id'], '他の社員の法定休日を指定する権限がありません。');

        $designation = $commandBus->dispatch(new DesignateLegalHoliday(
            userId: $data['user_id'],
            weekStartDate: $data['week_start_date'],
            designatedDate: $data['designated_date'],
            reason: $data['reason'],
            designatedByUserId: $request->user()->id,
        ));

        return (new LegalHolidayDesignationResource($designation))->response()->setStatusCode(201);
    }
}
