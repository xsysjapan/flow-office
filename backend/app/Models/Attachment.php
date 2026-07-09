<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * 添付ファイル (docs/12-usecases-attachment.md)。owner_type/owner_id のポリモーフィックで
 * 申請・勤怠など任意のエンティティに添付できる。
 */
#[Fillable(['owner_type', 'owner_id', 'uploaded_by', 'file_name', 'stored_path', 'mime_type', 'file_size'])]
class Attachment extends Model
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
