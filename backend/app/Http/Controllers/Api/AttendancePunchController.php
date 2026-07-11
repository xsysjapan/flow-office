<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance\Commands\CorrectAttendancePunch;
use App\Domain\Attendance\Commands\DeleteAttendancePunch;
use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendancePunchResource;
use App\Models\AttendancePunch;
use App\Models\PunchType;
use App\Models\Role;
use App\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * UC-A012: 打刻ログ。画面のクロックイン/クロックアウト(UC-A001〜A004)とは別に、
 * 将来ICカード端末やモバイル端末などから打刻を受け付けるための入口。
 * 打刻は参考情報であり、矛盾があっても記録自体は常に成功する
 * (矛盾なく1日分の勤務として組み立てられる場合のみ日次勤怠に反映される)。
 */
class AttendancePunchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $targetUserId = $this->resolveTargetUserId($request, $data['user_id'] ?? null);

        $punches = AttendancePunch::query()
            ->where('user_id', $targetUserId)
            ->when($data['from'] ?? null, fn ($query, $from) => $query->whereDate('work_date', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, $to) => $query->whereDate('work_date', '<=', $to))
            ->orderBy('work_date')
            ->orderBy('punched_at')
            ->get();

        return AttendancePunchResource::collection($punches);
    }

    public function store(Request $request, CommandBus $commandBus): AttendancePunchResource
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'work_date' => ['required', 'date'],
            'punch_type' => ['required', Rule::in(PunchType::values())],
            'punched_at' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'source' => ['required', 'string', 'max:50'],
            'note' => ['nullable', 'string'],
        ]);

        $targetUserId = $this->resolveTargetUserId($request, $data['user_id'] ?? null);

        $punch = $commandBus->dispatch(new RecordAttendancePunch(
            userId: $targetUserId,
            workDate: $data['work_date'],
            punchType: $data['punch_type'],
            punchedAt: $data['punched_at'],
            source: $data['source'],
            note: $data['note'] ?? null,
        ));

        return new AttendancePunchResource($punch);
    }

    /**
     * UC-A013: 打刻ログを訂正する。元の打刻行は「訂正済み」として残り、訂正後の値は
     * 新しい打刻行として追記される(打刻ログは追記のみ)。矛盾なく組み立てられる場合のみ
     * 対象日の日次勤怠に反映し直す。
     */
    public function update(Request $request, AttendancePunch $attendancePunch, CommandBus $commandBus): AttendancePunchResource
    {
        $this->authorizePunchOwner($request, $attendancePunch);

        $data = $request->validate([
            'punch_type' => ['required', Rule::in(PunchType::values())],
            'punched_at' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'reason' => ['required', 'string'],
        ]);

        $corrected = $commandBus->dispatch(new CorrectAttendancePunch(
            attendancePunchId: $attendancePunch->id,
            punchType: $data['punch_type'],
            punchedAt: $data['punched_at'],
            reason: $data['reason'],
            correctedByUserId: $request->user()->id,
        ));

        return new AttendancePunchResource($corrected);
    }

    /**
     * UC-A014: 打刻ログを削除する。行は物理削除せず「削除済み」として残す。
     */
    public function destroy(Request $request, AttendancePunch $attendancePunch, CommandBus $commandBus): AttendancePunchResource
    {
        $this->authorizePunchOwner($request, $attendancePunch);

        $data = $request->validate(['reason' => ['required', 'string']]);

        $commandBus->dispatch(new DeleteAttendancePunch(
            attendancePunchId: $attendancePunch->id,
            reason: $data['reason'],
            deletedByUserId: $request->user()->id,
        ));

        return new AttendancePunchResource($attendancePunch->refresh());
    }

    /**
     * 自分以外の打刻を記録・閲覧・訂正・削除できるのはadminのみ(将来の共有デバイス連携を
     * 想定した拡張点)。
     */
    private function resolveTargetUserId(Request $request, ?int $requestedUserId): int
    {
        $userId = $requestedUserId ?? $request->user()->id;

        abort_if(
            $userId !== $request->user()->id && ! $request->user()->hasRole(Role::ADMIN),
            403,
            '他の社員の打刻を記録・閲覧する権限がありません。'
        );

        return $userId;
    }

    private function authorizePunchOwner(Request $request, AttendancePunch $punch): void
    {
        abort_if(
            $punch->user_id !== $request->user()->id && ! $request->user()->hasRole(Role::ADMIN),
            403,
            '他の社員の打刻を訂正・削除する権限がありません。'
        );
    }
}
