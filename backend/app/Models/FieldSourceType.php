<?php

namespace App\Models;

/**
 * field_provenances.source_type。各入力項目の値の出所(docs/26-usecases-monthly-import.md
 * 「AI生成値の出所管理」、docs/03-architecture.md 3.7)。
 */
final class FieldSourceType
{
    public const SOURCE_DOCUMENT = 'source_document';

    public const EXISTING_CLOCK_EVENT = 'existing_clock_event';

    public const EXISTING_ATTENDANCE = 'existing_attendance';

    public const WORK_SCHEDULE = 'work_schedule';

    public const EMPLOYMENT_RULE = 'employment_rule';

    public const AI_INFERRED = 'ai_inferred';

    public const USER_CONFIRMED = 'user_confirmed';

    public const USER_MANUAL_INPUT = 'user_manual_input';

    public const ADMIN_CORRECTION = 'admin_correction';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::SOURCE_DOCUMENT, self::EXISTING_CLOCK_EVENT, self::EXISTING_ATTENDANCE,
            self::WORK_SCHEDULE, self::EMPLOYMENT_RULE, self::AI_INFERRED, self::USER_CONFIRMED,
            self::USER_MANUAL_INPUT, self::ADMIN_CORRECTION,
        ];
    }

    /**
     * ユーザー確認なしに月次申請できない重要項目(docs/26「AI生成値の出所管理」)。
     *
     * @return array<int, string>
     */
    public static function importantFields(): array
    {
        return ['start_time', 'end_time', 'breaks', 'work_date', 'holiday_work', 'leave_conflict'];
    }
}
