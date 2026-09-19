<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\MigratePaidLeaveAccountsCommand;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Leave\Support\LeaveHistoryQuery;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest as ApprovePaidLeaveRequestCommand;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\MigratePaidLeaveAccount;
use App\Domain\PaidLeaveAccount\Commands\RevokePaidLeaveGrant;
use App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\ReturnWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaidLeaveGrantResource;
use App\Http\Resources\PaidLeaveGrantRuleResource;
use App\Http\Resources\PaidLeaveGrantRuleTargetUserResource;
use App\Http\Resources\PaidLeaveRequestResource;
use App\Http\Resources\PaidLeaveUsageResource;
use App\Http\Resources\StoredEventResource;
use App\Jobs\ReapplyPaidLeaveSchedulePolicyJob;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveProportionalGrantPolicy;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use App\Models\PaidLeaveType;
use App\Models\PaidLeaveUsage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use App\Models\WorkStyle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * 有給残数管理・申請・承認 (docs/09-usecases-paid-leave.md UC-P001〜UC-P004, docs/21-mvp-scope.md)。
 * 継続勤務期間・出勤率に基づく自動付与バッチ、消滅警告、年5日取得義務警告は後続フェーズで実装する。
 */
#[OA\Tag(name: '有給休暇', description: '有給付与・申請・承認')]
class PaidLeaveController extends Controller
{
    #[OA\Get(
        path: '/paid-leave/grant-rules',
        operationId: 'paidLeave.grantRules.index',
        summary: '有給付与ルール一覧を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function indexRules(): AnonymousResourceCollection
    {
        return PaidLeaveGrantRuleResource::collection(
            PaidLeaveGrantRule::query()->with('steps')->orderBy('name')->get()
        );
    }

    #[OA\Post(
        path: '/paid-leave/grant-rules',
        operationId: 'paidLeave.grantRules.store',
        summary: '有給付与ルールを作成する',
        tags: ['有給休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'work_style_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'min_attendance_rate', type: 'integer'), new OA\Property(property: 'first_grant_after_months', type: 'integer'), new OA\Property(property: 'grant_cycle_months', type: 'integer'), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'work_style_id' => ['nullable', 'string', 'exists:work_styles,id'],
            'min_attendance_rate' => ['integer', 'between:0,100'],
            'first_grant_after_months' => ['integer', 'min:0'],
            'grant_cycle_months' => ['integer', 'min:1'],
            'grant_cycle_type' => ['string', 'in:anniversary,mass_grant_month'],
            'mass_grant_month' => ['required_if:grant_cycle_type,mass_grant_month', 'nullable', 'integer', 'between:1,12'],
            'is_active' => ['boolean'],
            'steps' => ['array'],
            'steps.*.continuous_service_months' => ['required', 'integer', 'min:0'],
            'steps.*.grant_days' => ['required', 'integer', 'min:0'],
        ]);

        $data['grant_cycle_type'] = $data['grant_cycle_type'] ?? PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY;
        $data['mass_grant_month'] = $data['grant_cycle_type'] === PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH
            ? ($data['mass_grant_month'] ?? null)
            : null;

        $this->validateStepsAgainstStatutoryMinimum($data['work_style_id'] ?? null, $data['steps'] ?? []);

        $rule = PaidLeaveGrantRule::query()->create($data);

        foreach ($data['steps'] ?? [] as $step) {
            $rule->steps()->create($step);
        }

        ReapplyPaidLeaveSchedulePolicyJob::dispatch("付与ルール「{$rule->name}」の作成");

        return (new PaidLeaveGrantRuleResource($rule->load('steps')))->response()->setStatusCode(201);
    }

    /**
     * 有給付与ルールの内容を編集する(spec.md 論点15-1「編集」。`is_active`単独の
     * 切替=「無効化」とは意味的に区別するが、本エンドポイントでも`is_active`の変更は
     * 受け付ける。ステップの法定最低日数チェックは`storeRule`と同じ)。
     */
    #[OA\Put(
        path: '/paid-leave/grant-rules/{rule}',
        operationId: 'paidLeave.grantRules.update',
        summary: '有給付与ルールを編集する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'work_style_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'min_attendance_rate', type: 'integer'), new OA\Property(property: 'first_grant_after_months', type: 'integer'), new OA\Property(property: 'grant_cycle_months', type: 'integer'), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function updateRule(Request $request, PaidLeaveGrantRule $rule): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'work_style_id' => ['nullable', 'string', 'exists:work_styles,id'],
            'min_attendance_rate' => ['integer', 'between:0,100'],
            'first_grant_after_months' => ['integer', 'min:0'],
            'grant_cycle_months' => ['integer', 'min:1'],
            'grant_cycle_type' => ['string', 'in:anniversary,mass_grant_month'],
            'mass_grant_month' => ['required_if:grant_cycle_type,mass_grant_month', 'nullable', 'integer', 'between:1,12'],
            'is_active' => ['boolean'],
            'steps' => ['array'],
            'steps.*.continuous_service_months' => ['required', 'integer', 'min:0'],
            'steps.*.grant_days' => ['required', 'integer', 'min:0'],
        ]);

        // grant_cycle_type/mass_grant_monthはリクエストに含まれている場合のみ更新する。
        // 既存の編集フォームはこれらのフィールドを送信しないため、無条件に既定値
        // (anniversary/null)で上書きすると、一斉付与ルールの設定が編集のたびに
        // 意図せずリセットされてしまう(未送信時は既存値を保持するpartial updateとする)。
        if (array_key_exists('grant_cycle_type', $data)) {
            $data['mass_grant_month'] = $data['grant_cycle_type'] === PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH
                ? ($data['mass_grant_month'] ?? null)
                : null;
        } else {
            unset($data['mass_grant_month']);
        }

        $this->validateStepsAgainstStatutoryMinimum($data['work_style_id'] ?? null, $data['steps'] ?? []);

        $rule->update($data);

        // stepsは全置換とする(1つ1つの継続勤務月数ステップに独立したIDを持たせて
        // 部分更新するUIは想定しておらず、ルール編集フォーム全体を都度送信する前提のため)。
        $rule->steps()->delete();
        foreach ($data['steps'] ?? [] as $step) {
            $rule->steps()->create($step);
        }

        ReapplyPaidLeaveSchedulePolicyJob::dispatch("付与ルール「{$rule->name}」の編集");

        return response()->json((new PaidLeaveGrantRuleResource($rule->load('steps')))->toArray($request));
    }

    /**
     * spec.md 論点15-1「削除」。同条は「無効化(is_active=false)ではなく削除」を
     * 明示的に別導線として要求しつつ、CLAUDE.md原則2(Projectionは再生成可能な派生データ)・
     * 監査可能性への配慮から、物理削除は既に無効化済み(is_active=false)のルールに限って
     * 許可する(実装時の判断。詳細はPhase D実装結果として変更セットに追記する)。
     * 稼働中(is_active=true)のルールをいきなり物理削除すると、そのルールを参照して
     * 生成済みのScheduleエントリ・Grantの説明責任(なぜその日数だったか)を辿る手掛かりが
     * 急に失われるため、「無効化してから削除」の2段階操作を強制する。
     */
    #[OA\Delete(
        path: '/paid-leave/grant-rules/{rule}',
        operationId: 'paidLeave.grantRules.destroy',
        summary: '無効化済みの有給付与ルールを削除する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 204, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function destroyRule(PaidLeaveGrantRule $rule): JsonResponse
    {
        if ($rule->is_active) {
            throw new DomainRuleException('有効な付与ルールは削除できません。先に無効化してください。');
        }

        $rule->steps()->delete();
        $rule->delete();

        return response()->json(null, 204);
    }

    /**
     * frontend/PaidLeavePolicyPage向け: 法定通常付与表・比例付与表を読み取り専用マトリクスとして
     * 返す(spec.md 論点14(3)・15-2「比例付与のトグルは置かず、法定Policyの自動適用を
     * 参考情報として表示する」)。各表の最新有効versionのみを返す(過去versionは
     * 既存Schedule/Assessmentの根拠としてDB上に残るが、画面表示は最新版で十分)。
     */
    #[OA\Get(
        path: '/paid-leave/grant-policies',
        operationId: 'paidLeave.grantPolicies.index',
        summary: '法定通常付与表・比例付与表(最新version)を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function indexGrantPolicies(): JsonResponse
    {
        $normalVersion = PaidLeaveGrantPolicy::latestVersion() ?? 'v1';
        $proportionalVersion = PaidLeaveProportionalGrantPolicy::latestVersion() ?? 'v1';

        $normal = PaidLeaveGrantPolicy::query()
            ->where('version', $normalVersion)
            ->where('is_active', true)
            ->orderBy('continuous_service_months')
            ->get(['continuous_service_months', 'grant_days']);

        $proportional = PaidLeaveProportionalGrantPolicy::query()
            ->where('version', $proportionalVersion)
            ->where('is_active', true)
            ->orderBy('weekly_scheduled_days_category')
            ->orderBy('continuous_service_months')
            ->get(['weekly_scheduled_days_category', 'continuous_service_months', 'grant_days']);

        return response()->json([
            'data' => [
                'version' => $normalVersion,
                'normal' => $normal,
                'proportional_version' => $proportionalVersion,
                'proportional' => $proportional,
            ],
        ]);
    }

    /**
     * `version`列(文字列)の次バージョンを採番する。既存versionが`v<数値>`形式であれば
     * その最大値+1を、そうでなければ現在のversion数+1を採用する(spec.md Feature 5。
     * `latestVersion()`同様、単純な数値インクリメント列を持たないためここで解決する)。
     */
    private function nextGrantPolicyVersion(string $modelClass): string
    {
        $versions = $modelClass::query()->distinct()->pluck('version');

        $maxNumber = 0;
        foreach ($versions as $version) {
            if (preg_match('/^v(\d+)$/', (string) $version, $matches) === 1) {
                $maxNumber = max($maxNumber, (int) $matches[1]);
            }
        }

        if ($maxNumber > 0) {
            return 'v'.($maxNumber + 1);
        }

        return 'v'.($versions->count() + 1);
    }

    /**
     * 法定通常付与表(`paid_leave_grant_policies`)の新バージョンを作成する
     * (docs/changesets/20260914-port-to-pr112/spec.md Feature 5)。法務判断が必要な値
     * (CLAUDE.md原則8)のため、既存versionは一切変更せず新versionとして追記する。
     */
    #[OA\Post(
        path: '/paid-leave/grant-policies',
        operationId: 'paidLeave.grantPolicies.store',
        summary: '法定通常付与表の新バージョンを作成する',
        tags: ['有給休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['rows'], properties: [new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeGrantPolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.continuous_service_months' => ['required', 'integer', 'min:0'],
            'rows.*.grant_days' => ['required', 'numeric', 'min:0'],
        ]);

        $months = array_map(fn (array $row) => $row['continuous_service_months'], $data['rows']);
        if (count($months) !== count(array_unique($months))) {
            throw ValidationException::withMessages([
                'rows' => ['continuous_service_monthsは重複できません。'],
            ]);
        }

        $version = DB::transaction(function () use ($data) {
            $version = $this->nextGrantPolicyVersion(PaidLeaveGrantPolicy::class);

            foreach ($data['rows'] as $row) {
                PaidLeaveGrantPolicy::query()->create([
                    'version' => $version,
                    'continuous_service_months' => $row['continuous_service_months'],
                    'grant_days' => $row['grant_days'],
                    'effective_from' => now()->toDateString(),
                    'is_active' => true,
                ]);
            }

            return $version;
        });

        ReapplyPaidLeaveSchedulePolicyJob::dispatch("法定通常付与表({$version})の作成");

        $rows = PaidLeaveGrantPolicy::query()
            ->where('version', $version)
            ->orderBy('continuous_service_months')
            ->get(['continuous_service_months', 'grant_days']);

        return response()->json(['data' => ['version' => $version, 'normal' => $rows]], 201);
    }

    /**
     * 法定比例付与表(`paid_leave_proportional_grant_policies`)の新バージョンを作成する。
     * `weekly_scheduled_days_category`はこの表が実際に管理する区分キー('1'〜'4'週日数)
     * であり、既存モデル・GETレスポンスと同じ形式で受け取る。
     */
    #[OA\Post(
        path: '/paid-leave/proportional-grant-policies',
        operationId: 'paidLeave.proportionalGrantPolicies.store',
        summary: '法定比例付与表の新バージョンを作成する',
        tags: ['有給休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['rows'], properties: [new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeProportionalGrantPolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.weekly_scheduled_days_category' => ['required', 'string', 'in:1,2,3,4'],
            'rows.*.continuous_service_months' => ['required', 'integer', 'min:0'],
            'rows.*.grant_days' => ['required', 'numeric', 'min:0'],
        ]);

        $keys = array_map(
            fn (array $row) => $row['weekly_scheduled_days_category'].'-'.$row['continuous_service_months'],
            $data['rows'],
        );
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages([
                'rows' => ['weekly_scheduled_days_categoryとcontinuous_service_monthsの組み合わせは重複できません。'],
            ]);
        }

        $version = DB::transaction(function () use ($data) {
            $version = $this->nextGrantPolicyVersion(PaidLeaveProportionalGrantPolicy::class);

            foreach ($data['rows'] as $row) {
                PaidLeaveProportionalGrantPolicy::query()->create([
                    'version' => $version,
                    'weekly_scheduled_days_category' => $row['weekly_scheduled_days_category'],
                    'continuous_service_months' => $row['continuous_service_months'],
                    'grant_days' => $row['grant_days'],
                    'effective_from' => now()->toDateString(),
                    'is_active' => true,
                ]);
            }

            return $version;
        });

        ReapplyPaidLeaveSchedulePolicyJob::dispatch("法定比例付与表({$version})の作成");

        $rows = PaidLeaveProportionalGrantPolicy::query()
            ->where('version', $version)
            ->orderBy('weekly_scheduled_days_category')
            ->orderBy('continuous_service_months')
            ->get(['weekly_scheduled_days_category', 'continuous_service_months', 'grant_days']);

        return response()->json(['data' => ['version' => $version, 'proportional' => $rows]], 201);
    }

    /**
     * ある有給付与ルールが現在対象としている社員の一覧(対象社員ごとの自動付与ON/OFF状態付き)を
     * 取得する。付与済み日数等の計算は行わない一覧表示専用の軽量エンドポイント
     * (docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md 論点4・5)。
     * 対象条件(work_style_id一致・hire_date設定済み)は
     * 有給付与ルールが対象とする社員の判定と同じ考え方だが、
     * タイムゾーン・利用開始日の到来判定は行わない(日次バッチの適格性判定ではなく、
     * 「このルールが現在どの社員を対象にしているか」の静的な一覧のため)。
     */
    #[OA\Get(
        path: '/paid-leave/grant-rules/{rule}/target-users',
        operationId: 'paidLeave.grantRules.targetUsers',
        summary: '有給付与ルールの対象社員一覧を取得する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function targetUsers(Request $request, PaidLeaveGrantRule $rule): JsonResponse
    {
        $query = User::query()->whereNotNull('hire_date');

        if ($rule->work_style_id !== null) {
            $userIds = EmployeeCalendarEntry::query()
                ->where('work_style_id', $rule->work_style_id)
                ->whereDate('work_date', Carbon::now()->toDateString())
                ->pluck('user_id');
            $query->whereIn('id', $userIds);
        }

        $workStyleName = $rule->work_style_id !== null ? $rule->workStyle?->name : null;

        // AppServiceProviderでJsonResource::withoutWrapping()しているため、他のエンドポイント
        // と同じくトップレベルを"data"でラップしない。1件ずつPaidLeaveGrantRuleTargetUserResource
        // で整形した配列をそのままトップレベルとして返す。
        $data = $query->orderBy('name')->get()
            ->map(fn (User $user) => (new PaidLeaveGrantRuleTargetUserResource($user, $workStyleName))->resolve($request))
            ->all();

        return response()->json($data);
    }

    /**
     * 有給残数を確認する (UC-A007 有給残数表示の元データ)。
     */
    #[OA\Get(
        path: '/paid-leave/grants/mine',
        operationId: 'paidLeave.grants.mine',
        summary: '自分の有給残数を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myGrants(Request $request): AnonymousResourceCollection
    {
        $grants = PaidLeaveGrant::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('expires_on')
            ->get();

        return PaidLeaveGrantResource::collection($grants);
    }

    #[OA\Get(
        path: '/paid-leave/grants/user/{userId}',
        operationId: 'paidLeave.grants.forUser',
        summary: '社員の有給残数を取得する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function grantsForUser(string $userId): AnonymousResourceCollection
    {
        $grants = PaidLeaveGrant::query()
            ->where('user_id', $userId)
            ->orderBy('expires_on')
            ->get();

        return PaidLeaveGrantResource::collection($grants);
    }

    /**
     * UC-P002: 有給を付与する(人事担当者による手動実行)。
     */
    #[OA\Post(
        path: '/paid-leave/grants',
        operationId: 'paidLeave.grants.store',
        summary: '有給を手動付与する',
        tags: ['有給休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'granted_on', 'expires_on', 'granted_days'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'granted_on', type: 'string', format: 'date'), new OA\Property(property: 'expires_on', type: 'string', format: 'date'), new OA\Property(property: 'granted_days', type: 'number'), new OA\Property(property: 'grant_reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function grant(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'granted_on' => ['required', 'date'],
            'expires_on' => ['required', 'date', 'after:granted_on'],
            'granted_days' => ['required', 'numeric', 'min:0.5'],
            'grant_reason' => ['nullable', 'string'],
        ]);

        $grantId = $commandBus->dispatch(new GrantPaidLeave(
            userId: $data['user_id'],
            grantedOn: $data['granted_on'],
            expiresOn: $data['expires_on'],
            grantedDays: (float) $data['granted_days'],
            grantReason: $data['grant_reason'] ?? null,
        ));

        $grant = PaidLeaveGrant::query()->findOrFail($grantId);

        return (new PaidLeaveGrantResource($grant))->response()->setStatusCode(201);
    }

    /**
     * 管理者が発行済みの有給付与を取り消す。既に消化された分がある場合は
     * RevokePaidLeaveGrantHandlerがDomainRuleExceptionを投げる(422)。
     */
    #[OA\Post(
        path: '/paid-leave/grants/{grant}/revoke',
        operationId: 'paidLeave.grants.revoke',
        summary: '有給付与を取り消す',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'grant', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function revoke(Request $request, PaidLeaveGrant $grant, CommandBus $commandBus): PaidLeaveGrantResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string']]);

        $commandBus->dispatch(new RevokePaidLeaveGrant(
            userId: $grant->user_id,
            grantId: $grant->id,
            revokedByUserId: $request->user()->id,
            reason: $data['reason'] ?? null,
        ));

        return new PaidLeaveGrantResource($grant->refresh());
    }

    /**
     * 旧システム/旧ドメインからのcutover移行専用エンドポイント(1社員分)。管理者権限限定。
     * 口座は一度きりの移行しか受け付けないため、既にGrantが存在する口座への再実行は
     * `MigratePaidLeaveAccountHandler`経由でDomainRuleException(422)となる。
     * 大量移行はartisanコマンド`paid-leave:migrate-accounts`(CSV/JSON一括投入、行単位で
     * 成功・失敗を継続収集する)を使う想定で、本エンドポイントは1社員分の疎通・単発修正用。
     *
     * @see MigratePaidLeaveAccountsCommand
     */
    #[OA\Post(
        path: '/paid-leave/migrate',
        operationId: 'paidLeave.migrate',
        summary: '旧システムからの有給データを1社員分移行する(cutover専用・一度きり)',
        tags: ['有給休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id', 'cutover_date', 'grants'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'cutover_date', type: 'string', format: 'date'), new OA\Property(property: 'grants', type: 'array', items: new OA\Items(type: 'object'))])),
        responses: [new OA\Response(response: 204, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function migrate(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,id'],
            'cutover_date' => ['required', 'date'],
            'grants' => ['array'],
            'grants.*.grant_id' => ['nullable', 'string', 'uuid'],
            'grants.*.original_granted_on' => ['nullable', 'date'],
            'grants.*.original_granted_days' => ['nullable', 'numeric', 'min:0'],
            'grants.*.remaining_days_at_cutover' => ['required', 'numeric', 'min:0'],
            'grants.*.expires_on' => ['required', 'date'],
            'grants.*.mode' => ['required', Rule::in(['A', 'B', 'C'])],
            'grants.*.notes' => ['nullable', 'string'],
        ]);

        $commandBus->dispatch(new MigratePaidLeaveAccount(
            userId: $data['user_id'],
            cutoverDate: $data['cutover_date'],
            grants: array_map(static fn (array $g): array => [
                'grantId' => $g['grant_id'] ?? null,
                'originalGrantedOn' => $g['original_granted_on'] ?? null,
                'originalGrantedDays' => $g['original_granted_days'] ?? null,
                'remainingDaysAtCutover' => (float) $g['remaining_days_at_cutover'],
                'expiresOn' => $g['expires_on'],
                'mode' => $g['mode'],
                'notes' => $g['notes'] ?? null,
            ], $data['grants'] ?? []),
        ));

        return response()->json(null, 204);
    }

    /**
     * UC-P003: 有給を申請する。
     */
    #[OA\Post(
        path: '/paid-leave/requests',
        operationId: 'paidLeave.requests.store',
        summary: '有給を申請する',
        tags: ['有給休暇'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['target_date', 'leave_type', 'approver_user_id'], properties: [new OA\Property(property: 'target_date', type: 'string', format: 'date'), new OA\Property(property: 'leave_type', type: 'string'), new OA\Property(property: 'hours', type: 'number', nullable: true), new OA\Property(property: 'approver_user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'reason', type: 'string', nullable: true), new OA\Property(property: 'request_group_id', type: 'string', format: 'uuid', nullable: true, description: '期間指定でまとめて申請した複数日分を束ねるID(単日申請では省略)')])),
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function storeRequest(Request $request, CommandBus $commandBus): JsonResponse
    {
        // system_settings.paid_leave_requires_approval=falseの場合、承認ワークフローを
        // 経由せずその場で申請→承認不要のまま(消化)まで完結させる(ルートCLAUDE.md
        // 「AIは勤怠ルールを決定しない」とは無関係の、承認要否そのものをマスタ化した設定)。
        $requiresApproval = SystemSetting::current()->paid_leave_requires_approval;

        $data = $request->validate([
            'target_date' => ['required', 'date'],
            'leave_type' => ['required', Rule::in(PaidLeaveType::values())],
            'hours' => ['nullable', 'numeric', 'min:0.5'],
            'approver_user_id' => [$requiresApproval ? 'required' : 'nullable', 'string', 'exists:users,id'],
            'reason' => ['nullable', 'string'],
            // 期間指定でまとめて申請した複数日分(1日1リクエスト)を束ねるID。frontend側が
            // 同一の申請操作内で生成した同じ値を全日分に渡す(単日申請では省略)。
            'request_group_id' => ['nullable', 'string', 'uuid'],
        ]);

        if ($requiresApproval) {
            // UC-P003: 有給申請はworkflow_requestの下書き作成を起点にする。PaidLeaveRequest集約への
            // RequestPaidLeaveはPaidLeaveRequestOnWorkflowRequestDraftedReactorが発行する
            // (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。
            // PaidLeaveRequestのIDはここで採番してsubjectIdとして渡す。Handler側から
            // workflow_requests.subject_idを直接書き換えるとProjectionの再生成で失われるため
            // (ルートCLAUDE.md「Projectionは再生成可能な派生データ」)。
            $requestId = (string) Str::uuid();

            $commandBus->dispatch(new DraftWorkflowRequest(
                requestTypeCode: null,
                applicantUserId: $request->user()->id,
                title: $data['target_date'].' の有給申請',
                formData: [
                    'target_date' => $data['target_date'],
                    'leave_type' => $data['leave_type'],
                    'hours' => $data['hours'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'request_group_id' => $data['request_group_id'] ?? null,
                ],
                approverUserId: $data['approver_user_id'],
                subjectType: WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST,
                subjectId: $requestId,
            ));

            $paidLeaveRequest = PaidLeaveRequest::query()->findOrFail($requestId);

            return (new PaidLeaveRequestResource($paidLeaveRequest->load('user', 'approver')))->response()->setStatusCode(201);
        }

        // 承認不要設定: workflow_requestを作らず、RequestPaidLeave→ApprovePaidLeaveRequest
        // (approvedByUserId: null)を同一トランザクションで発行し、その場で消化まで確定させる。
        // RequestPaidLeaveAggregate::request()のapproverUserIdは非null必須のため、
        // 指定が無い場合は申請者自身のIDをプレースホルダとして使う(このパスの申請は
        // 即座にapprovedになるため、requests/to-approve一覧(status=submittedのみ表示)には
        // そもそも現れず、実質的な承認者としては使われない)。
        $requestId = (string) Str::uuid();
        $approverUserId = $data['approver_user_id'] ?? $request->user()->id;

        // 2つのコマンド発行(CommandBus::dispatchはそれぞれ独自のDBトランザクションで包む)を
        // 外側のトランザクションでまとめ、後段の残数不足等でApprovePaidLeaveRequestが例外を
        // 投げた場合でも、先に作成したPaidLeaveRequest行(submitted状態)が残らないようにする。
        $paidLeaveRequest = DB::transaction(function () use ($commandBus, $data, $requestId, $approverUserId, $request) {
            $commandBus->dispatch(new RequestPaidLeave(
                userId: $request->user()->id,
                targetDate: $data['target_date'],
                leaveType: $data['leave_type'],
                hours: $data['hours'] ?? null,
                approverUserId: $approverUserId,
                reason: $data['reason'] ?? null,
                workflowRequestId: null,
                requestId: $requestId,
                requestGroupId: $data['request_group_id'] ?? null,
            ));

            return $commandBus->dispatch(new ApprovePaidLeaveRequestCommand(
                paidLeaveRequestId: $requestId,
                approvedByUserId: null,
            ));
        });

        return (new PaidLeaveRequestResource($paidLeaveRequest->load('user', 'approver')))->response()->setStatusCode(201);
    }

    #[OA\Get(
        path: '/paid-leave/requests/mine',
        operationId: 'paidLeave.requests.mine',
        summary: '自分の有給申請一覧を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myRequests(Request $request): AnonymousResourceCollection
    {
        $requests = PaidLeaveRequest::query()
            ->with('user', 'approver')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('target_date')
            ->get();

        return PaidLeaveRequestResource::collection($requests);
    }

    #[OA\Get(
        path: '/paid-leave/requests/to-approve',
        operationId: 'paidLeave.requests.toApprove',
        summary: '承認待ち有給申請一覧を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function requestsToApprove(Request $request): AnonymousResourceCollection
    {
        $requests = PaidLeaveRequest::query()
            ->with('user', 'approver')
            ->where('approver_user_id', $request->user()->id)
            ->where('status', PaidLeaveRequestStatus::SUBMITTED)
            ->orderBy('target_date')
            ->get();

        return PaidLeaveRequestResource::collection($requests);
    }

    /**
     * UC-P004: 有給を承認する。
     */
    #[OA\Post(
        path: '/paid-leave/requests/{paidLeaveRequest}/approve',
        operationId: 'paidLeave.requests.approve',
        summary: '有給申請を承認する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function approveRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        // UC-P004: 承認はworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ApproveWorkflowRequestを発行する。
        $commandBus->dispatch(new ApproveWorkflowRequest(
            workflowRequestId: $this->submittedWorkflowRequestId(
                $paidLeaveRequest,
                '対応する申請が見つからないため承認できません。',
            ),
            approvedByUserId: $request->user()->id,
        ));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/paid-leave/requests/{paidLeaveRequest}/return',
        operationId: 'paidLeave.requests.return',
        summary: '有給申請を差し戻す',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['comment'], properties: [new OA\Property(property: 'comment', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function returnRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $data = $request->validate(['comment' => ['required', 'string']]);

        // UC-P004 手順2: 差戻しはworkflow_requestを経由する。対応するworkflow_requestを見つけ、
        // ReturnWorkflowRequestを発行する。
        $commandBus->dispatch(new ReturnWorkflowRequest(
            workflowRequestId: $this->submittedWorkflowRequestId(
                $paidLeaveRequest,
                '対応する申請が見つからないため差し戻せません。',
            ),
            returnedByUserId: $request->user()->id,
            comment: $data['comment'],
        ));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    #[OA\Post(
        path: '/paid-leave/requests/{paidLeaveRequest}/cancel',
        operationId: 'paidLeave.requests.cancel',
        summary: '有給申請を取り消す',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function cancelRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $commandBus->dispatch(new CancelPaidLeaveRequest($paidLeaveRequest->id, $request->user()->id));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    /**
     * 管理者が対象社員の有給申請を取り消す(自分の申請のみ取消可能な`cancelRequest`とは別に、
     * 管理者は他者の承認済み申請も取り消せる。実際に取消操作をしたのは管理者自身のため、
     * cancelledByUserIdは申請者本人ではなく操作者(管理者)のIDを渡す)。
     */
    #[OA\Post(
        path: '/paid-leave/requests/{paidLeaveRequest}/admin-cancel',
        operationId: 'paidLeave.requests.adminCancel',
        summary: '管理者が社員の有給申請を取り消す',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'paidLeaveRequest', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function adminCancelRequest(Request $request, PaidLeaveRequest $paidLeaveRequest, CommandBus $commandBus): PaidLeaveRequestResource
    {
        $commandBus->dispatch(new CancelPaidLeaveRequest($paidLeaveRequest->id, $request->user()->id, isAdminAction: true));

        return new PaidLeaveRequestResource($paidLeaveRequest->refresh()->load('user', 'approver'));
    }

    /**
     * 管理者が対象社員の有給消化明細(paid_leave_usages)を確認する。取消は明細単位では
     * できず、明細に紐づく申請(`paid_leave_request_id`)を`adminCancelRequest`で取り消すことで
     * 反映される。フロント側で取消可否を判定できるよう、関連する申請の現在ステータス
     * (`request_status`)も併せて返す。
     */
    #[OA\Get(
        path: '/paid-leave/usages/user/{userId}',
        operationId: 'paidLeave.usages.forUser',
        summary: '社員の有給消化明細を取得する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function usagesForUser(string $userId): AnonymousResourceCollection
    {
        $usages = PaidLeaveUsage::query()
            ->with('request')
            ->where('user_id', $userId)
            ->where('cancelled', false)
            ->orderByDesc('used_on')
            ->get();

        return PaidLeaveUsageResource::collection($usages);
    }

    /**
     * UC-P007: 自分の有給履歴を確認する。EventStore(stored_events)を正の記録として
     * 直接検索する(付与・申請・承認・差戻し・取消・消化・警告のすべてを時系列で表示するため、
     * 現残高スナップショットのみを返す `myGrants` とは別に用意する)。
     */
    #[OA\Get(
        path: '/paid-leave/history/mine',
        operationId: 'paidLeave.history.mine',
        summary: '自分の有給履歴を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function myHistory(Request $request): AnonymousResourceCollection
    {
        return $this->historyResponse($request->user()->id);
    }

    /**
     * UC-P007: 管理者・人事担当者が対象社員の有給履歴を確認する。他の管理者向け
     * エンドポイント(`grantsForUser`等)と同様、認可はルート側のFeature・Permission
     * で行う。
     */
    #[OA\Get(
        path: '/paid-leave/history/user/{userId}',
        operationId: 'paidLeave.history.forUser',
        summary: '社員の有給履歴を取得する',
        tags: ['有給休暇'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function historyForUser(string $userId): AnonymousResourceCollection
    {
        return $this->historyResponse($userId);
    }

    /**
     * 承認・差戻し対象のworkflow_request(subject_type=paid_leave_request)を特定する。
     * 見つからない場合に黙って何もしないと、状態が変わらないまま200を返してしまうため
     * DomainRuleExceptionを投げる。
     */
    private function submittedWorkflowRequestId(PaidLeaveRequest $paidLeaveRequest, string $message): string
    {
        $workflowRequest = WorkflowRequest::query()
            ->where('subject_type', WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST)
            ->where('subject_id', $paidLeaveRequest->id)
            ->where('status', WorkflowRequestStatus::SUBMITTED)
            ->latest()
            ->first();

        if ($workflowRequest === null) {
            throw new DomainRuleException($message);
        }

        return $workflowRequest->id;
    }

    /**
     * `paid_leave_grant`/`paid_leave_request` それぞれの集約に属するイベントを時系列で返す
     * (LeaveHistoryQuery参照。有給・特別休暇で共通の読み取り専用Query)。
     */
    private function historyResponse(string $userId): AnonymousResourceCollection
    {
        $events = LeaveHistoryQuery::eventsForUser(
            userId: $userId,
            grantModelClass: PaidLeaveGrant::class,
            requestModelClass: PaidLeaveRequest::class,
            // Phase 5(cutover): PaidLeaveAccountAggregateの集約ルートはgrant/request単位
            // ではなくuserId(社員単位の年休台帳)なので、grantIds/requestIdsでは拾えない。
            // aggregate_uuid=userIdのpaid_leave_account.*イベントを別枠で追加する。
            userScopedEventClassPrefixes: ['paid_leave_account.'],
        );

        return StoredEventResource::collection($events);
    }

    /**
     * spec.md 論点5/16: `paid_leave_grant_rules`のstepsが、対応する法定Policyの最低日数を
     * 下回る場合はField Errorとして拒否する(上回る内容は無制限に許可する)。
     * `work_style_id`が未指定(全社共通ルール)の場合は「通常付与」の法定Policyを
     * 最低日数として扱う(論点5決定事項)。
     *
     * @param  array<int, array{continuous_service_months: int, grant_days: int|float}>  $steps
     */
    private function validateStepsAgainstStatutoryMinimum(?string $workStyleId, array $steps): void
    {
        $errors = [];

        foreach ($steps as $index => $step) {
            $minimum = $this->statutoryMinimumGrantDays($workStyleId, (int) $step['continuous_service_months']);

            if ($minimum !== null && (float) $step['grant_days'] < $minimum) {
                $errors["steps.{$index}.grant_days"] = ["継続勤務{$step['continuous_service_months']}か月時点の法定最低付与日数({$minimum}日)を下回っています。"];
            }
        }

        if ($errors !== []) {
            $validator = Validator::make([], []);
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
            throw new ValidationException($validator);
        }
    }

    /**
     * 指定`work_style_id`・継続勤務月数に対する法定最低付与日数を解決する
     * (`App\Domain\PaidLeaveSchedule\Support\ScheduleCandidateGenerator::resolveGrantDays()`の
     * 法定Policy参照部分と同じ考え方。シフト勤務・要確認区分は実績ベースの近似判定になり
     * 機械的な最低日数を一意に決められないため、安全側(下回りを見逃さない)に倒し
     * 「通常付与」表を最低日数の基準として使う)。
     */
    private function statutoryMinimumGrantDays(?string $workStyleId, int $continuousServiceMonths): ?float
    {
        $normalVersion = PaidLeaveGrantPolicy::latestVersion() ?? 'v1';

        if ($workStyleId === null) {
            return PaidLeaveGrantPolicy::grantDaysFor($continuousServiceMonths, $normalVersion);
        }

        $workStyle = WorkStyle::query()->find($workStyleId);

        if ($workStyle === null) {
            return PaidLeaveGrantPolicy::grantDaysFor($continuousServiceMonths, $normalVersion);
        }

        $category = app(GrantCategoryClassifier::class)->classify($workStyle);

        if ($category === GrantCategoryClassifier::CATEGORY_PROPORTIONAL) {
            $weeklyDays = $workStyle->weekly_scheduled_days;
            $categoryCode = $weeklyDays === null ? '4' : (string) max(1, min(4, (int) round($weeklyDays)));

            return PaidLeaveProportionalGrantPolicy::grantDaysFor(
                $categoryCode,
                $continuousServiceMonths,
                PaidLeaveProportionalGrantPolicy::latestVersion() ?? 'v1',
            );
        }

        // 通常付与・シフト勤務・要確認は、いずれも「通常付与」表を最低基準とみなす
        // (シフト勤務・要確認は所定労働日数が確定しておらず比例付与表を機械的に
        // 適用できないため)。
        return PaidLeaveGrantPolicy::grantDaysFor($continuousServiceMonths, $normalVersion);
    }
}
