<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAggregate;
use App\Domain\AccessControl\Commands\CloneRole;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * ロールの複製。作成と権限の設定を1つの集約の1回の記録で行う(途中で失敗したら何も残らない)。
 * 戻り値は新しいロールのID。
 *
 * @implements CommandHandler<CloneRole>
 */
class CloneRoleHandler implements CommandHandler
{
    public function handle(Command $c): int
    {
        assert($c instanceof CloneRole);
        $source = DB::table('roles')->where('id', $c->sourceRoleId)->first();
        if (! $source) {
            throw new DomainRuleException('複製元のRoleが存在しません。');
        }
        if (DB::table('roles')->where('code', $c->code)->exists()) {
            throw new DomainRuleException('Roleコードは既に使用されています。');
        }
        $permissionIds = DB::table('permission_role')->where('role_id', $source->id)->pluck('permission_id')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        $businessId = (int) (DB::table('roles')->lockForUpdate()->max('id') ?? 0) + 1;

        RoleAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:'.$c->code)->toString())
            ->create($businessId, $c->code, $c->name, $c->description ?? $source->description, $c->actorUserId)
            ->changePermissions($businessId, $permissionIds, $c->actorUserId)
            ->persist();

        return $businessId;
    }
}
