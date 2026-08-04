<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * 認証済みなら誰でも参照できる、システム設定のうち機微でないサブセットを返す軽量エンドポイント。
 * M365テナント設定・通知メール設定等の管理者専用項目は含めない(`SystemSettingController`側)。
 * フロントエンドの起動時初期設定はここに集約する。
 */
#[OA\Tag(name: 'システム設定', description: '認証・勤怠のシステム設定')]
class PublicSystemSettingController extends Controller
{
    #[OA\Get(
        path: '/system-settings',
        operationId: 'systemSettings.showPublic',
        summary: 'フロントエンド向け公開システム設定を取得する',
        tags: ['システム設定'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function show(): JsonResponse
    {
        $settings = SystemSetting::current()->load('defaultWorkStyle');

        return response()->json([
            'default_timezone' => $settings->default_timezone,
            'default_work_style_id' => $settings->default_work_style_id,
            'default_work_style' => $settings->defaultWorkStyle ? [
                'id' => $settings->defaultWorkStyle->id,
                'code' => $settings->defaultWorkStyle->code,
                'name' => $settings->defaultWorkStyle->name,
            ] : null,
            'attendance_submission_deadline_day' => $settings->attendance_submission_deadline_day,
            'attendance_month_close_deadline_day' => $settings->attendance_month_close_deadline_day,
            'paid_leave_requires_approval' => $settings->paid_leave_requires_approval,
            'special_leave_requires_approval' => $settings->special_leave_requires_approval,
            'attendance_requires_approval' => $settings->attendance_requires_approval,
            'expense_claim_requires_approval' => $settings->expense_claim_requires_approval,
            'shift_swap_requires_approval' => $settings->shift_swap_requires_approval,
        ]);
    }
}
