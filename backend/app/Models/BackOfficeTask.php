<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * バックオフィスタスク (docs/11-usecases-backoffice.md)。承認とは別ステータス系列で管理する。
 *
 * 主キーはUUID(HasUuids)。WorkflowRequest同様、BackOfficeTaskProjector が
 * stored_events からこの行自体(作成含む)を作成・更新する
 * (.claude/skills/add-projection「集約ルートのUUID化」参照)。
 */
#[Fillable(['id', 'source_type', 'source_id', 'task_type', 'title', 'status', 'assigned_department', 'assigned_user_id', 'due_on', 'completed_at'])]
class BackOfficeTask extends Model
{
    use HasUuids;

    protected $table = 'backoffice_tasks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
