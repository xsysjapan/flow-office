<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\DesignateLegalHoliday;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LegalHolidayDesignationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UC-C007: 法定休日「決めない方式」の週ごとの法定休日を指定する。
 * 本人または管理者が、申請時・月次確認時などいつでも指定・再指定できる
 * (月次が承認済み以降を除く。DesignateLegalHolidayHandler参照)。
 */
class LegalHolidayDesignationController extends Controller
{
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
