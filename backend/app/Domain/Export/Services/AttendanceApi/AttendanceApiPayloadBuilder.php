<?php

namespace App\Domain\Export\Services\AttendanceApi;

use App\Models\AttendanceMonth;

/**
 * 勤怠API連携(フェーズ2)のペイロード組み立ての共通インターフェース。AttendanceCsvFormatと
 * 同じ発想で、連携先(freee/moneyforward)ごとにペイロード構造をBuilder実装へ分ける。
 * docs/33-usecases-attendance-external-api.md参照。
 */
interface AttendanceApiPayloadBuilder
{
    /** 連携先を一意に識別するキー(ExternalIntegrationConnection::providerと一致させる)。 */
    public function key(): string;

    /**
     * @param  string  $externalEmployeeCode  連携先側の従業員ID(ExternalEmployeeMapping)
     * @param  string|null  $externalCompanyId  連携先側の事業所ID(ExternalIntegrationConnection::external_office_id)。
     *                                           連携先によっては不要な場合があるためnull許容。
     * @return array<string, mixed>
     */
    public function build(AttendanceMonth $month, string $externalEmployeeCode, ?string $externalCompanyId = null): array;
}
