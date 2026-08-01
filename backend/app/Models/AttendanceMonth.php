<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 月次勤怠 (docs/07-usecases-attendance.md UC-A007〜UC-A011)。
 * 日次勤怠実績の集計結果であり、直接の入力元にはしない。
 *
 * 主キーはUUID(HasUuids)。集約ID(aggregate_id)としてstored_eventsに書き込まれるため、
 * DB採番だと確定前にProjectorが行を作成できない(docs/29-event-sourcing-framework-migration.md参照)。
 */
#[Fillable(['id', 'user_id', 'year_month', 'status', 'approver_user_id', 'submitted_at', 'approved_at', 'returned_at', 'return_comment', 'closed_at', 'snapshot_json'])]
class AttendanceMonth extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'snapshot_json' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * (user_id, year_month)に対応する集約ID(attendance_months.id)を返す。行がまだ無い月
     * (初回提出)ではUUIDを新規採番するだけで、行は作らない(行の作成は
     * AttendanceMonthProjectorがattendance_month.submittedイベントから行う)。
     *
     * 月次勤怠申請は「workflow_requestの下書き作成 → Reactorが月次勤怠を提出」という順で
     * 進むため、下書きのsubject_idに載せる集約IDを提出より先に確定させる必要がある。
     */
    public static function resolveIdFor(string $userId, string $yearMonth): string
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('year_month', $yearMonth)
            ->value('id') ?? (string) Str::uuid();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
