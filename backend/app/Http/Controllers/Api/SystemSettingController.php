<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SystemSettingResource;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

/**
 * UC-003: システム設定を管理する。新規作成するユーザーのデフォルトタイムゾーンなどを保持する
 * (docs/06-usecases-auth.md)。既存ユーザーのタイムゾーンには影響しない。
 */
class SystemSettingController extends Controller
{
    public function show(): SystemSettingResource
    {
        return new SystemSettingResource(SystemSetting::current());
    }

    public function update(Request $request): SystemSettingResource
    {
        $data = $request->validate([
            'default_timezone' => ['required', 'timezone'],
        ]);

        $setting = SystemSetting::current();
        $setting->update($data);

        return new SystemSettingResource($setting);
    }
}
