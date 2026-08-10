<?php

namespace App\Domain\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\ExternalIdentityAggregate;
use App\Domain\UserManagement\Commands\LinkExternalIdentity;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;

/** @implements CommandHandler<LinkExternalIdentity> */
class LinkExternalIdentityHandler implements CommandHandler
{
    public function handle(Command $c): string
    {
        assert($c instanceof LinkExternalIdentity);
        if (! DB::table('users')->where('id', $c->userId)->exists()) {
            throw new DomainRuleException('ユーザーが存在しません。');
        }
        if ($c->provider === 'MICROSOFT_ENTRA') {
            $tenant = SystemSetting::current()->m365_tenant_id;
            if (! $tenant || ! $c->externalTenantId || strcasecmp($tenant, $c->externalTenantId) !== 0) {
                throw new DomainRuleException('許可されていないMicrosoft Entra Tenantです。');
            }
        }
        $duplicate = DB::table('external_identities')->where('provider', $c->provider)->where('external_subject_id', $c->externalSubjectId)->where('status', 'active')->first();
        if ($duplicate && $duplicate->user_id !== $c->userId) {
            throw new DomainRuleException('この外部IDは別のユーザーにリンクされています。');
        }
        ExternalIdentityAggregate::retrieve(UserManagementStreamId::for('user-identity', $c->userId))->link($c->userId, $c->provider, $c->externalTenantId, $c->externalSubjectId, $c->externalCode, $c->email, $c->actorUserId)->persist();

        return $c->userId;
    }
}
