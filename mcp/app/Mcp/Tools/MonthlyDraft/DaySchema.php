<?php

namespace App\Mcp\Tools\MonthlyDraft;

/**
 * 日次勤怠編集系・月次勤怠系ツール共通の1日分入力スキーマ(mcp-server/src/tools/monthlyDraft.ts
 * の dayShape に対応)。
 */
final class DaySchema
{
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'startTime' => ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$'],
                'endTime' => ['type' => 'string', 'pattern' => '^\d{2}:\d{2}$'],
                'breaks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'startTime' => ['type' => 'string'],
                            'endTime' => ['type' => 'string'],
                        ],
                        'required' => ['startTime'],
                    ],
                ],
                'workLocationType' => [
                    'type' => 'string',
                    'enum' => ['office', 'remote', 'client_site', 'business_trip', 'direct_to_site', 'direct_from_site', 'other'],
                ],
                'workDescription' => ['type' => 'string'],
                'source' => [
                    'type' => 'string',
                    'enum' => [
                        'source_document', 'existing_clock_event', 'existing_attendance', 'work_schedule',
                        'employment_rule', 'ai_inferred', 'user_confirmed', 'user_manual_input', 'admin_correction',
                    ],
                ],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required' => ['date'],
        ];
    }
}
