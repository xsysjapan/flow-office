<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use PHPUnit\Framework\TestCase;

/**
 * 作り直しの出力の先頭に警告を出す「利用者・権限のテーブルの変更」の判定(docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点2)。
 */
class ReadModelRebuilderAccountTablesTest extends TestCase
{
    private function row(string $table, bool $changed): array
    {
        return ['table' => $table, 'before' => 1, 'after' => $changed ? 2 : 1, 'changed' => $changed];
    }

    public function test_account_tables_are_the_eight_user_and_permission_tables(): void
    {
        $this->assertSame(
            ['users', 'memberships', 'groups', 'roles', 'role_assignments', 'permission_role', 'role_features', 'group_feature_assignments'],
            ReadModelRebuilder::ACCOUNT_TABLES,
        );
    }

    public function test_only_changed_account_tables_are_returned(): void
    {
        $rows = [
            $this->row('users', true),
            $this->row('role_assignments', true),
            $this->row('groups', false),
            $this->row('paid_leave_grants', true),
            $this->row('attendance_days', false),
        ];

        $this->assertSame(['users', 'role_assignments'], ReadModelRebuilder::changedAccountTables($rows));
    }

    public function test_no_warning_target_when_nothing_changed_or_only_other_tables_changed(): void
    {
        $this->assertSame([], ReadModelRebuilder::changedAccountTables([]));
        $this->assertSame([], ReadModelRebuilder::changedAccountTables([$this->row('users', false), $this->row('paid_leave_grants', true)]));
    }
}
