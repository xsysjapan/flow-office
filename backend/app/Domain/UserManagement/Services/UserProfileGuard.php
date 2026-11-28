<?php

namespace App\Domain\UserManagement\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 利用者のプロフィール変更の共通の検査(UpdateUserProfile・UpdateUserDetails が使う)。
 */
final class UserProfileGuard
{
    /**
     * 最後の有効なシステム管理者を無効化しようとしていれば拒否する。
     */
    public function assertNotDisablingLastSystemAdministrator(User $user, array $changes): void
    {
        if (! array_key_exists('account_status', $changes) || $changes['account_status'] === 'active') {
            return;
        }
        $adminGroupId = DB::table('groups')->where('code', 'SYSTEM_ADMINISTRATORS')->value('id');
        $isSystemAdministrator = $adminGroupId !== null
            && DB::table('memberships')->where('user_id', $user->id)->where('group_id', $adminGroupId)->exists();
        if (! $isSystemAdministrator) {
            return;
        }
        $activeAdminCount = DB::table('users')
            ->join('memberships', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.group_id', $adminGroupId)
            ->where('users.account_status', 'active')
            ->count();
        if ($user->account_status === 'active' && $activeAdminCount <= 1) {
            throw new DomainRuleException('最後のシステム管理者は無効化できません。');
        }
    }
}
