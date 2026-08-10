<?php

namespace App\Http\Controllers\Api;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\Attendance\Commands\CorrectAttendancePunch;
use App\Domain\Attendance\Commands\DeleteAttendancePunch;
use App\Domain\Attendance\Commands\RecordAttendancePunch;
use App\Domain\Attendance\Services\AttendanceApproverAccess;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendancePunchResource;
use App\Models\AttendancePunch;
use App\Models\PunchType;
use App\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * UC-A012: 打刻ログ。画面のクロックイン/クロックアウト(UC-A001〜A004)とは別に、
 * 将来ICカード端末やモバイル端末などから打刻を受け付けるための入口。
 * 打刻は参考情報であり、矛盾があっても記録自体は常に成功する
 * (矛盾なく1日分の勤務として組み立てられる場合のみ日次勤怠に反映される)。
 */
#[OA\Tag(name: '打刻ログ', description: 'ICカード等を含む打刻ログ')]
class AttendancePunchController extends Controller
{
    #[OA\Get(
        path: '/attendance-punches',
        operationId: 'attendancePunches.index',
        summary: '打刻ログ一覧を取得する',
        tags: ['打刻ログ'],
        parameters: [new OA\Parameter(name: 'user_id', in: 'query', required: false, description: '省略時は自分自身。他の社員を指定できるのはadmin、またはfrom/toの期間の年月の承認者のみ', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'string', 'exists:users,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $targetUserId = $this->resolveViewableUserId(
            $request,
            $data['user_id'] ?? null,
            $this->candidateYearMonths($data['from'] ?? null, $data['to'] ?? null),
            '他の社員の打刻を閲覧する権限がありません。',
        );

        $punches = AttendancePunch::query()
            ->where('user_id', $targetUserId)
            ->when($data['from'] ?? null, fn ($query, $from) => $query->whereDate('work_date', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, $to) => $query->whereDate('work_date', '<=', $to))
            ->orderBy('work_date')
            ->orderBy('punched_at')
            ->get();

        return AttendancePunchResource::collection($punches);
    }

    #[OA\Post(
        path: '/attendance-punches',
        operationId: 'attendancePunches.store',
        summary: '打刻ログを記録する',
        tags: ['打刻ログ'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['work_date', 'punch_type', 'punched_at', 'source'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'work_date', type: 'string', format: 'date'), new OA\Property(property: 'punch_type', type: 'string'), new OA\Property(property: 'punched_at', type: 'string', format: 'date-time'), new OA\Property(property: 'source', type: 'string'), new OA\Property(property: 'note', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function store(Request $request, CommandBus $commandBus): AttendancePunchResource
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'string', 'exists:users,id'],
            'work_date' => ['required', 'date'],
            'punch_type' => ['required', Rule::in(PunchType::values())],
            'punched_at' => ['required', 'date', LocalDateTime::OFFSET_REQUIRED_RULE],
            'source' => ['required', 'string', 'max:50'],
            'note' => ['nullable', 'string'],
        ]);

        $targetUserId = $this->resolveTargetUserId($request, $data['user_id'] ?? null, '他の社員の打刻を記録・閲覧する権限がありません。');

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
    #[OA\Put(
        path: '/attendance-punches/{attendancePunch}',
        operationId: 'attendancePunches.update',
        summary: '打刻ログを訂正する',
        tags: ['打刻ログ'],
        parameters: [new OA\Parameter(name: 'attendancePunch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['punch_type', 'punched_at', 'reason'], properties: [new OA\Property(property: 'punch_type', type: 'string'), new OA\Property(property: 'punched_at', type: 'string', format: 'date-time'), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function update(Request $request, AttendancePunch $attendancePunch, CommandBus $commandBus): AttendancePunchResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $attendancePunch->user_id, '他の社員の打刻を訂正・削除する権限がありません。');

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
    #[OA\Delete(
        path: '/attendance-punches/{attendancePunch}',
        operationId: 'attendancePunches.destroy',
        summary: '打刻ログを削除する',
        tags: ['打刻ログ'],
        parameters: [new OA\Parameter(name: 'attendancePunch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function destroy(Request $request, AttendancePunch $attendancePunch, CommandBus $commandBus): AttendancePunchResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $attendancePunch->user_id, '他の社員の打刻を訂正・削除する権限がありません。');

        $data = $request->validate(['reason' => ['required', 'string']]);

        $commandBus->dispatch(new DeleteAttendancePunch(
            attendancePunchId: $attendancePunch->id,
            reason: $data['reason'],
            deletedByUserId: $request->user()->id,
        ));

        return new AttendancePunchResource($attendancePunch->refresh());
    }

    /**
     * 打刻ログの閲覧のみを、本人・管理者に加えて「対象期間の年月の承認者に指定されている場合」
     * まで許可する(UC-A009参照。AttendanceControllerと同じ判定)。書き込み系(記録・訂正・削除)
     * は引き続き`resolveTargetUserId`/`abortUnlessOwnerOrAdmin`(本人・管理者のみ)を使う。
     *
     * @param  string[]  $yearMonths
     */
    private function resolveViewableUserId(Request $request, ?string $requestedUserId, array $yearMonths, string $message): string
    {
        $userId = $requestedUserId ?? $request->user()->id;
        if ($userId === $request->user()->id) {
            return $userId;
        }

        $isAdmin = $this->currentTokenHasFullAccess($request)
            && app(EffectiveAccessResolver::class)->hasGlobalPermission($request->user(), 'attendance.read');
        $isApprover = app(AttendanceApproverAccess::class)->isApproverForAnyYearMonth($request->user()->id, $userId, $yearMonths);

        abort_if(! $isAdmin && ! $isApprover, 403, $message);

        return $userId;
    }

    /** from/toの範囲に含まれる年月(`Y-m`)を列挙する。どちらか一方でも欠けている場合は、
     *  無制限の期間を承認者の閲覧範囲チェックに使わせないため空配列を返す(本人・管理者のみ許可)。 */
    private function candidateYearMonths(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return [];
        }

        $months = [];
        $cursor = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth();
        while ($cursor->lte($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $months;
    }
}
