<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\GroupAggregate;
use App\Domain\UserManagement\Commands\UpdateGroup;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<UpdateGroup> */ class UpdateGroupHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof UpdateGroup);
        $group = DB::table('groups')->where('id', $c->groupId)->lockForUpdate()->first();
        if (! $group) {
            throw new DomainRuleException('グループが存在しません。');
        } if (in_array($group->code, ['ALL_USERS', 'SYSTEM_ADMINISTRATORS', 'BACKOFFICE_USERS'], true) && ($group->code !== $c->code || $c->status !== 'active')) {
            throw new DomainRuleException('システムグループのコード・状態は変更できません。');
        } if (DB::table('groups')->where('code', $c->code)->where('id', '!=', $c->groupId)->exists()) {
            throw new DomainRuleException('グループコードは既に使用されています。');
        } if ($c->parentGroupId && ! DB::table('groups')->where('id', $c->parentGroupId)->where('group_type_id', $group->group_type_id)->where('status', 'active')->exists()) {
            throw new DomainRuleException('同じGroupTypeの有効な親グループを指定してください。');
        } $parent = $c->parentGroupId;
        while ($parent) {
            if ($parent === $c->groupId) {
                throw new DomainRuleException('グループ階層を循環させることはできません。');
            } $parent = DB::table('groups')->where('id', $parent)->value('parent_group_id');
        } GroupAggregate::retrieve($c->groupId)->update($c->name, $c->code, $c->description, $c->parentGroupId, $c->status, $c->actorUserId)->persist();

        return $c->groupId;
    }
}
