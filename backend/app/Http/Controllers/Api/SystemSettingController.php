<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SystemSettingResource;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * UC-003: システム設定を管理する。新規作成するユーザーのデフォルトタイムゾーンなどを保持する
 * (docs/06-usecases-auth.md)。既存ユーザーのタイムゾーンには影響しない。
 */
#[OA\Tag(name: 'システム設定', description: '認証・勤怠のシステム設定')]
class SystemSettingController extends Controller
{
    #[OA\Get(
        path: '/system-settings',
        operationId: 'systemSettings.show',
        summary: 'システム設定を取得する',
        tags: ['システム設定'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function show(): SystemSettingResource
    {
        return new SystemSettingResource(SystemSetting::current()->load('defaultWorkStyle'));
    }

    #[OA\Put(
        path: '/system-settings',
        operationId: 'systemSettings.update',
        summary: 'システム設定を更新する',
        tags: ['システム設定'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['default_timezone'], properties: [new OA\Property(property: 'default_timezone', type: 'string'), new OA\Property(property: 'default_work_style_id', type: 'integer', nullable: true), new OA\Property(property: 'attendance_submission_deadline_day', type: 'integer'), new OA\Property(property: 'attendance_month_close_deadline_day', type: 'integer'), new OA\Property(property: 'm365_tenant_id', type: 'string', nullable: true), new OA\Property(property: 'm365_client_id', type: 'string', nullable: true), new OA\Property(property: 'm365_client_secret', type: 'string', nullable: true, description: '省略時は既存の値を変更しない'), new OA\Property(property: 'm365_mock_enabled', type: 'boolean'), new OA\Property(property: 'notification_mail_enabled', type: 'boolean'), new OA\Property(property: 'notification_mail_sender_address', type: 'string', nullable: true), new OA\Property(property: 'notification_mail_sender_name', type: 'string', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function update(Request $request): SystemSettingResource
    {
        $data = $request->validate([
            'default_timezone' => ['required', 'timezone'],
            'default_work_style_id' => ['nullable', 'integer', 'exists:work_styles,id'],
            'attendance_submission_deadline_day' => ['integer', 'min:1', 'max:31'],
            'attendance_month_close_deadline_day' => ['integer', 'min:1', 'max:31'],
            'm365_tenant_id' => ['nullable', 'string'],
            'm365_client_id' => ['nullable', 'string'],
            'm365_client_secret' => ['nullable', 'string'],
            'm365_mock_enabled' => ['boolean'],
            'notification_mail_enabled' => ['boolean'],
            'notification_mail_sender_address' => ['nullable', 'email'],
            'notification_mail_sender_name' => ['nullable', 'string'],
        ]);

        // クライアントシークレットは画面に平文を出さないため、未入力(送信されない、または空)の場合は
        // 既存の値を保持する。明示的に空文字を送っても消去はしない(無効化は notification_mail_enabled/
        // m365_mock_enabled で行う)。
        if (! $request->filled('m365_client_secret')) {
            unset($data['m365_client_secret']);
        }

        $setting = SystemSetting::current();
        $setting->update($data);

        return new SystemSettingResource($setting->load('defaultWorkStyle'));
    }
}
