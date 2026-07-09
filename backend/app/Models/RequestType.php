<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 申請種別マスタ (docs/10-usecases-workflow.md UC-W001)。
 * フォーム項目・添付必須有無・バックオフィスタスク生成有無をここで設定し、
 * 新しい申請種別を追加してもコード変更を必要最小限にする。
 * (.claude/skills/add-workflow-request-type 参照)
 */
#[Fillable(['code', 'name', 'description', 'form_schema', 'requires_backoffice_task', 'backoffice_task_type', 'is_active'])]
class RequestType extends Model
{
    protected function casts(): array
    {
        return [
            'form_schema' => 'array',
            'requires_backoffice_task' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
