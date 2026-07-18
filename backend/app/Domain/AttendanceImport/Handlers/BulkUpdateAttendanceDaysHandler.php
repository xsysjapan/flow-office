<?php

namespace App\Domain\AttendanceImport\Handlers;

use App\Domain\Attendance\Commands\CreateAttendanceDay;
use App\Domain\Attendance\Commands\EditAttendanceDay;
use App\Domain\Attendance\Handlers\CreateAttendanceDayHandler;
use App\Domain\Attendance\Handlers\EditAttendanceDayHandler;
use App\Domain\AttendanceImport\Commands\BulkUpdateAttendanceDays;
use App\Domain\AttendanceImport\Events\MonthlyAttendanceDraftUpdated;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\ConcurrencyException;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\FieldProvenance;
use App\Models\FieldSourceType;
use App\Models\MonthlyAttendanceDraft;
use App\Models\MonthlyDraftStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * 月次一括更新(docs/26-usecases-monthly-import.md「一括更新API」)。楽観ロック・冪等性に
 * 対応し、日ごとの成功・警告・失敗を返す。既存のCreateAttendanceDayHandler/
 * EditAttendanceDayHandlerをそのまま呼び出し、勤怠計算ロジックを複製しない
 * (docs/03-architecture.md 3.5)。
 *
 * @implements CommandHandler<BulkUpdateAttendanceDays>
 */
class BulkUpdateAttendanceDaysHandler implements CommandHandler
{
    private const IDEMPOTENCY_CACHE_PREFIX = 'bulk-update-attendance-days:';

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly CreateAttendanceDayHandler $createDayHandler,
        private readonly EditAttendanceDayHandler $editDayHandler,
    ) {}

    /**
     * @return array{draft: MonthlyAttendanceDraft, results: array<int, array{date: string, status: string, errors: array<int, array{code: string, message: string}>}>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof BulkUpdateAttendanceDays);

        $cacheKey = $command->idempotencyKey !== null
            ? self::IDEMPOTENCY_CACHE_PREFIX.$command->draftId.':'.$command->idempotencyKey
            : null;

        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                // 同じIdempotency-Keyでの再送は再処理せず、直前の結果をそのまま返す
                // (docs/26「冪等性」)。
                return [
                    'draft' => MonthlyAttendanceDraft::query()->findOrFail($cached['draft_id']),
                    'results' => $cached['results'],
                ];
            }
        }

        // lockForUpdate()で行ロックを取得し、バージョン確認から書き込みまでを
        // アトミックにする(CommandBus::dispatch()がこのhandle()全体をDB::transaction()で
        // 包んでいるため、ロックはこのトランザクションのコミット/ロールバックまで保持される)。
        // ロックが無いと、同じexpected_versionを持つ2つの同時リクエストが両方チェックを
        // 通過し、一方の更新がもう一方に静かに上書きされるlost updateが起こり得る。
        $draft = MonthlyAttendanceDraft::query()->lockForUpdate()->findOrFail($command->draftId);

        if ($draft->version !== $command->expectedVersion) {
            throw new ConcurrencyException(
                "月次勤怠下書き#{$draft->id}への書き込みが競合しました(期待バージョン: {$command->expectedVersion}、現在: {$draft->version})。"
            );
        }

        $user = User::query()->findOrFail($draft->user_id);
        $results = [];
        $acceptedCount = 0;
        $rejectedCount = 0;

        foreach ($command->days as $dayInput) {
            $date = $dayInput['date'];
            $sourceType = $dayInput['source'] ?? FieldSourceType::USER_MANUAL_INPUT;

            try {
                $this->applyDay($user, $date, $dayInput, $draft->id, $sourceType);
                $results[] = ['date' => $date, 'status' => 'ACCEPTED', 'errors' => []];
                $acceptedCount++;
            } catch (DomainRuleException $e) {
                $results[] = ['date' => $date, 'status' => 'REJECTED', 'errors' => [['code' => 'DOMAIN_RULE_VIOLATION', 'message' => $e->getMessage()]]];
                $rejectedCount++;
            }
        }

        $draft->version += 1;
        $draft->status = $rejectedCount > 0 ? MonthlyDraftStatus::NEEDS_REVIEW : MonthlyDraftStatus::READY_TO_SUBMIT;
        $draft->save();

        $this->eventStore->append(
            aggregateType: 'monthly_attendance_draft',
            aggregateId: (string) $draft->id,
            event: new MonthlyAttendanceDraftUpdated(
                draftId: $draft->id,
                version: $draft->version,
                acceptedCount: $acceptedCount,
                rejectedCount: $rejectedCount,
                updatedByUserId: $command->updatedByUserId,
            ),
        );

        if ($cacheKey !== null) {
            Cache::put($cacheKey, ['draft_id' => $draft->id, 'results' => $results], now()->addHours(24));
        }

        return ['draft' => $draft, 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $dayInput
     */
    private function applyDay(User $user, string $date, array $dayInput, int $draftId, string $sourceType): void
    {
        $startTime = $dayInput['startTime'] ?? null;
        $endTime = $dayInput['endTime'] ?? null;
        if ($startTime !== null && $endTime !== null && $startTime >= $endTime) {
            // 一括更新のdays[]は同一workDate内のstartTime/endTimeのみを表現するため
            // (日跨ぎ勤務はUC-A005の日次編集で個別に扱う。docs/07「複数勤務区間」参照)、
            // 終了時刻が開始時刻以前になる入力は不正とする(INVALID_TIME_RANGE)。
            throw new DomainRuleException("開始時刻({$startTime})が終了時刻({$endTime})以降になっています。");
        }

        $startAt = $this->toOffsetIso($date, $startTime, $user->timezone);
        $endAt = $this->toOffsetIso($date, $endTime, $user->timezone);
        $breaks = array_map(fn ($break) => [
            'start' => $this->toOffsetIso($date, $break['startTime'], $user->timezone),
            'end' => isset($break['endTime']) ? $this->toOffsetIso($date, $break['endTime'], $user->timezone) : null,
        ], $dayInput['breaks'] ?? []);

        $existing = AttendanceDay::query()->where('user_id', $user->id)->whereDate('work_date', $date)->first();

        if ($existing === null) {
            $this->createDayHandler->handle(new CreateAttendanceDay(
                userId: $user->id,
                workDate: $date,
                actualStartAt: $startAt,
                actualEndAt: $endAt,
                breaks: $breaks,
                workType: null,
                note: $dayInput['workDescription'] ?? null,
                leaveSegments: [],
                reason: '月次一括作成(作業報告書インポート)',
                createdByUserId: $user->id,
                workLocationType: $dayInput['workLocationType'] ?? null,
            ));
        } else {
            $this->editDayHandler->handle(new EditAttendanceDay(
                attendanceDayId: $existing->id,
                actualStartAt: $startAt,
                actualEndAt: $endAt,
                breaks: $breaks,
                workType: $existing->work_type,
                note: $dayInput['workDescription'] ?? $existing->note,
                leaveSegments: [],
                reason: '月次一括作成(作業報告書インポート)',
                editedByUserId: $user->id,
                workLocationType: $dayInput['workLocationType'] ?? $existing->work_location_type,
                workLocationTypeProvided: true,
            ));
        }

        foreach (['start_time' => $dayInput['startTime'] ?? null, 'end_time' => $dayInput['endTime'] ?? null] as $field => $value) {
            if ($value === null) {
                continue;
            }
            FieldProvenance::query()->create([
                'entity_type' => FieldProvenance::ENTITY_MONTHLY_ATTENDANCE_DRAFT,
                'entity_id' => $draftId,
                'field_name' => "{$date}:{$field}",
                'source_type' => $sourceType,
                'confidence' => $dayInput['confidence'] ?? null,
                'source_reference_json' => $dayInput['sourceReferences'] ?? null,
                'confirmed_at' => $sourceType === FieldSourceType::AI_INFERRED ? null : Carbon::now(),
                'confirmed_by_user_id' => $sourceType === FieldSourceType::AI_INFERRED ? null : $user->id,
            ]);
        }
    }

    private function toOffsetIso(string $date, ?string $time, string $timezone): ?string
    {
        if ($time === null) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}", $timezone)->toIso8601String();
    }
}
