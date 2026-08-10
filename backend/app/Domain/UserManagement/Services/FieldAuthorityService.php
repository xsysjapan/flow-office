<?php

namespace App\Domain\UserManagement\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Illuminate\Support\Facades\DB;

final class FieldAuthorityService
{
    public function isExternalHr(string $field): bool
    {
        return DB::table('field_authorities')->where('field_key', $field)->where('authority_type', 'EXTERNAL_HR')->exists();
    }

    public function assertLocallyEditable(array $fields): void
    {
        $authorityKeys = array_map(fn (string $field) => $field === 'name' ? 'display_name' : $field, $fields);
        $blocked = DB::table('field_authorities')->whereIn('field_key', $authorityKeys)->where('authority_type', 'EXTERNAL_HR')->pluck('field_key')->all();
        if ($blocked) {
            throw new DomainRuleException('外部HR管理項目は更新できません: '.implode(', ', $blocked));
        }
    }
}
