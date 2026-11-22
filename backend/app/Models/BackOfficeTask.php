<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;

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

    /**
     * 担当者区分で絞り込む。unassigned=未割当、me=自分、others=自分以外(未割当を除く)、all=絞らない。
     *
     * @param  Builder<BackOfficeTask>  $query
     * @return Builder<BackOfficeTask>
     */
    public function scopeAssigneeScope(Builder $query, string $assignee, string $userId): Builder
    {
        return match ($assignee) {
            'unassigned' => $query->whereNull('assigned_user_id'),
            'me' => $query->where('assigned_user_id', $userId),
            'others' => $query->whereNotNull('assigned_user_id')->where('assigned_user_id', '<>', $userId),
            'all' => $query,
            default => throw new InvalidArgumentException("未対応の担当者区分です: {$assignee}"),
        };
    }

    /**
     * ステータスで絞り込む。open=完了・取消以外、all=絞らない、それ以外は個別ステータス。
     *
     * @param  Builder<BackOfficeTask>  $query
     * @return Builder<BackOfficeTask>
     */
    public function scopeStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'open' => $query->whereNotIn('status', [BackOfficeTaskStatus::COMPLETED, BackOfficeTaskStatus::CANCELLED]),
            'all' => $query,
            default => $query->where('status', $status),
        };
    }
}
