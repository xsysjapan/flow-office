<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 複数ドメイン(月次勤怠申請・経費精算申請など)から共通で使う「共有」記録。
 * 申請提出時に元エンティティ(勤怠日次データ・経費明細等)を承認者へ開示したことを表す
 * 追記専用ログ。一度作成した行は削除・更新しない(ルートCLAUDE.md「絶対に外してはいけない
 * 設計原則」)。そのため更新用のメソッドは生やさず、`EntityShare::query()->create([...])`を
 * 直接呼ぶだけで足りるシンプルなEloquentモデルにしてある。
 *
 * `shareable_type`/`shareable_id`は`App\Models\Attachment`(owner_type/owner_id)や
 * `App\Models\BackOfficeTask`(source_type/source_id)と同じ考え方のポリモーフィックカラム。
 * 各ドメイン(Attendance/ExpenseClaim)のSubmitハンドラが発行する「共有」イベントの反映処理
 * から作成される想定(配線は別タスク)。
 */
#[Fillable(['shareable_type', 'shareable_id', 'shared_with_user_id', 'shared_by_user_id', 'shared_at'])]
class EntityShare extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'shared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sharedWithUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sharedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_user_id');
    }
}
