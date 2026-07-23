<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 汎用申請の履歴表示用Projection(UC-W002〜UC-W005)。stored_eventsのイベントクラス名・
 * payload形状をUIに直接公開しないための専用テーブル
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
#[Fillable(['stored_event_id', 'workflow_request_id', 'action', 'actor_user_id', 'comment', 'occurred_at'])]
class WorkflowRequestHistoryEntry extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkflowRequest, $this>
     */
    public function workflowRequest(): BelongsTo
    {
        return $this->belongsTo(WorkflowRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
