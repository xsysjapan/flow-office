<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'default_timezone' => $this->default_timezone,
            'default_work_style_id' => $this->default_work_style_id,
            'default_work_style' => $this->whenLoaded('defaultWorkStyle', fn () => $this->defaultWorkStyle && [
                'id' => $this->defaultWorkStyle->id,
                'code' => $this->defaultWorkStyle->code,
                'name' => $this->defaultWorkStyle->name,
            ]),
            'attendance_submission_deadline_day' => $this->attendance_submission_deadline_day,
            'attendance_month_close_deadline_day' => $this->attendance_month_close_deadline_day,
            // SSOログイン・MS365ユーザー同期・Graphメール送信で共有するEntra ID資格情報。
            'm365_tenant_id' => $this->m365_tenant_id,
            'm365_client_id' => $this->m365_client_id,
            // クライアントシークレットは平文を返さず、設定済みかどうかのみ返す。
            'm365_client_secret_configured' => $this->m365_client_secret !== null,
            'm365_redirect_uri' => $this->m365_redirect_uri,
            'm365_mock_enabled' => $this->m365_mock_enabled,
            'notification_mail_enabled' => $this->notification_mail_enabled,
            'notification_mail_sender_address' => $this->notification_mail_sender_address,
            'notification_mail_sender_name' => $this->notification_mail_sender_name,
        ];
    }
}
