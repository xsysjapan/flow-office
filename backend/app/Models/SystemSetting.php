<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * システム全体の設定 (docs/06-usecases-auth.md UC-003)。常に1行のみ存在する
 * シングルトンのマスタ。
 */
#[Fillable(['default_timezone', 'attendance_submission_deadline_day', 'attendance_month_close_deadline_day'])]
class SystemSetting extends Model
{
    /**
     * 常に存在する1行を返す。存在しない場合は既定値で作成する。
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'default_timezone' => 'Asia/Tokyo',
            'attendance_submission_deadline_day' => 5,
            'attendance_month_close_deadline_day' => 10,
        ]);
    }
}
