<?php

namespace App\Domain\Export\Aggregates;

use App\Domain\Export\Events\ExportCreated;
use App\Domain\Export\Events\ExternalIntegrationPublished;
use App\Domain\Export\Events\InternalArchiveCreated;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/** An export is an immutable audit stream with exactly one creation fact. */
final class ExportAuditAggregate extends AggregateRoot
{
    /** @param array<string, mixed> $params */
    public function record(
        string $exportType,
        array $params,
        string $requestedByUserId,
        int $rowCount,
        ?string $idempotencyKey = null,
    ): self {
        $this->recordThat(new ExportCreated($exportType, $params, $requestedByUserId, $rowCount, $idempotencyKey));

        return $this;
    }

    /**
     * UC-X012: 経費の証跡アーカイブExcelを内部保存したことを記録する(internal_archive.created)。
     *
     * @param  array<string, mixed>  $params
     */
    public function recordInternalArchive(
        string $exportType,
        string $subjectId,
        array $params,
        string $requestedByUserId,
        ?string $location,
        int $rowCount,
    ): self {
        $this->recordThat(new InternalArchiveCreated(
            $exportType,
            $subjectId,
            self::idempotencyKeyFor($exportType, $subjectId, $this->aggregateVersion + 1),
            $params,
            $requestedByUserId,
            $location,
            $rowCount,
        ));

        return $this;
    }

    /**
     * フェーズ2: 勤怠月次確定データをfreee/moneyforward等の外部APIへ送信したことを記録する
     * (external_integration.published)。冪等性キーは「対象データID(attendance_months.id)
     * +連携先+出力種別+実行回数」から決定的に導出する。
     *
     * @param  array<string, mixed>  $params
     */
    public function recordExternalPublish(
        string $provider,
        string $attendanceMonthId,
        string $yearMonth,
        string $employeeUserId,
        string $externalEmployeeCode,
        array $params,
        string $requestedByUserId,
    ): self {
        $exportType = 'attendance_external_api_'.$provider;

        $this->recordThat(new ExternalIntegrationPublished(
            $provider,
            $attendanceMonthId,
            $yearMonth,
            $employeeUserId,
            $externalEmployeeCode,
            self::idempotencyKeyFor($exportType, $attendanceMonthId, $this->aggregateVersion + 1),
            $params,
            $requestedByUserId,
        ));

        return $this;
    }

    /**
     * フェーズ3: 経費申請(承認済み)の確定値をfreee/moneyforward等の外部APIへ送信したことを
     * 記録する(external_integration.published)。イベントクラス・冪等性キーの導出方法は
     * recordExternalPublish()(勤怠)と同一で、対象データIDにexpense_claims.idを渡す
     * (docs/30-usecases-expense.md UC-X012、docs/17-events.md参照)。
     *
     * @param  array<string, mixed>  $params
     */
    public function recordExpenseExternalPublish(
        string $provider,
        string $expenseClaimId,
        string $periodLabel,
        string $employeeUserId,
        string $externalEmployeeCode,
        array $params,
        string $requestedByUserId,
    ): self {
        $exportType = 'expense_external_api_'.$provider;

        $this->recordThat(new ExternalIntegrationPublished(
            $provider,
            $expenseClaimId,
            $periodLabel,
            $employeeUserId,
            $externalEmployeeCode,
            self::idempotencyKeyFor($exportType, $expenseClaimId, $this->aggregateVersion + 1),
            $params,
            $requestedByUserId,
        ));

        return $this;
    }

    /**
     * 冪等性キー: 「対象データID+出力種別+実行回数」から決定的に導出する。同一対象・同一
     * 出力種別・同一実行回数での再実行を検知するための監査用キー(DBの一意制約は設けない)。
     */
    public static function idempotencyKeyFor(string $exportType, string $subjectId, int $executionCount): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, "{$exportType}:{$subjectId}:{$executionCount}")->toString();
    }
}
