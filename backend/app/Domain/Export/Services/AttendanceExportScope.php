<?php

namespace App\Domain\Export\Services;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 勤怠の出力範囲の判定。勤怠CSV・Excel・外部連携(ExportController)と、月次勤怠APIの
 * can_export(画面の出力ボタンの出し分け)が同じ規則を使うよう、判定をここに集約する。
 */
class AttendanceExportScope
{
    public function __construct(private readonly EffectiveAccessResolver $resolver) {}

    /**
     * 出力できる社員ID。attendance.exportを全社(global)で持つ利用者は null(制限なし)。
     * グループスコープだけの利用者は許可グループのメンバー(memberships)の社員IDだけ。
     * 本人が許可グループに所属していなければ本人も含めない。
     *
     * @return Collection<int, string>|null
     */
    public function allowedUserIds(User $user): ?Collection
    {
        if ($this->resolver->hasGlobalPermission($user, 'attendance.export')) {
            return null;
        }

        $groupIds = $this->resolver->permittedGroupIds($user, 'attendance.export');
        $userIds = DB::table('memberships')
            ->whereIn('group_id', $groupIds)
            ->distinct()
            ->pluck('user_id');

        return Collection::make($userIds->all());
    }

    /**
     * 指定社員の月次勤怠を出力できるか。attendance.exportを持たなければfalse、
     * 制限なし(global)ならtrue、それ以外は allowedUserIds に含まれるかで判定する。
     */
    public function canExport(User $user, string $userId): bool
    {
        if (! $this->resolver->permissions($user)->contains('attendance.export')) {
            return false;
        }

        $allowedUserIds = $this->allowedUserIds($user);

        return $allowedUserIds === null || $allowedUserIds->contains($userId);
    }
}
