<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ワークフローID→休暇申請の対応(leave_request_workflow_links)。派生データで、
 * LeaveRequestWorkflowLinkProjectorだけが書き込む。主キーはワークフローのUUID
 * (イベントの集約IDをそのまま使い、DB採番しない)。
 */
#[Fillable(['workflow_request_id', 'leave_kind', 'leave_request_id'])]
class LeaveRequestWorkflowLink extends Model
{
    public const KIND_PAID = 'paid';

    public const KIND_SPECIAL = 'special';

    public const KIND_COMPENSATORY = 'compensatory';

    protected $primaryKey = 'workflow_request_id';

    public $incrementing = false;

    protected $keyType = 'string';
}
