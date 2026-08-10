<?php

namespace App\Application\UserManagement\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\UserManagement\Aggregates\ExternalHrImportAggregate;
use App\Domain\UserManagement\Aggregates\MembershipChangeSetAggregate;
use App\Domain\UserManagement\Aggregates\UserAggregate;
use App\Domain\UserManagement\Aggregates\UserMembershipAggregate;
use App\Domain\UserManagement\Commands\ApplyExternalHrImport;
use App\Domain\UserManagement\Services\MembershipConstraintValidator;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @implements CommandHandler<ApplyExternalHrImport> */
class ApplyExternalHrImportHandler implements CommandHandler
{
    public function __construct(private MembershipConstraintValidator $validator) {}

    public function handle(Command $c): string
    {
        assert($c instanceof ApplyExternalHrImport);
        if (! $c->rows) {
            throw new DomainRuleException('取込対象がありません。');
        } $allowed = DB::table('field_authorities')->where('authority_type', 'EXTERNAL_HR')->pluck('field_key')->all();
        $normalized = [];
        $subjects = [];
        foreach ($c->rows as $row) {
            $subject = (string) ($row['external_subject_id'] ?? '');
            if ($subject === '') {
                throw new DomainRuleException('external_subject_idは必須です。');
            } $userId = (string) ($row['user_id'] ?? Str::uuid());
            if (isset($subjects[$subject]) && $subjects[$subject] !== $userId) {
                throw new DomainRuleException('同じ外部HR IDを複数ユーザーへ割り当てることはできません。');
            } $subjects[$subject] = $userId;
            $linkedUserId = DB::table('external_identities')->where('provider', 'EXTERNAL_HR')->where('external_subject_id', $subject)->where('status', 'active')->value('user_id');
            if ($linkedUserId !== null && $linkedUserId !== $userId) {
                throw new DomainRuleException('外部HR IDは別のユーザーへリンク済みです。');
            } $changes = array_intersect_key($row['changes'] ?? [], array_flip($allowed));
            $isNew = ! DB::table('users')->where('id', $userId)->exists();
            if ($isNew && (! isset($changes['display_name']) || ! isset($changes['email']))) {
                throw new DomainRuleException('新規ユーザーにはdisplay_nameとemailが必要です。');
            } $normalized[] = ['user_id' => $userId, 'external_subject_id' => $subject, 'changes' => $changes, 'group_code' => $row['group_code'] ?? null, 'effective_at' => $row['effective_at'] ?? now()->toISOString(), 'is_new' => $isNew];
        } ExternalHrImportAggregate::retrieve($c->importId)->applyImport($normalized, $c->actorUserId)->persist();
        $allUsers = DB::table('groups')->where('code', 'ALL_USERS')->where('status', 'active')->first();
        foreach ($normalized as $row) {
            if ($row['is_new']) {
                if ($allUsers) {
                    UserMembershipAggregate::retrieve(UserManagementStreamId::for('user-membership', $row['user_id']))->add($row['user_id'], $allUsers->id, 'member', false, $c->actorUserId)->persist();
                }
            } if (! $row['group_code']) {
                continue;
            } $group = DB::table('groups')->where('code', $row['group_code'])->where('status', 'active')->first();
            if (! $group) {
                throw new DomainRuleException('CSVに存在しないGroup codeが含まれています。');
            } $existing = DB::table('memberships')->join('groups', 'memberships.group_id', '=', 'groups.id')->where('memberships.user_id', $row['user_id'])->where('groups.group_type_id', $group->group_type_id)->first();
            if ($existing && $existing->group_id === $group->id) {
                continue;
            } $primaryRequired = (bool) DB::table('group_types')->where('id', $group->group_type_id)->value('primary_membership_required');
            $item = $existing ? ['operation' => 'replace', 'group_type_id' => $group->group_type_id, 'from_group_id' => $existing->group_id, 'to_group_id' => $group->id, 'is_primary' => (bool) $existing->is_primary] : ['operation' => 'add', 'group_type_id' => $group->group_type_id, 'target_group_id' => $group->id, 'is_primary' => $primaryRequired];
            $this->validator->validateItems($row['user_id'], [$item]);
            $changeSetId = (string) Str::uuid();
            MembershipChangeSetAggregate::retrieve($changeSetId)->schedule($row['user_id'], $row['effective_at'], 'external_hr', [$item], '外部HR CSV取込', $c->actorUserId)->persist();
        }

        return $c->importId;
    }
}
