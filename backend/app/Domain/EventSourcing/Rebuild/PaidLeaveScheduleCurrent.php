<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * ReadModel(paid_leave_schedule_entries・paid_leave_schedule_assessments)の現在値(論点19 決定3)。
 * 旧版の付与予定イベントは現行のクラスで復元できないため、現行の形のイベント列を行の値から導出する入力に使う。
 *
 * 日時・日付は保存された文字列のまま持つ(PaidLeaveScheduleRebuilder が日付部分を切り出す)。
 */
final readonly class PaidLeaveScheduleCurrent
{
    /**
     * @param  list<array{id: string, periodStart: ?string, periodEnd: ?string, denominatorDays: ?int, attendanceDays: ?int, excludedDays: ?int, attendanceRate: ?float, policyVersion: ?string, automaticResult: ?string, finalResult: ?string, overrideReason: ?string, overriddenByUserId: ?string, createdAt: ?string}>  $assessments  作成日時・id順
     */
    public function __construct(
        public string $id,
        public ?string $userId,
        public ?string $scheduledOn,
        public ?string $category,
        public ?float $candidateGrantDays,
        public ?string $status,
        public bool $isManuallyOverridden,
        public ?string $manualOverrideReason,
        public ?string $manualOverrideByUserId,
        public ?string $grantId,
        public ?string $cancelledReason,
        public ?string $createdAt,
        public ?string $manualOverrideAt = null,
        public ?string $updatedAt = null,
        public array $assessments = [],
    ) {}
}
