<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 汎用申請 (docs/10-usecases-workflow.md)。承認とバックオフィス処理は別ステータス系列
 * (backoffice_tasks) で管理するため、ここでの status は申請自体の承認フローのみを表す。
 *
 * 主キーはUUID(HasUuids)。DB採番だと集約IDがINSERTするまで確定せずProjectorで
 * 作成できないため、コマンド側で生成できるUUIDにしている
 * (.claude/skills/add-projection「集約ルートのUUID化」参照)。この行自体も
 * WorkflowRequestProjector が stored_events から作成・更新する。
 *
 * `subject_type`/`subject_id`が設定されている行(`attendance_month`/`expense_claim`)は、
 * 月次勤怠申請・経費精算申請の申請そのもの。`DraftWorkflowRequest`にsubjectを渡して
 * 作成し、以降の提出・承認・差戻しも汎用申請と同じCommand/Handlerで行う。この場合
 * `request_type_id`は常にnullで、フォーム内容は`form_data`ではなく対象ドメインの正データ
 * (`attendance_months`/`expense_claims`)を参照する。
 */
#[Fillable(['id', 'request_type_id', 'title', 'applicant_user_id', 'approver_user_id', 'status', 'form_data', 'submitted_at', 'approved_at', 'returned_at', 'cancelled_at', 'subject_type', 'subject_id'])]
class WorkflowRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * subject_type/subject_idが指す他ドメインの正データを都度取得する。一覧・詳細表示の
     * 都度呼ばれる想定のため、キャッシュはしない(呼び出し側でN+1を気にする必要がある場合は
     * 個別に対応する)。
     */
    public function subjectModel(): AttendanceMonth|ExpenseClaim|PaidLeaveRequest|SpecialLeaveRequest|null
    {
        return match ($this->subject_type) {
            'attendance_month' => AttendanceMonth::query()->find($this->subject_id),
            'expense_claim' => ExpenseClaim::query()->find($this->subject_id),
            'paid_leave_request' => PaidLeaveRequest::query()->with(['user'])->find($this->subject_id),
            'special_leave_request' => SpecialLeaveRequest::query()->with(['user', 'specialLeaveType'])->find($this->subject_id),
            default => null,
        };
    }

    /**
     * @return BelongsTo<RequestType, $this>
     */
    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner');
    }
}
