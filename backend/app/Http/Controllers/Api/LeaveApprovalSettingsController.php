<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * 有給・特別休暇の申請フォームが「承認者指定を必須にすべきか」を判断するために必要な
 * 最小限の設定値のみを返す軽量エンドポイント。`SystemSettingResource`(M365テナント設定等を
 * 含む管理者専用リソース)を全社員に開放しないよう、あえて専用の薄いコントローラーとして
 * 分離する(role:adminミドルウェアは付けず、認証済みユーザーなら誰でも参照できる)。
 */
#[OA\Tag(name: '有給休暇', description: '有給付与・申請・承認')]
class LeaveApprovalSettingsController extends Controller
{
    #[OA\Get(
        path: '/leave-approval-settings',
        operationId: 'leaveApprovalSettings.show',
        summary: '有給・特別休暇の承認要否設定を取得する',
        tags: ['有給休暇'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function show(): JsonResponse
    {
        $settings = SystemSetting::current();

        return response()->json([
            'paid_leave_requires_approval' => $settings->paid_leave_requires_approval,
            'special_leave_requires_approval' => $settings->special_leave_requires_approval,
        ]);
    }
}
