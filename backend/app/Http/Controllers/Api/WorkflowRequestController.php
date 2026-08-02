<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowRequestHistoryEntryResource;
use App\Http\Resources\WorkflowRequestResource;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\EntityShare;
use App\Models\ExpenseClaim;
use App\Models\PaidLeaveRequest;
use App\Models\SpecialLeaveRequest;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestHistoryEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * UC-W002〜UC-W005: 汎用申請の作成・提出・承認・差戻し・取消。
 */
#[OA\Tag(name: '汎用申請', description: '申請の作成・提出・承認・差戻し・取消')]
class WorkflowRequestController extends Controller
{
    #[OA\Get(
        path: '/workflow-requests/mine',
        operationId: 'workflowRequests.mine',
        summary: '自分の申請一覧を取得する',
        tags: ['汎用申請'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexMine(Request $request): AnonymousResourceCollection
    {
        $requests = WorkflowRequest::query()
            ->with(['requestType', 'applicant', 'approver'])
            ->where('applicant_user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return WorkflowRequestResource::collection($requests);
    }

    /**
     * UC-W004: 自分が承認者に指定された申請の一覧。既定では承認待ち(submitted)のみを返すが、
     * ステータス・年月での絞り込みにより過去の承認履歴(approved/returned/cancelled)も
     * 検索できる(AttendanceController::monthsToApproveと同じ絞り込み+ページングの形)。
     * `status`を省略した場合は常に`submitted`のみを返す(既存呼び出し元との後方互換)。
     * 一方、「すべて」を明示的に検索したい場合は`status=all`を渡す(絞り込みなしの意味の
     * 予約語。draft状態の他人の下書きを検索させないよう、'draft'自体は許可しない)。
     */
    #[OA\Get(
        path: '/workflow-requests/to-approve',
        operationId: 'workflowRequests.toApprove',
        summary: '承認待ち申請一覧を取得する',
        tags: ['汎用申請'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['submitted', 'approved', 'returned', 'cancelled', 'all'])),
            new OA\Parameter(name: 'year_month', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexToApprove(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['submitted', 'approved', 'returned', 'cancelled', 'all'])],
            'year_month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $data['status'] ?? 'submitted';
        $yearMonth = $data['year_month'] ?? null;

        $requests = WorkflowRequest::query()
            ->with(['requestType', 'applicant', 'approver'])
            ->where('approver_user_id', $request->user()->id)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($yearMonth, function ($query, $yearMonth) {
                [$year, $month] = explode('-', $yearMonth);

                $query->whereYear('submitted_at', $year)->whereMonth('submitted_at', $month);
            })
            ->latest()
            ->paginate($data['per_page'] ?? 20);

        return WorkflowRequestResource::collection($requests);
    }

    #[OA\Get(
        path: '/workflow-requests/{workflowRequest}',
        operationId: 'workflowRequests.show',
        summary: '申請詳細を取得する',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function show(Request $request, WorkflowRequest $workflowRequest): JsonResponse
    {
        $workflowRequest->load(['requestType', 'applicant', 'approver', 'attachments']);

        // AppServiceProviderでJsonResource::withoutWrapping()しているため、他のエンドポイント
        // と同じくトップレベルを"data"でラップしない(WorkflowRequestResourceの形状に合わせる)。
        // resolve()経由で呼ぶことで、$this->when()が返すMissingValueが正しくフィルタされる
        // (toArray()を直接呼ぶとMissingValueがそのまま残ってしまう)。
        $data = (new WorkflowRequestResource($workflowRequest))->resolve($request);

        if ($workflowRequest->subject_type === null) {
            return response()->json($data);
        }

        $this->authorizeSubjectAccess($workflowRequest, $request->user());

        $data['subject'] = match ($workflowRequest->subject_type) {
            'attendance_month' => $this->buildAttendanceMonthSubject($workflowRequest->subject_id),
            'expense_claim' => $this->buildExpenseClaimSubject($workflowRequest->subject_id),
            'paid_leave_request' => $this->buildPaidLeaveRequestSubject($workflowRequest->subject_id),
            'special_leave_request' => $this->buildSpecialLeaveRequestSubject($workflowRequest->subject_id),
            default => null,
        };

        return response()->json($data);
    }

    /**
     * subject_type付きの行(月次勤怠申請・経費精算申請)の詳細を閲覧できるのは、
     * 申請者・承認者、またはentity_sharesで自分宛に共有されている場合のみ
     * (ルートCLAUDE.md「絶対に外してはいけない設計原則」12・docs/25参照の考え方を
     * 他ドメインにも適用)。
     */
    private function authorizeSubjectAccess(WorkflowRequest $workflowRequest, User $user): void
    {
        if ($user->id === $workflowRequest->applicant_user_id || $user->id === $workflowRequest->approver_user_id) {
            return;
        }

        $isShared = EntityShare::query()
            ->where('shareable_type', $workflowRequest->subject_type)
            ->where('shareable_id', $workflowRequest->subject_id)
            ->where('shared_with_user_id', $user->id)
            ->exists();

        abort_unless($isShared, 403, 'この申請を閲覧する権限がありません。');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildAttendanceMonthSubject(?string $subjectId): ?array
    {
        $month = AttendanceMonth::query()->find($subjectId);

        if ($month === null) {
            return null;
        }

        $days = AttendanceDay::query()
            ->with('breaks')
            ->where('user_id', $month->user_id)
            ->where('work_date', 'like', "{$month->year_month}%")
            ->orderBy('work_date')
            ->get();

        return [
            'type' => 'attendance_month',
            'id' => $month->id,
            'user_id' => $month->user_id,
            'year_month' => $month->year_month,
            'status' => $month->status,
            'submitted_at' => $month->submitted_at?->toIso8601String(),
            'approved_at' => $month->approved_at?->toIso8601String(),
            'returned_at' => $month->returned_at?->toIso8601String(),
            'return_comment' => $month->return_comment,
            'days' => $days->map(fn (AttendanceDay $day) => [
                'id' => $day->id,
                'work_date' => $day->work_date?->toDateString(),
                'status' => $day->status,
                'actual_start_at' => $day->actual_start_at?->toIso8601String(),
                'actual_end_at' => $day->actual_end_at?->toIso8601String(),
                'breaks' => $day->breaks->map(fn ($break) => [
                    'id' => $break->id,
                    'break_start_at' => $break->break_start_at?->toIso8601String(),
                    'break_end_at' => $break->break_end_at?->toIso8601String(),
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildExpenseClaimSubject(?string $subjectId): ?array
    {
        $claim = ExpenseClaim::query()->with('items.category')->find($subjectId);

        if ($claim === null) {
            return null;
        }

        return [
            'type' => 'expense_claim',
            'id' => $claim->id,
            'employee_id' => $claim->employee_id,
            'title' => $claim->title,
            'status' => $claim->status,
            'total_amount' => $claim->total_amount,
            'period_from' => $claim->period_from?->toDateString(),
            'period_to' => $claim->period_to?->toDateString(),
            'submitted_at' => $claim->submitted_at?->toIso8601String(),
            'approved_at' => $claim->approved_at?->toIso8601String(),
            'items' => $claim->items->map(fn ($item) => [
                'id' => $item->id,
                'category_id' => $item->category_id,
                'category_name' => $item->category?->name,
                'usage_date' => $item->usage_date?->toDateString(),
                'description' => $item->description,
                'amount' => $item->amount,
                'commuting_deduction_amount' => $item->commuting_deduction_amount,
                'reimbursement_amount' => $item->reimbursement_amount,
                'payment_bearer' => $item->payment_bearer,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPaidLeaveRequestSubject(?string $subjectId): ?array
    {
        $request = PaidLeaveRequest::query()->with(['user', 'approver'])->find($subjectId);

        if ($request === null) {
            return null;
        }

        return [
            'type' => 'paid_leave_request',
            'id' => $request->id,
            'user_id' => $request->user_id,
            'status' => $request->status,
            'target_date' => $request->target_date?->toDateString(),
            'leave_type' => $request->leave_type,
            'leave_type_label' => WorkflowRequestResource::leaveTypeLabel($request->leave_type),
            'hours' => $request->hours !== null ? (float) $request->hours : null,
            'requested_days' => (float) $request->requested_days,
            'reason' => $request->reason,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'approved_at' => $request->approved_at?->toIso8601String(),
            'returned_at' => $request->returned_at?->toIso8601String(),
            'cancelled_at' => $request->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildSpecialLeaveRequestSubject(?string $subjectId): ?array
    {
        $request = SpecialLeaveRequest::query()->with(['user', 'approver', 'specialLeaveType'])->find($subjectId);

        if ($request === null) {
            return null;
        }

        return [
            'type' => 'special_leave_request',
            'id' => $request->id,
            'user_id' => $request->user_id,
            'status' => $request->status,
            'target_date' => $request->target_date?->toDateString(),
            'leave_type' => $request->leave_type,
            'leave_type_label' => WorkflowRequestResource::leaveTypeLabel($request->leave_type),
            'special_leave_type_id' => $request->special_leave_type_id,
            'special_leave_type_name' => $request->specialLeaveType?->name,
            'hours' => $request->hours !== null ? (float) $request->hours : null,
            'requested_days' => (float) $request->requested_days,
            'reason' => $request->reason,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'approved_at' => $request->approved_at?->toIso8601String(),
            'returned_at' => $request->returned_at?->toIso8601String(),
            'cancelled_at' => $request->cancelled_at?->toIso8601String(),
        ];
    }

    #[OA\Post(
        path: '/workflow-requests',
        operationId: 'workflowRequests.store',
        summary: '申請の下書きを作成する',
        tags: ['汎用申請'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['request_type_code', 'title', 'form_data'], properties: [new OA\Property(property: 'request_type_code', type: 'string'), new OA\Property(property: 'title', type: 'string'), new OA\Property(property: 'form_data', type: 'object'), new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'request_type_code' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'form_data' => ['present', 'array'],
            'approver_user_id' => ['nullable', 'string', 'exists:users,id'],
        ]);

        $workflowRequest = $commandBus->dispatch(new DraftWorkflowRequest(
            requestTypeCode: $data['request_type_code'],
            applicantUserId: $request->user()->id,
            title: $data['title'],
            formData: $data['form_data'],
            approverUserId: $data['approver_user_id'] ?? null,
        ));

        $resource = new WorkflowRequestResource($workflowRequest->load(['requestType', 'applicant', 'approver']));

        return $resource->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/workflow-requests/{workflowRequest}/submit',
        operationId: 'workflowRequests.submit',
        summary: '申請を提出する',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function submit(Request $request, WorkflowRequest $workflowRequest, CommandBus $commandBus): WorkflowRequestResource
    {
        $data = $request->validate([
            'approver_user_id' => ['nullable', 'string', 'exists:users,id'],
        ]);

        $commandBus->dispatch(new SubmitWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            submittedByUserId: $request->user()->id,
            approverUserId: $data['approver_user_id'] ?? null,
        ));

        return new WorkflowRequestResource($workflowRequest->refresh()->load(['requestType', 'applicant', 'approver']));
    }

    #[OA\Post(
        path: '/workflow-requests/{workflowRequest}/approve',
        operationId: 'workflowRequests.approve',
        summary: '申請を承認する',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function approve(Request $request, WorkflowRequest $workflowRequest, CommandBus $commandBus): WorkflowRequestResource
    {
        $commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            approvedByUserId: $request->user()->id,
        ));

        return new WorkflowRequestResource($workflowRequest->refresh()->load(['requestType', 'applicant', 'approver']));
    }

    #[OA\Post(
        path: '/workflow-requests/{workflowRequest}/return',
        operationId: 'workflowRequests.return',
        summary: '申請を差し戻す',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['comment'], properties: [new OA\Property(property: 'comment', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function return(Request $request, WorkflowRequest $workflowRequest, CommandBus $commandBus): WorkflowRequestResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        $commandBus->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            returnedByUserId: $request->user()->id,
            comment: $data['comment'],
        ));

        return new WorkflowRequestResource($workflowRequest->refresh()->load(['requestType', 'applicant', 'approver']));
    }

    /**
     * UC-W003/UC-W004 コメント履歴: この申請の専用履歴Projection
     * (workflow_request_history_entries)を時系列で返す。stored_events(EventStore)を
     * 直接公開しない(イベントクラス名・payload形状への依存を避けるため。
     * docs/29-event-sourcing-framework-migration.md参照)。
     * 申請者・承認者・管理者のみ閲覧可能(汎用監査ログAPIとは別に、資源に紐づけて認可する)。
     */
    #[OA\Get(
        path: '/workflow-requests/{workflowRequest}/history',
        operationId: 'workflowRequests.history',
        summary: '申請の履歴を取得する',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function history(Request $request, WorkflowRequest $workflowRequest): AnonymousResourceCollection
    {
        $user = $request->user();

        abort_unless(
            $user->id === $workflowRequest->applicant_user_id
                || $user->id === $workflowRequest->approver_user_id
                || $user->hasRole('admin'),
            403,
            'この申請の履歴を閲覧する権限がありません。'
        );

        $entries = WorkflowRequestHistoryEntry::query()
            ->where('workflow_request_id', $workflowRequest->id)
            ->orderBy('occurred_at')
            ->get();

        return WorkflowRequestHistoryEntryResource::collection($entries);
    }

    #[OA\Post(
        path: '/workflow-requests/{workflowRequest}/cancel',
        operationId: 'workflowRequests.cancel',
        summary: '申請を取り消す',
        tags: ['汎用申請'],
        parameters: [new OA\Parameter(name: 'workflowRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function cancel(Request $request, WorkflowRequest $workflowRequest, CommandBus $commandBus): WorkflowRequestResource
    {
        $data = $request->validate(['reason' => ['required', 'string']]);

        $commandBus->dispatch(new CancelWorkflowRequest(
            workflowRequestId: $workflowRequest->id,
            cancelledByUserId: $request->user()->id,
            reason: $data['reason'],
        ));

        return new WorkflowRequestResource($workflowRequest->refresh()->load(['requestType', 'applicant', 'approver']));
    }
}
