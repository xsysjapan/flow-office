<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\ExternalIdentityAggregate;
use App\Domain\UserManagement\Commands\UnlinkExternalIdentity;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<UnlinkExternalIdentity> */ class UnlinkExternalIdentityHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof UnlinkExternalIdentity);
        $row = DB::table('external_identities')->where('id', $c->identityId)->where('status', 'active')->first();
        if (! $row) {
            throw new DomainRuleException('有効な外部IDリンクが存在しません。');
        } ExternalIdentityAggregate::retrieve(UserManagementStreamId::for('user-identity', $row->user_id))->unlink($row->user_id, $c->identityId, $row->provider, $row->external_subject_id, $c->actorUserId)->persist();

        return $row->user_id;
    }
}
