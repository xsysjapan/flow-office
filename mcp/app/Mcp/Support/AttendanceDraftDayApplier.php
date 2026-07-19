<?php

namespace App\Mcp\Support;

use App\Models\FieldProvenance;
use App\Models\FieldSourceType;
use App\Models\MonthlyAttendanceDraft;
use App\Models\MonthlyDraftStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * 月次勤怠下書きへの一括反映(旧backend BulkUpdateAttendanceDaysHandlerのmcp/移設版、
 * docs/26-usecases-monthly-import.md「一括更新API」)。楽観ロック(expected_version)・
 * 冪等性(idempotency_key)に対応する。実際の日次勤怠(attendance_days)への書き込みは、
 * backend/の既存の日次編集API(UC-A005、POST/PUT /attendance/days)をそのまま呼び出す。
 * 労働時間計算・休日判定は一切ここで行わない(docs/03-architecture.md 3.5)。
 */
class AttendanceDraftDayApplier
{
    private const IDEMPOTENCY_CACHE_PREFIX = 'mcp:bulk-update-attendance-days:';

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @return array{draft: MonthlyAttendanceDraft, results: array<int, array{date: string, status: string, errors: array<int, array{code: string, message: string}>}>}
     */
    public function apply(
        BackendApiClient $client,
        int $draftId,
        int $ownerUserId,
        int $expectedVersion,
        array $days,
        ?string $idempotencyKey,
        int $actingMcpUserId,
    ): array {
        $cacheKey = $idempotencyKey !== null
            ? self::IDEMPOTENCY_CACHE_PREFIX.$draftId.':'.$idempotencyKey
            : null;

        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return [
                    'draft' => MonthlyAttendanceDraft::query()->findOrFail($cached['draft_id']),
                    'results' => $cached['results'],
                ];
            }
        }

        // 実際のattendance_daysへの書き込みはbackend/へのHTTP呼び出しのため、backend側の
        // 個々の書き込みはbackend/自身のCommandHandlerがトランザクションを持つ。ここでの
        // トランザクションはmcp/自身のDB(下書きのバージョン・出所レコード)の一貫性のみを守る。
        return DB::transaction(function () use ($client, $draftId, $ownerUserId, $expectedVersion, $days, $cacheKey, $actingMcpUserId) {
            $draft = MonthlyAttendanceDraft::query()->where('user_id', $ownerUserId)->lockForUpdate()->findOrFail($draftId);

            if ($draft->version !== $expectedVersion) {
                throw new RuntimeException(
                    "月次勤怠下書き#{$draft->id}への書き込みが競合しました(期待バージョン: {$expectedVersion}、現在: {$draft->version})。"
                );
            }

            $profile = $client->get('/auth/me');
            $timezone = $profile['timezone'] ?? 'Asia/Tokyo';
            $month = $client->get("/attendance/months/{$draft->target_month}");
            $existingDaysByDate = Collection::make($month['days'] ?? [])->keyBy('work_date');

            $results = [];
            $acceptedCount = 0;
            $rejectedCount = 0;

            foreach ($days as $dayInput) {
                $date = $dayInput['date'];

                try {
                    $this->applyDay($client, $date, $dayInput, $draft->id, $timezone, $profile['id'] ?? null, $existingDaysByDate->get($date), $actingMcpUserId);
                    $results[] = ['date' => $date, 'status' => 'ACCEPTED', 'errors' => []];
                    $acceptedCount++;
                } catch (Throwable $e) {
                    $results[] = ['date' => $date, 'status' => 'REJECTED', 'errors' => [['code' => 'DOMAIN_RULE_VIOLATION', 'message' => $e->getMessage()]]];
                    $rejectedCount++;
                }
            }

            $draft->version += 1;
            $draft->status = $rejectedCount > 0 ? MonthlyDraftStatus::NEEDS_REVIEW : MonthlyDraftStatus::READY_TO_SUBMIT;
            $draft->save();

            if ($cacheKey !== null) {
                Cache::put($cacheKey, ['draft_id' => $draft->id, 'results' => $results], now()->addHours(24));
            }

            return ['draft' => $draft, 'results' => $results];
        });
    }

    /**
     * @param  array<string, mixed>  $dayInput
     * @param  array<string, mixed>|null  $existingDay
     */
    private function applyDay(
        BackendApiClient $client,
        string $date,
        array $dayInput,
        int $draftId,
        string $timezone,
        ?int $ownerBackendUserId,
        ?array $existingDay,
        int $actingMcpUserId,
    ): void {
        $sourceType = $dayInput['source'] ?? FieldSourceType::USER_MANUAL_INPUT;
        $startTime = $dayInput['startTime'] ?? null;
        $endTime = $dayInput['endTime'] ?? null;

        if ($startTime !== null && $endTime !== null && $startTime >= $endTime) {
            throw new RuntimeException("開始時刻({$startTime})が終了時刻({$endTime})以降になっています。");
        }

        $startAt = $this->toOffsetIso($date, $startTime, $timezone);
        $endAt = $this->toOffsetIso($date, $endTime, $timezone);
        $breaks = array_map(fn ($break) => [
            'start' => $this->toOffsetIso($date, $break['startTime'], $timezone),
            'end' => isset($break['endTime']) ? $this->toOffsetIso($date, $break['endTime'], $timezone) : null,
        ], $dayInput['breaks'] ?? []);

        $payload = [
            'actual_start_at' => $startAt,
            'actual_end_at' => $endAt,
            'breaks' => $breaks,
            'work_location_type' => $dayInput['workLocationType'] ?? null,
            'note' => $dayInput['workDescription'] ?? null,
            'leave_segments' => [],
            'reason' => '月次一括作成(作業報告書インポート)',
        ];

        if ($existingDay === null) {
            $client->post('/attendance/days', $payload + [
                'user_id' => $ownerBackendUserId,
                'work_date' => $date,
            ]);
        } else {
            $client->put("/attendance/days/{$existingDay['id']}", $payload);
        }

        foreach (['start_time' => $startTime, 'end_time' => $endTime] as $field => $value) {
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
                'confirmed_by_user_id' => $sourceType === FieldSourceType::AI_INFERRED ? null : $actingMcpUserId,
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
