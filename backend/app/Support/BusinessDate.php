<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * 業務日付の「今日」。システムのデフォルトタイムゾーン(`system_settings.default_timezone`)での今日の日付を、
 * アプリのタイムゾーン(UTC)の0時のCarbonとして返す。付与予定の日付(同じ形で保存・生成される)と
 * そのまま比較できるようにするため。`Carbon::today()`(アプリのタイムゾーン=UTCの日付)を使わない。
 */
final class BusinessDate
{
    public static function today(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', Carbon::now(SystemSetting::current()->default_timezone)->toDateString())->startOfDay();
    }
}
