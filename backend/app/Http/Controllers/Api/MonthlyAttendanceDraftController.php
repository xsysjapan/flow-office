<?php

namespace App\Http\Controllers\Api;

use App\Domain\AttendanceImport\Commands\BulkUpdateAttendanceDays;
use App\Domain\AttendanceImport\Commands\ConfirmFieldProvenance;
use App\Domain\AttendanceImport\Commands\CreateMonthlyAttendanceDraft;
use App\Domain\AttendanceImport\Commands\SubmitMonthlyAttendanceDraft;
use App\Domain\AttendanceImport\Commands\ValidateMonthlyAttendanceDraft;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Resources\FieldProvenanceResource;
use App\Http\Resources\MonthlyAttendanceDraftResource;
use App\Models\FieldProvenance;
use App\Models\FieldSourceType;
use App\Models\MonthlyAttendanceDraft;
use App\Models\WorkLocationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * UC-R001〜UC-R002: 月次勤怠下書き(docs/26-usecases-monthly-import.md)。作業報告書からの
 * 月次一括作成の中核。既存のattendance_months(正式な月次勤怠)とは分離する。
 */
#[OA\Tag(name: '月次勤怠下書き', description: '作業報告書等からの月次勤怠下書き・一括更新・申請')]
class MonthlyAttendanceDraftController extends Controller
{
    #[OA\Get(path: '/attendance/monthly-drafts/mine', operationId: 'monthlyDrafts.indexMine', summary: '自分の月次勤怠下書き一覧を取得する', tags: ['月次勤怠下書き'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function indexMine(Request $request): AnonymousResourceCollection
    {
        $drafts = MonthlyAttendanceDraft::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return MonthlyAttendanceDraftResource::collection($drafts);
    }

    #[OA\Post(path: '/attendance/monthly-drafts', operationId: 'monthlyDrafts.store', summary: '月次勤怠下書きを作成する', tags: ['月次勤怠下書き'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'target_month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'source_type' => ['nullable', 'string'],
            'source_reference' => ['nullable', 'string'],
        ]);

        $draft = $commandBus->dispatch(new CreateMonthlyAttendanceDraft(
            userId: $request->user()->id,
            targetMonth: $data['target_month'],
            sourceType: $data['source_type'] ?? null,
            sourceReference: $data['source_reference'] ?? null,
            createdByUserId: $request->user()->id,
        ));

        return (new MonthlyAttendanceDraftResource($draft))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/attendance/monthly-drafts/{monthlyAttendanceDraft}', operationId: 'monthlyDrafts.show', summary: '月次勤怠下書きを取得する', tags: ['月次勤怠下書き'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function show(Request $request, MonthlyAttendanceDraft $monthlyAttendanceDraft): MonthlyAttendanceDraftResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $monthlyAttendanceDraft->user_id, '他の社員の月次勤怠下書きを閲覧する権限がありません。');

        return new MonthlyAttendanceDraftResource($monthlyAttendanceDraft);
    }

    /**
     * UC-R001「不明点の確認」: 下書きに紐づく各項目の出所(AI推定値か否か・確認状況)を
     * 一覧取得する。項目ごとの最新の記録のみを返す(BulkUpdateAttendanceDaysHandler等が
     * 同一項目を複数回追記しうるため)。
     */
    #[OA\Get(path: '/attendance/monthly-drafts/{monthlyAttendanceDraft}/fields', operationId: 'monthlyDrafts.fields', summary: '下書きの項目出所一覧を取得する', tags: ['月次勤怠下書き'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function fields(Request $request, MonthlyAttendanceDraft $monthlyAttendanceDraft): AnonymousResourceCollection
    {
        $this->abortUnlessOwnerOrAdmin($request, $monthlyAttendanceDraft->user_id, '他の社員の月次勤怠下書きを閲覧する権限がありません。');

        $provenances = FieldProvenance::query()
            ->where('entity_type', FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT)
            ->where('entity_id', $monthlyAttendanceDraft->id)
            ->orderByDesc('id')
            ->get()
            ->unique('field_name')
            ->sortBy('field_name')
            ->values();

        return FieldProvenanceResource::collection($provenances);
    }

    /**
     * UC-R001手順8: 一括更新API(docs/26「一括更新API」)。楽観ロック(expected_version)・
     * 冪等性(Idempotency-Keyヘッダー)に対応する。
     */
    #[OA\Put(
        path: '/attendance/monthly-drafts/{monthlyAttendanceDraft}/days',
        operationId: 'monthlyDrafts.bulkUpdateDays',
        summary: '日次勤怠を一括更新する',
        tags: ['月次勤怠下書き'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 409, description: 'Version conflict')],
    )]
    public function bulkUpdateDays(Request $request, MonthlyAttendanceDraft $monthlyAttendanceDraft, CommandBus $commandBus): JsonResponse
    {
        $this->abortUnlessOwnerOrAdmin($request, $monthlyAttendanceDraft->user_id, '他の社員の月次勤怠下書きを編集する権限がありません。');

        $data = $request->validate([
            'expected_version' => ['required', 'integer'],
            'days' => ['required', 'array', 'min:1'],
            'days.*.date' => ['required', 'date'],
            'days.*.startTime' => ['nullable', 'date_format:H:i'],
            'days.*.endTime' => ['nullable', 'date_format:H:i'],
            'days.*.breaks' => ['array'],
            'days.*.breaks.*.startTime' => ['required', 'date_format:H:i'],
            'days.*.breaks.*.endTime' => ['nullable', 'date_format:H:i'],
            'days.*.workLocationType' => ['nullable', Rule::in(WorkLocationType::values())],
            'days.*.workDescription' => ['nullable', 'string'],
            'days.*.source' => ['nullable', Rule::in(FieldSourceType::values())],
            'days.*.confidence' => ['nullable', 'string'],
        ]);

        $result = $commandBus->dispatch(new BulkUpdateAttendanceDays(
            draftId: $monthlyAttendanceDraft->id,
            expectedVersion: $data['expected_version'],
            days: $data['days'],
            updatedByUserId: $request->user()->id,
            idempotencyKey: $request->header('Idempotency-Key'),
        ));

        $rejected = collect($result['results'])->contains(fn ($r) => $r['status'] === 'REJECTED');

        return response()->json([
            'status' => $rejected ? 'PARTIALLY_ACCEPTED' : 'ACCEPTED',
            'draft' => new MonthlyAttendanceDraftResource($result['draft']),
            'results' => $result['results'],
        ]);
    }

    #[OA\Post(path: '/attendance/monthly-drafts/{monthlyAttendanceDraft}/validate', operationId: 'monthlyDrafts.validate', summary: '月次勤怠下書きを検証する', tags: ['月次勤怠下書き'], responses: [new OA\Response(response: 200, description: 'Successful response')])]
    public function validateDraft(Request $request, MonthlyAttendanceDraft $monthlyAttendanceDraft, CommandBus $commandBus): JsonResponse
    {
        $this->abortUnlessOwnerOrAdmin($request, $monthlyAttendanceDraft->user_id, '他の社員の月次勤怠下書きを検証する権限がありません。');

        $result = $commandBus->dispatch(new ValidateMonthlyAttendanceDraft($monthlyAttendanceDraft->id));

        return response()->json([
            'draft' => new MonthlyAttendanceDraftResource($result['draft']),
            'unconfirmed_fields' => $result['unconfirmedFields'],
        ]);
    }

    /**
     * UC-R002手順3: ユーザーの明示的な指示によってのみ呼び出すことを前提とする
     * (docs/26「月次申請」)。
     */
    #[OA\Post(
        path: '/attendance/monthly-drafts/{monthlyAttendanceDraft}/submit',
        operationId: 'monthlyDrafts.submit',
        summary: '月次勤怠を申請する',
        tags: ['月次勤怠下書き'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['approver_user_id'], properties: [new OA\Property(property: 'approver_user_id', type: 'integer')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function submit(Request $request, MonthlyAttendanceDraft $monthlyAttendanceDraft, CommandBus $commandBus): MonthlyAttendanceDraftResource
    {
        $this->abortUnlessOwnerOrAdmin($request, $monthlyAttendanceDraft->user_id, '他の社員の月次勤怠を申請する権限がありません。');

        $data = $request->validate(['approver_user_id' => ['required', 'integer', 'exists:users,id']]);

        $draft = $commandBus->dispatch(new SubmitMonthlyAttendanceDraft(
            draftId: $monthlyAttendanceDraft->id,
            approverUserId: $data['approver_user_id'],
            submittedByUserId: $request->user()->id,
        ));

        return new MonthlyAttendanceDraftResource($draft);
    }

    /**
     * UC-R001「不明点の確認」: AI推定値をユーザーが確認したことを記録する。
     * 確認対象の項目が対象社員の下書きに属することを検証する。
     */
    #[OA\Post(
        path: '/attendance/monthly-drafts/{monthlyAttendanceDraft}/fields/{fieldProvenance}/confirm',
        operationId: 'monthlyDrafts.confirmField',
        summary: 'AI推定値を確認する',
        tags: ['月次勤怠下書き'],
        responses: [new OA\Response(response: 200, description: 'Successful response')],
    )]
    public function confirmField(Request $request, MonthlyAttendanceDraft $monthlyAttendanceDraft, FieldProvenance $fieldProvenance, CommandBus $commandBus): JsonResponse
    {
        $this->abortUnlessOwnerOrAdmin($request, $monthlyAttendanceDraft->user_id, '他の社員の月次勤怠下書きを操作する権限がありません。');

        abort_unless(
            $fieldProvenance->entity_type === FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT
                && $fieldProvenance->entity_id === $monthlyAttendanceDraft->id,
            404,
        );

        $provenance = $commandBus->dispatch(new ConfirmFieldProvenance(
            fieldProvenanceId: $fieldProvenance->id,
            confirmedByUserId: $request->user()->id,
        ));

        return response()->json([
            'field_name' => $provenance->field_name,
            'confirmed_at' => $provenance->confirmed_at?->toIso8601String(),
        ]);
    }
}
