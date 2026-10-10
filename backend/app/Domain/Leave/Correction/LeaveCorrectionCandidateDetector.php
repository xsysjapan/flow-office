<?php

namespace App\Domain\Leave\Correction;

use App\Models\AttendanceDay;
use Illuminate\Support\Facades\DB;

/**
 * 休暇の本番データ補正の候補を検出する(試し実行専用。何も書き込まない)。
 *
 * 変更セット論点12の候補(1)〜(4)を、stored_events(イベント履歴)と勤怠日(投影・読み取りのみ)から検出し、
 * 候補ごとに採りうる方法(直接修正/補正イベント)と推奨・根拠を返す。方法の確定と本実行は、リハーサルの件数を見て
 * ユーザーの許可を得てから行う(.claude/skills/data-correction ステップ2)。
 *
 * 旧系統の前提(検出規則の根拠):
 * - 旧 `*.usage_designated` / `*.usage_reversed` は申請の集約(集約IDが申請ID)に記録されていた(docs/17-events.md)。
 * - 口座の `*_account.usage_designated` は申請IDを持ち(有給は paidLeaveRequestId)、取消は usageId で対応する。
 */
final class LeaveCorrectionCandidateDetector
{
    public const CANDIDATE_RETURNED_USAGE = 1;

    public const CANDIDATE_CREATED_MISSING = 2;

    public const CANDIDATE_LEAVE_VALUE_IN_EVENTS = 3;

    public const CANDIDATE_LEAVE_VALUE_ON_DAYS = 4;

    /** 差戻し時点の申請の状態を表すイベント(申請の集約の版の順に見る)。 */
    private const RETURNED_STATES = [
        'special_leave.request_returned',
        'compensatory_leave.request_returned',
        'paid_leave.request_returned',
        'paid_leave_request.returned',
    ];

    /** 差戻しの後に申請の状態を変えるイベント(再提出・承認・取消)。 */
    private const RELEASED_STATES = [
        'special_leave.request_resubmitted',
        'compensatory_leave.request_resubmitted',
        'paid_leave_request.resubmitted',
        'special_leave.request_approved',
        'compensatory_leave.request_approved',
        'paid_leave.request_approved',
        'paid_leave_request.approved',
        'special_leave.request_cancelled',
        'compensatory_leave.request_cancelled',
        'paid_leave.request_cancelled',
        'paid_leave_request.cancelled',
    ];

    private const PAID_LEAVE_MIGRATED = 'paid_leave_request.migrated';

    /** 旧系統: 申請の集約に記録された消化記録(集約IDが申請ID)。値は休暇の種類。 */
    private const LEGACY_USAGE_DESIGNATED = [
        'special_leave.usage_designated' => 'special_leave',
        'compensatory_leave.usage_designated' => 'compensatory_leave',
        'paid_leave.usage_designated' => 'paid_leave',
    ];

    private const LEGACY_USAGE_REVERSED = [
        'special_leave.usage_reversed',
        'compensatory_leave.usage_reversed',
        'paid_leave.usage_reversed',
    ];

    /** 口座(新系統): 消化記録の確定・取消の対象。request_keyは申請IDのpayloadの項目名。 */
    private const ACCOUNT_USAGE_DESIGNATED = [
        'special_leave_account.usage_designated' => ['kind' => 'special_leave', 'request_key' => 'requestId'],
        'compensatory_leave_account.usage_designated' => ['kind' => 'compensatory_leave', 'request_key' => 'requestId'],
        'paid_leave_account.usage_designated' => ['kind' => 'paid_leave', 'request_key' => 'paidLeaveRequestId'],
    ];

    private const ACCOUNT_USAGE_CANCELLED = [
        'special_leave_account.usage_cancelled',
        'compensatory_leave_account.usage_cancelled',
        'paid_leave_account.usage_cancelled',
    ];

    /** 勤怠日の補完・作成の正当な経路(打刻同期・ライブ状態)。これが最初のイベントの日は補正対象外。 */
    private const LEGITIMATE_FIRST_EVENTS = [
        'attendance_day.synced_from_punches',
        'attendance_day.live_status_synced',
    ];

    /**
     * @return list<array{id: int, title: string, count: int, items: list<array<string, mixed>>, methods: list<array{name: string, recommended: bool, rationale: string}>}>
     */
    public function detect(): array
    {
        return [
            $this->returnedButActiveUsages(),
            $this->attendanceDaysWithoutCreatedEvent(),
            $this->leaveValuesInAttendanceEvents(),
            $this->leaveValuesOnAttendanceDays(),
        ];
    }

    /**
     * (1) 差し戻された休暇の未取消の消化記録。
     */
    private function returnedButActiveUsages(): array
    {
        $states = [];
        $streamEvents = DB::table('stored_events')
            ->whereIn('event_class', array_merge(
                self::RETURNED_STATES,
                self::RELEASED_STATES,
                [self::PAID_LEAVE_MIGRATED],
                array_keys(self::LEGACY_USAGE_DESIGNATED),
                self::LEGACY_USAGE_REVERSED,
            ))
            ->orderBy('id')
            ->get(['id', 'aggregate_uuid', 'event_class', 'event_properties']);

        // 集約(申請)ごとに、消化記録の有無と最後の状態を版(id)の順で求める。
        foreach ($streamEvents as $event) {
            $uuid = (string) $event->aggregate_uuid;
            $state = $states[$uuid] ?? ['active' => false, 'kind' => null, 'returned' => false, 'state_class' => null, 'state_id' => null];
            $class = (string) $event->event_class;

            if (isset(self::LEGACY_USAGE_DESIGNATED[$class])) {
                $state['active'] = true;
                $state['kind'] = self::LEGACY_USAGE_DESIGNATED[$class];
            } elseif (in_array($class, self::LEGACY_USAGE_REVERSED, true)) {
                $state['active'] = false;
            } else {
                $state['returned'] = $class === self::PAID_LEAVE_MIGRATED
                    ? ($this->decode($event->event_properties)['status'] ?? null) === 'returned'
                    : in_array($class, self::RETURNED_STATES, true);
                $state['state_class'] = $class;
                $state['state_id'] = (int) $event->id;
            }

            $states[$uuid] = $state;
        }

        $items = [];
        $seen = [];
        foreach ($states as $uuid => $state) {
            if (! $state['active'] || ! $state['returned'] || $state['kind'] === null) {
                continue;
            }
            $seen[$state['kind'].'|'.$uuid] = true;
            $items[] = [
                'path' => '旧系統(申請の集約に記録された消化記録)',
                'kind' => $state['kind'],
                'request_id' => $uuid,
                'usage_id' => null,
                'last_state_event' => $state['state_class'],
                'last_state_event_id' => $state['state_id'],
            ];
        }

        $cancelledUsageIds = [];
        foreach (DB::table('stored_events')->whereIn('event_class', self::ACCOUNT_USAGE_CANCELLED)->get(['event_properties']) as $event) {
            $usageId = $this->decode($event->event_properties)['usageId'] ?? null;
            if (is_string($usageId)) {
                $cancelledUsageIds[$usageId] = true;
            }
        }

        $designated = DB::table('stored_events')
            ->whereIn('event_class', array_keys(self::ACCOUNT_USAGE_DESIGNATED))
            ->orderBy('id')
            ->get(['id', 'event_class', 'event_properties']);
        foreach ($designated as $event) {
            $map = self::ACCOUNT_USAGE_DESIGNATED[(string) $event->event_class];
            $properties = $this->decode($event->event_properties);
            $requestId = $properties[$map['request_key']] ?? null;
            $usageId = $properties['usageId'] ?? null;
            if (! is_string($requestId) || $requestId === '' || ! is_string($usageId) || isset($cancelledUsageIds[$usageId])) {
                continue;
            }

            $state = $states[$requestId] ?? null;
            if ($state === null || ! $state['returned'] || isset($seen[$map['kind'].'|'.$requestId])) {
                continue;
            }
            $seen[$map['kind'].'|'.$requestId] = true;
            $items[] = [
                'path' => '口座(新系統)',
                'kind' => $map['kind'],
                'request_id' => $requestId,
                'usage_id' => $usageId,
                'designated_event_id' => (int) $event->id,
                'last_state_event' => $state['state_class'],
                'last_state_event_id' => $state['state_id'],
            ];
        }

        return [
            'id' => self::CANDIDATE_RETURNED_USAGE,
            'title' => '(1) 差し戻された休暇の未取消の消化記録',
            'count' => count($items),
            'items' => $items,
            'methods' => [
                [
                    'name' => '補正イベント(今の時点の取消を追記する)',
                    'recommended' => true,
                    'rationale' => '差戻しは業務上の事実で、承認・差戻しの記録は書き換えない(設計原則13)。消化記録の取消は、口座集約の取消Command'.
                        'で今の時点に追記する。旧系統の消化記録は口座への移行(migrated)の後に取消として扱うため、移行コマンドの件数と合わせて方法を決める。',
                ],
                [
                    'name' => '直接修正(payloadの書き換え・削除)',
                    'recommended' => false,
                    'rationale' => '差戻し前に利用者が行った消化記録は不具合による誤記録ではない。data-correction ステップ2の改竄の妥当性を満たさないため採らない。',
                ],
            ],
        ];
    }

    /**
     * (2) 休暇の処理が直接作った勤怠日(attendance_day.created が無い)。
     *
     * 判定はイベント履歴で行う(投影の行は読まない)。最初の勤怠日のイベントが打刻同期・ライブ状態でない日、
     * かつ created・corrected の無い日を対象にする。
     */
    private function attendanceDaysWithoutCreatedEvent(): array
    {
        $uuids = DB::table('stored_events as e')
            ->where('e.event_class', 'like', 'attendance_day.%')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('stored_events as c')
                    ->whereColumn('c.aggregate_uuid', 'e.aggregate_uuid')
                    ->whereIn('c.event_class', ['attendance_day.created', 'attendance_day.corrected']);
            })
            ->select('e.aggregate_uuid')
            ->distinct()
            ->get()
            ->pluck('aggregate_uuid')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();

        $items = [];
        foreach (array_chunk($uuids, 500) as $chunk) {
            $grouped = [];
            $events = DB::table('stored_events')
                ->whereIn('aggregate_uuid', $chunk)
                ->where('event_class', 'like', 'attendance_day.%')
                ->orderBy('aggregate_uuid')
                ->orderBy('aggregate_version')
                ->get(['aggregate_uuid', 'event_class']);
            foreach ($events as $event) {
                $grouped[(string) $event->aggregate_uuid][] = (string) $event->event_class;
            }

            foreach ($grouped as $uuid => $classes) {
                if (in_array($classes[0], self::LEGITIMATE_FIRST_EVENTS, true)) {
                    continue;
                }
                $day = AttendanceDay::query()->find($uuid);
                $items[] = [
                    'attendance_day_id' => $uuid,
                    'first_event' => $classes[0],
                    'events' => count($classes),
                    'row_exists' => $day !== null,
                    'user_id' => $day?->user_id,
                    'work_date' => $day?->work_date?->toDateString(),
                ];
            }
        }

        return [
            'id' => self::CANDIDATE_CREATED_MISSING,
            'title' => '(2) 休暇の処理が直接作った勤怠日(attendance_day.created が無い)',
            'count' => count($items),
            'items' => $items,
            'methods' => [
                [
                    'name' => '補正イベント(attendance_day.corrected を今の時点に追記)',
                    'recommended' => true,
                    'rationale' => '欠けた attendance_day.created を過去の版へ挿入すると、版の振り直しとProjectorの再生順への依存を伴う'.
                        '(挿入は data-correction で行わない)。現在の状態一式を今の時点の補正イベントで追記すれば、行の補完と日次計算の記録値を同時に置ける。',
                ],
                [
                    'name' => '直接修正(欠けた created の挿入)',
                    'recommended' => false,
                    'rationale' => '過去の時点へのイベント挿入は直接修正として扱わない(data-correction)。',
                ],
            ],
        ];
    }

    /**
     * (3) 編集イベント(created・edited)に入った休暇値(workType の paid_leave_* 等)。
     */
    private function leaveValuesInAttendanceEvents(): array
    {
        $items = [];
        DB::table('stored_events')
            ->whereIn('event_class', ['attendance_day.created', 'attendance_day.edited'])
            ->select(['id', 'aggregate_uuid', 'aggregate_version', 'event_class', 'event_properties'])
            ->chunkById(500, function ($rows) use (&$items): void {
                foreach ($rows as $row) {
                    $workType = $this->decode($row->event_properties)['workType'] ?? null;
                    if (is_string($workType) && $this->isLeaveWorkType($workType)) {
                        $items[] = [
                            'stored_event_id' => (int) $row->id,
                            'aggregate_uuid' => (string) $row->aggregate_uuid,
                            'aggregate_version' => (int) $row->aggregate_version,
                            'event_class' => (string) $row->event_class,
                            'work_type' => $workType,
                        ];
                    }
                }
            });

        return [
            'id' => self::CANDIDATE_LEAVE_VALUE_IN_EVENTS,
            'title' => '(3) 編集イベントに入った休暇値(workType の休暇値)',
            'count' => count($items),
            'items' => $items,
            'methods' => [
                [
                    'name' => '直接修正(payloadの workType を null に書き換える。版は変えない)',
                    'recommended' => true,
                    'rationale' => '休暇の処理が勤怠日へ複写した値で、利用者が入力した値ではない。本来値は未設定と機械的に決まり、'.
                        '書き換えは版を変えない。ただし改竄の妥当性の確認とユーザーの許可が前提。リビルドで勤怠日の値も消える。',
                ],
                [
                    'name' => '補正イベント(勤怠日の状態一式で上書き)',
                    'recommended' => false,
                    'rationale' => '勤怠日の表示は補正イベントで正せるが、過去の誤った値は履歴に残る。直接修正で解消しない行の補完に使う。',
                ],
            ],
        ];
    }

    /**
     * (4) 勤怠日(投影)に残る休暇値・全休の status(実績も打刻も無い clocked_out)。
     */
    private function leaveValuesOnAttendanceDays(): array
    {
        $items = [];

        $days = AttendanceDay::query()
            ->where('work_type', 'like', '%_leave_%')
            ->orderBy('work_date')
            ->get(['id', 'user_id', 'work_date', 'status', 'source', 'work_type']);
        foreach ($days as $day) {
            if ($this->isLeaveWorkType((string) $day->work_type)) {
                $items[$day->id] = $this->dayItem($day, '休暇値が残る');
            }
        }

        $fullDays = AttendanceDay::query()
            ->where('status', 'clocked_out')
            ->whereNull('actual_start_at')
            ->whereNull('actual_end_at')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('attendance_punches')
                    ->whereColumn('attendance_punches.user_id', 'attendance_days.user_id')
                    ->whereColumn('attendance_punches.work_date', 'attendance_days.work_date');
            })
            ->orderBy('work_date')
            ->get(['id', 'user_id', 'work_date', 'status', 'source', 'work_type']);
        foreach ($fullDays as $day) {
            $items[$day->id] ??= $this->dayItem($day, '全休のstatus(実績・打刻なし)');
        }

        return [
            'id' => self::CANDIDATE_LEAVE_VALUE_ON_DAYS,
            'title' => '(4) 勤怠日に残る休暇値・全休の status',
            'count' => count($items),
            'items' => array_values($items),
            'methods' => [
                [
                    'name' => '候補(3)の直接修正とリビルド後に残る行のみ補正イベント(attendance_day.corrected)',
                    'recommended' => true,
                    'rationale' => '勤怠日は投影であり、候補(3)を直したうえでevent-sourcing:replayを行えば解消する見込み。'.
                        '解消しない行は、今の状態一式を今の時点の補正イベントで置く。',
                ],
                [
                    'name' => '勤怠日テーブルの直接UPDATE',
                    'recommended' => false,
                    'rationale' => 'ReadModelだけを書き換える補正は設計原則2により行わない。',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dayItem(AttendanceDay $day, string $reason): array
    {
        return [
            'attendance_day_id' => (string) $day->id,
            'user_id' => (string) $day->user_id,
            'work_date' => $day->work_date?->toDateString(),
            'status' => (string) $day->status,
            'source' => (string) $day->source,
            'work_type' => $day->work_type,
            'reason' => $reason,
        ];
    }

    private function isLeaveWorkType(string $workType): bool
    {
        return preg_match('/^(paid|special|compensatory)_leave_/', $workType) === 1;
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
