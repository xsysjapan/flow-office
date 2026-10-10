<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 有給の消化記録ID→有給申請ID(paid_leave_request_usage_links)。
 * PaidLeaveRequestProjectorだけが書き込む派生データ。cutover後の確定・取消イベント
 * (usageIdしか持たない)を申請IDへ対応付けるために使う。
 */
#[Fillable(['usage_id', 'paid_leave_request_id'])]
class PaidLeaveRequestUsageLink extends Model
{
    protected $primaryKey = 'usage_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
