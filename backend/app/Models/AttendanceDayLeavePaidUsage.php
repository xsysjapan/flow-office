<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 有給の消化記録ID→有給申請ID(attendance_day_leave_paid_usages)。
 * 【確認事項】作り直し後は書き込みが無い(旧系統の対応表。削除の要否は未決定)。AttendanceDayLeaveProjectorだけが書き込む派生データ。cutover後の確定・取消イベント
 * (usageIdしか持たない)を申請IDへ対応付けるために使う。
 */
#[Fillable(['usage_id', 'leave_request_id'])]
class AttendanceDayLeavePaidUsage extends Model
{
    protected $primaryKey = 'usage_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
