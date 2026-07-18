<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['authentication_key_id', 'device_id', 'site_id', 'allow'])]
class AuthenticationKeyDeviceRule extends Model
{
    protected function casts(): array
    {
        return ['allow' => 'boolean'];
    }

    /**
     * @return BelongsTo<AuthenticationKey, $this>
     */
    public function authenticationKey(): BelongsTo
    {
        return $this->belongsTo(AuthenticationKey::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
