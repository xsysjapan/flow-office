<?php

namespace App\Domain\Notification;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * 特定の個人に紐づかない通知(部門宛て等)の宛先を、ロールから解決する。
 */
class NotificationRecipients
{
    /**
     * @param  array<int, string>  $roleCodes
     * @return Collection<int, User>
     */
    public static function byRoles(array $roleCodes): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('code', $roleCodes))
            ->get();
    }
}
