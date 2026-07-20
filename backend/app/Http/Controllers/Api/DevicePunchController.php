<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\AuthenticationKey\Services\AuthenticationKeyResolver;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendancePunchResource;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\PunchType;
use App\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * UC-A020/UC-D002: 共有Android打刻リーダー・個人端末からの打刻。既存の`AttendancePunchController`
 * (人間のSanctumセッションを前提とする`resolveTargetUserId`)とは別の入口とし、端末トークン
 * (ability: `recorder:punch` または `punch:self`)で認証する。最終的には既存の
 * `RecordAttendancePunch`コマンドを共通で呼び出し、計算ロジックを複製しない
 * (docs/03-architecture.md 3.5)。
 */
#[OA\Tag(name: '端末打刻', description: '共有端末・個人端末からの打刻(docs/23-usecases-devices.md UC-D002)')]
class DevicePunchController extends Controller
{
    #[OA\Post(
        path: '/device-punches',
        operationId: 'devicePunches.store',
        summary: '端末から打刻を記録する(UC-A020)',
        tags: ['端末打刻'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['work_date', 'punch_type', 'punched_at'], properties: [new OA\Property(property: 'work_date', type: 'string', format: 'date'), new OA\Property(property: 'punch_type', type: 'string'), new OA\Property(property: 'punched_at', type: 'string', format: 'date-time'), new OA\Property(property: 'authentication_key_value', type: 'string', nullable: true), new OA\Property(property: 'offline', type: 'boolean', nullable: true), new OA\Property(property: 'idempotency_key', type: 'string', nullable: true), new OA\Property(property: 'note', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(
        Request $request,
        CommandBus $commandBus,
        AuthenticationKeyResolver $resolver,
        AttendanceCalculator $attendanceCalculator,
    ): AttendancePunchResource {
        $device = $request->user();
        abort_unless($device instanceof Device, 401);

        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'punch_type' => ['required', Rule::in(PunchType::values())],
            'punched_at' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'authentication_key_value' => ['nullable', 'string'],
            'offline' => ['nullable', 'boolean'],
            'idempotency_key' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
        ]);

        if ($device->owner_type === DeviceOwnerType::PERSONAL) {
            $targetUserId = $device->owner_user_id;
            $authenticationKeyId = null;
        } else {
            abort_if(empty($data['authentication_key_value']), 422, 'この端末での打刻には認証キー(NFCカード等)の提示が必要です。');
            $key = $resolver->resolve($data['authentication_key_value'], $device->id);
            $targetUserId = $key->user_id;
            $authenticationKeyId = $key->id;
        }

        $punch = $commandBus->dispatch(new RecordAttendancePunch(
            userId: $targetUserId,
            workDate: $data['work_date'],
            punchType: $data['punch_type'],
            punchedAt: $data['punched_at'],
            source: 'device:'.$device->device_type,
            note: $data['note'] ?? null,
            deviceId: $device->id,
            authenticationKeyId: $authenticationKeyId,
            actorUserId: $targetUserId,
            offline: $data['offline'] ?? false,
            idempotencyKey: $data['idempotency_key'] ?? null,
            requestId: $request->header('X-Request-Id'),
        ));

        $punch->loadMissing('user');
        $attendanceDay = AttendanceDay::query()
            ->where('user_id', $targetUserId)
            ->whereDate('work_date', $data['work_date'])
            ->first();
        $missingPunchCount = AttendanceDay::query()
            ->where('user_id', $targetUserId)
            ->whereDate('work_date', '>=', $punch->work_date->copy()->subDays(31))
            ->whereDate('work_date', '<', $punch->work_date)
            ->where('status', '!=', AttendanceDayStatus::CLOCKED_OUT)
            ->whereDoesntHave('calculation', fn ($query) => $query->where('absence_minutes', '>', 0))
            ->count();
        $workMinutes = null;
        if ($data['punch_type'] === PunchType::CLOCK_OUT && $attendanceDay?->status === AttendanceDayStatus::CLOCKED_OUT) {
            $calculation = $attendanceCalculator->calculate(
                $attendanceDay->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle'),
            );
            $workMinutes = $calculation['work_minutes'];
        }

        return (new AttendancePunchResource($punch))->additional([
            'user_name' => $punch->user?->name,
            'attendance_summary' => [
                'work_minutes' => $workMinutes,
                'missing_punch_count' => $missingPunchCount,
                'current_day_incomplete' => $data['punch_type'] === PunchType::CLOCK_OUT
                    && $attendanceDay?->status !== AttendanceDayStatus::CLOCKED_OUT,
            ],
        ]);
    }
}
