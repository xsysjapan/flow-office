<?php

namespace App\Domain\Export\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * external_integration.published (勤怠月次確定データ、およびフェーズ3からは経費申請
 * (承認済み)の確定データをfreee/moneyforward等の外部APIへ送信したことを記録する共通イベント)。
 * $attendanceMonthIdは対象データIDの汎用フィールドで、勤怠ではattendance_months.id、
 * 経費ではexpense_claims.idを渡す(対象種別はexportType/idempotencyKeyの接頭辞
 * 'attendance_external_api_'/'expense_external_api_'で区別する。docs/17-events.md参照)。
 * $idempotencyKeyは「対象データID+連携先+出力種別+実行回数」から決定的に導出する
 * (重複送信の検知用。DBの一意制約は設けない)。
 */
class ExternalIntegrationPublished extends ShouldBeStored
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $attendanceMonthId,
        public readonly string $yearMonth,
        public readonly string $employeeUserId,
        public readonly string $externalEmployeeCode,
        public readonly string $idempotencyKey,
        public readonly array $params,
        public readonly string $requestedByUserId,
    ) {}
}
