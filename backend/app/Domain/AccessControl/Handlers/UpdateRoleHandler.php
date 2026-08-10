<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAggregate;
use App\Domain\AccessControl\Commands\UpdateRole;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<UpdateRole> */ class UpdateRoleHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof UpdateRole);
        $role = DB::table('roles')->where('id', $c->roleId)->lockForUpdate()->first();
        if (! $role) {
            throw new DomainRuleException('Roleが存在しません。');
        } if ($role->is_system && $c->status !== 'active') {
            throw new DomainRuleException('システムRoleは廃止できません。');
        } RoleAggregate::retrieve(Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:'.$role->code)->toString())->update($c->roleId, $c->name, $c->description, $c->status, $c->actorUserId)->persist();

        return (string) $c->roleId;
    }
}
