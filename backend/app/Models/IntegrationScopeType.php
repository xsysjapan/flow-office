<?php

namespace App\Models;

/**
 * integration_scopes.scope。個人API/MCP連携に許可できるスコープの固定一覧
 * (docs/25-usecases-integrations-mcp.md UC-I002「個人連携の権限」)。他人の勤怠閲覧・登録・
 * 代理打刻、勤怠承認、月次締め、組織設定変更、端末管理、認証キーの管理者操作は含めない。
 */
final class IntegrationScopeType
{
    public const ATTENDANCE_SELF_READ = 'attendance:self:read';

    public const ATTENDANCE_SELF_CLOCK = 'attendance:self:clock';

    public const ATTENDANCE_SELF_DRAFT = 'attendance:self:draft';

    public const ATTENDANCE_SELF_UPDATE = 'attendance:self:update';

    public const ATTENDANCE_SELF_VALIDATE = 'attendance:self:validate';

    public const ATTENDANCE_SELF_SUBMIT = 'attendance:self:submit';

    public const LEAVE_SELF_READ = 'leave:self:read';

    public const LEAVE_SELF_CREATE = 'leave:self:create';

    public const SCHEDULE_SELF_READ = 'schedule:self:read';

    public const REPORT_SELF_IMPORT = 'report:self:import';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::ATTENDANCE_SELF_READ, self::ATTENDANCE_SELF_CLOCK, self::ATTENDANCE_SELF_DRAFT,
            self::ATTENDANCE_SELF_UPDATE, self::ATTENDANCE_SELF_VALIDATE, self::ATTENDANCE_SELF_SUBMIT,
            self::LEAVE_SELF_READ, self::LEAVE_SELF_CREATE, self::SCHEDULE_SELF_READ, self::REPORT_SELF_IMPORT,
        ];
    }
}
