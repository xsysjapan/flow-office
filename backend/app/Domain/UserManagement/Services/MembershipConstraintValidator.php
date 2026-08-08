<?php

namespace App\Domain\UserManagement\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;

final class MembershipConstraintValidator
{
    /** @return array<string, array{group_id:string,group_type_id:int,is_primary:bool,membership_kind:string}> */
    public function current(string $userId): array
    {
        return DB::table('memberships')
            ->join('groups', 'memberships.group_id', '=', 'groups.id')
            ->where('memberships.user_id', $userId)
            ->select('memberships.group_id', 'memberships.is_primary', 'memberships.membership_kind', 'groups.group_type_id')
            ->get()->mapWithKeys(fn ($row) => [$row->group_id => [
                'group_id' => $row->group_id,
                'group_type_id' => (int) $row->group_type_id,
                'is_primary' => (bool) $row->is_primary,
                'membership_kind' => $row->membership_kind,
            ]])->all();
    }

    /** @param array<string, array{group_id:string,group_type_id:int,is_primary:bool,membership_kind:string}> $state */
    public function applyItems(array $state, array $items): array
    {
        foreach ($items as $item) {
            $operation = strtolower($item['operation']);
            $from = $item['from_group_id'] ?? $item['target_group_id'] ?? null;
            if (in_array($operation, ['remove', 'replace'], true)) {
                if (! $from || ! isset($state[$from])) {
                    throw new DomainRuleException('削除・置換元の所属が存在しません。');
                }
                unset($state[$from]);
            }
        }
        foreach ($items as $item) {
            $operation = strtolower($item['operation']);
            $to = $item['to_group_id'] ?? $item['target_group_id'] ?? null;
            if (in_array($operation, ['add', 'replace'], true)) {
                if (! $to) {
                    throw new DomainRuleException('追加・置換先のGroupが必要です。');
                }
                if (isset($state[$to])) {
                    throw new DomainRuleException('同じGroupへ重複所属できません。');
                }
                $state[$to] = [
                    'group_id' => $to,
                    'group_type_id' => (int) $item['group_type_id'],
                    'is_primary' => (bool) ($item['is_primary'] ?? false),
                    'membership_kind' => ($item['is_primary'] ?? false) ? 'primary' : 'member',
                ];
            }
        }
        foreach ($items as $item) {
            if (strtolower($item['operation']) !== 'set_primary') {
                continue;
            }
            $target = $item['target_group_id'] ?? $item['from_group_id'] ?? null;
            if (! $target || ! isset($state[$target])) {
                throw new DomainRuleException('主所属設定の対象所属が存在しません。');
            }
            $state[$target]['is_primary'] = (bool) ($item['is_primary'] ?? true);
            $state[$target]['membership_kind'] = $state[$target]['is_primary'] ? 'primary' : 'member';
        }

        return $state;
    }

    public function validate(string $userId, array $state, array $affectedTypeIds = []): void
    {
        if (! DB::table('users')->where('id', $userId)->exists()) {
            throw new DomainRuleException('ユーザーが存在しません。');
        }
        $typeIds = collect($state)->pluck('group_type_id')->merge($affectedTypeIds)->map(fn ($id) => (int) $id)->unique();
        $types = DB::table('group_types')->whereIn('id', $typeIds)->get()->keyBy('id');
        $byType = collect($state)->groupBy('group_type_id');
        foreach ($typeIds as $typeId) {
            $memberships = $byType->get($typeId, collect());
            $type = $types->get($typeId);
            if (! $type || $type->status !== 'active') {
                throw new DomainRuleException('無効なGroupTypeの所属が含まれています。');
            }
            if ($type->max_memberships_per_user !== null && $memberships->count() > $type->max_memberships_per_user) {
                throw new DomainRuleException('所属数の上限を超えています。');
            }
            $primaryCount = $memberships->where('is_primary', true)->count();
            if ($type->primary_membership_required && $primaryCount < 1) {
                throw new DomainRuleException('主所属が必要です。');
            }
            if ($type->max_primary_memberships !== null && $primaryCount > $type->max_primary_memberships) {
                throw new DomainRuleException('主所属数の上限を超えています。');
            }
        }
    }

    public function validateItems(string $userId, array $items): array
    {
        $current = $this->current($userId);
        foreach ($items as $item) {
            foreach (['target_group_id', 'to_group_id', 'from_group_id'] as $key) {
                $groupId = $item[$key] ?? null;
                if ($groupId && ! DB::table('groups')->where('id', $groupId)->where('group_type_id', $item['group_type_id'])->exists()) {
                    throw new DomainRuleException('GroupTypeと一致しないグループが含まれています。');
                }
            }
            $operation = strtolower($item['operation']);
            $to = $item['to_group_id'] ?? $item['target_group_id'] ?? null;
            if (in_array($operation, ['add', 'replace'], true) && (! $to || ! DB::table('groups')->where('id', $to)->where('status', 'active')->exists())) {
                throw new DomainRuleException('追加先には有効なグループが必要です。');
            }
        }
        $state = $this->applyItems($current, $items);
        $administratorGroupId = DB::table('groups')->where('code', 'SYSTEM_ADMINISTRATORS')->value('id');
        $targetIsActive = DB::table('users')->where('id', $userId)->whereIn('account_status', ['active', 'leave'])->exists();
        $activeAdministratorCount = $administratorGroupId === null ? 0 : DB::table('memberships')->join('users', 'memberships.user_id', '=', 'users.id')->where('memberships.group_id', $administratorGroupId)->whereIn('users.account_status', ['active', 'leave'])->count();
        if ($administratorGroupId !== null && $targetIsActive && isset($current[$administratorGroupId]) && ! isset($state[$administratorGroupId])
            && $activeAdministratorCount <= 1) {
            throw new DomainRuleException('最後のシステム管理者は削除できません。');
        }
        $this->validate($userId, $state, collect($items)->pluck('group_type_id')->all());

        return $state;
    }
}
