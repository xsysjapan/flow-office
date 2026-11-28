<?php

namespace App\Domain\UserManagement\Services;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * 利用者管理の参照のサービス。他の文脈(休暇の手動付与)の入口が、グループの指定を利用者IDへ展開するために使う
 * (休暇の文脈が所属のテーブルを読まない。原則15)。
 *
 * 展開の規則: 実行した時点の、そのグループへの直接の所属(子グループのメンバーは含めない)。退職者(在籍状態が有効でない、
 * または退社日が今日より前)は除く。グループの指定には`group.view`を要する(グループ一覧・メンバー一覧の参照と同じ)。
 */
final class GroupMemberExpander
{
    public function __construct(private readonly EffectiveAccessResolver $access) {}

    /**
     * @return array<string, string> 利用者ID=>表示名(利用者IDの昇順)
     *
     * @throws AuthorizationException `group.view`を持たないとき
     */
    public function activeDirectMembers(User $actor, string $groupId): array
    {
        if (! $this->access->permissions($actor)->contains('group.view')) {
            throw new AuthorizationException('グループを指定するには、グループの閲覧権限が必要です。');
        }

        $today = now()->toDateString();

        return User::query()
            ->whereIn('id', Membership::query()->where('group_id', $groupId)->select('user_id'))
            ->where('employment_status', 'active')
            ->where(function ($query) use ($today) {
                $query->whereNull('termination_date')->orWhereDate('termination_date', '>=', $today);
            })
            ->orderBy('id')
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * 入口の指定(`user_ids`か`group_id`)から、対象の利用者ID=>表示名を決める。グループは入口で利用者IDへ展開し、
     * 展開した後の件数で上限を検査する(検査に反したら入力の検証エラー)。
     *
     * @param  list<string>|null  $userIds
     * @return array<string, string> 利用者ID=>表示名(`user_ids`の指定順。グループは利用者IDの昇順)
     *
     * @throws AuthorizationException グループの指定で`group.view`を持たないとき
     * @throws ValidationException グループに対象がいない・上限を超えるとき
     */
    public function resolveTargets(User $actor, ?string $groupId, ?array $userIds, int $limit): array
    {
        if ($groupId !== null) {
            $labels = $this->activeDirectMembers($actor, $groupId);
            if ($labels === []) {
                throw ValidationException::withMessages(['group_id' => ['このグループには、対象にできる所属メンバーがいません。']]);
            }
            if (count($labels) > $limit) {
                throw ValidationException::withMessages(['group_id' => ["グループのメンバーが{$limit}人を超えています。"]]);
            }

            return $labels;
        }

        $names = $this->namesFor(array_values($userIds ?? []));
        $ordered = [];
        foreach ($userIds ?? [] as $userId) {
            $ordered[$userId] = $names[$userId] ?? $userId;
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string, string> 利用者ID=>表示名
     */
    public function namesFor(array $userIds): array
    {
        return User::query()
            ->whereIn('id', $userIds)
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }
}
