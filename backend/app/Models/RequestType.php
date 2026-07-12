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
#[Fillable([
    'code', 'name', 'description', 'form_schema',
    'requires_attachment', 'attachment_max_size_kb', 'attachment_allowed_extensions',
    'eligible_role_codes',
    'requires_backoffice_task', 'backoffice_task_type', 'backoffice_department',
    'export_amount_field', 'allowed_status_transitions',
    'is_active',
])]
class RequestType extends Model
{
    protected function casts(): array
    {
        return [
            'form_schema' => 'array',
            'requires_attachment' => 'boolean',
            'attachment_allowed_extensions' => 'array',
            'eligible_role_codes' => 'array',
            'requires_backoffice_task' => 'boolean',
            'allowed_status_transitions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * UC-W001 手順4: この申請種別を申請できるロールか。`eligible_role_codes`が未設定(null)なら
     * 全員が申請可能。
     *
     * @param  list<string>  $userRoleCodes
     */
    public function isEligibleForRoles(array $userRoleCodes): bool
    {
        if ($this->eligible_role_codes === null) {
            return true;
        }

        return count(array_intersect($this->eligible_role_codes, $userRoleCodes)) > 0;
    }
}
