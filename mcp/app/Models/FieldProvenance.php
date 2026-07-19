<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * 各入力項目の値の出所(docs/26-usecases-monthly-import.md、docs/03-architecture.md 3.7)。
 * `entity_type`/`entity_id`は独自の緩いポリモーフィック参照であり、Eloquentのmorphmapは
 * 使わない単純な文字列で管理する。
 */
#[Fillable([
    'entity_type', 'entity_id', 'field_name', 'source_type', 'source_reference_json',
    'confidence', 'previous_value', 'confirmed_by_user_id', 'confirmed_at',
])]
class FieldProvenance extends Model
{
    public const ENTITY_MONTHLY_ATTENDANCE_DRAFT = 'monthly_attendance_draft';

    public const ENTITY_ATTENDANCE_IMPORT_ITEM = 'attendance_import_item';

    /**
     * updated_at列を持たないため(追記専用の記録)。
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'source_reference_json' => 'array',
            'confirmed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<McpUser, $this>
     */
    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(McpUser::class, 'confirmed_by_user_id');
    }

    /**
     * 指定エンティティの各field_nameについて、最新の記録のみを取得する
     * (同じ項目が複数回追記されうるため。docs/26「AI生成値の出所管理」)。
     *
     * @return Collection<int, FieldProvenance>
     */
    public static function latestForEntity(string $entityType, int $entityId): Collection
    {
        return static::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereIn('id', function (Builder $query) use ($entityType, $entityId) {
                $query->selectRaw('MAX(id)')
                    ->from('field_provenances')
                    ->where('entity_type', $entityType)
                    ->where('entity_id', $entityId)
                    ->groupBy('field_name');
            })
            ->get();
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isImportantAndUnconfirmed(): bool
    {
        if ($this->source_type !== FieldSourceType::AI_INFERRED || $this->isConfirmed()) {
            return false;
        }

        // field_nameは"{work_date}:{field}"形式(例: "2026-07-01:start_time")のため、
        // コロン以降の項目名で重要項目かどうかを判定する。
        $field = str_contains($this->field_name, ':') ? substr(strrchr($this->field_name, ':'), 1) : $this->field_name;

        return in_array($field, FieldSourceType::importantFields(), true);
    }
}
