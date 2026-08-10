<?php

namespace App\Domain\AccessControl\Handlers;

use App\Domain\AccessControl\Aggregates\RoleAggregate;
use App\Domain\AccessControl\Commands\CreateRole;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** @implements CommandHandler<CreateRole> */ class CreateRoleHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof CreateRole);
        if (DB::table('roles')->where('code', $c->code)->exists()) {
            throw new DomainRuleException('Roleコードは既に使用されています。');
        } $businessId = (int) (DB::table('roles')->lockForUpdate()->max('id') ?? 0) + 1;
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:'.$c->code)->toString();
        RoleAggregate::retrieve($id)->create($businessId, $c->code, $c->name, $c->description, $c->actorUserId)->persist();

        return $c->code;
    }
}
