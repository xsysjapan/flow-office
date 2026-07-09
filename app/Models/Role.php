<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * アプリ独自のロール(権限)。docs/05-user-roles.md のユーザー種別に対応する。
 * Microsoft Graph同期(UC-002)では絶対に上書きしない。
 */
#[Fillable(['code', 'name'])]
class Role extends Model
{
    public const EMPLOYEE = 'employee';

    public const BACKOFFICE_STAFF = 'backoffice_staff';

    public const ACCOUNTING_STAFF = 'accounting_staff';

    public const GENERAL_AFFAIRS_STAFF = 'general_affairs_staff';

    public const HR_STAFF = 'hr_staff';

    public const ADMIN = 'admin';

    /**
     * @return array<int, string>
     */
    public static function defaultCodes(): array
    {
        return [
            self::EMPLOYEE,
            self::BACKOFFICE_STAFF,
            self::ACCOUNTING_STAFF,
            self::GENERAL_AFFAIRS_STAFF,
            self::HR_STAFF,
            self::ADMIN,
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
