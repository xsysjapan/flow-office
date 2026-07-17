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
 */
#[Fillable(['id', 'request_type_id', 'title', 'applicant_user_id', 'approver_user_id', 'status', 'form_data', 'submitted_at', 'approved_at', 'returned_at', 'cancelled_at'])]
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
