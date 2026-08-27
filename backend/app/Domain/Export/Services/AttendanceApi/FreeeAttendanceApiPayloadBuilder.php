<?php

namespace App\Domain\Export\Services\AttendanceApi;

use App\Models\AttendanceMonth;
use App\Models\ExternalIntegrationConnection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * freee人事労務「勤怠情報月次サマリの更新」API
 * (`PUT /api/v1/employees/{employee_id}/work_record_summaries/{year}/{month}`)向けの
 * ペイロード構造。公式OpenAPIスキーマ(freee/freee-api-schema, hr/open-api-3/api-schema.json)で
 * 確認済み(docs/notes/moneyforward-api-investigation.md 4.freee人事労務API)。docs/33参照。
 *
 * `employee_id`/`year`/`month`はURLのパスパラメータであり、リクエストボディには含めない
 * (`ExternalApiPublisher`が予約キー`_path`を使ってエンドポイントURLへ置換する)。
 * `attendance_months.snapshot_json`の確定値をそのまま使い、日次実績・計算ロジック自体は
 * 変更しない(CLAUDE.mdの設計原則3)。値の単位はflow-office側・freee側とも「分」で揃っている。
 *
 * flow-office側にフレックスタイム制・裁量労働制に相当する概念が無いため、
 * `total_shortage_work_mins`(フレックス制のみ)・`total_deemed_paid_excess_statutory_work_mins`・
 * `total_deemed_paid_overtime_except_normal_work_mins`(裁量労働制のみ)は送信しない
 * (freee側で未送信項目は自動的に0になる)。
 *
 * flow-officeは遅刻・早退(`attendance_leave_segments`)を欠勤時間として月次の欠勤集計に含める
 * 設計であり(AttendanceCalculator参照)、freeeの`total_lateness_mins`/`total_early_leaving_mins`に
 * 個別に対応するデータを持たないため、これらも送信しない。同様に「勤務日数」
 * (`work_days`系フィールド)も、稼働日数を独立して集計していないため送信しない。
 */
class FreeeAttendanceApiPayloadBuilder implements AttendanceApiPayloadBuilder
{
    public function key(): string
    {
        return ExternalIntegrationConnection::PROVIDER_FREEE;
    }

    public function build(AttendanceMonth $month, string $externalEmployeeCode, ?string $externalCompanyId = null): array
    {
        if ($externalCompanyId === null || $externalCompanyId === '') {
            throw new RuntimeException('freeeへの勤怠送信には事業所ID(company_id)が必要です。連携設定(external_office_id)を確認してください。');
        }

        $snapshot = $month->snapshot_json ?? [];
        $startOfMonth = Carbon::createFromFormat('Y-m-d', $month->year_month.'-01')->startOfMonth();

        return [
            '_path' => [
                'employee_id' => (string) (int) $externalEmployeeCode,
                'year' => $startOfMonth->format('Y'),
                'month' => (string) (int) $startOfMonth->format('n'),
            ],
            'company_id' => (int) $externalCompanyId,
            // 総労働時間
            'total_work_mins' => (int) ($snapshot['work_minutes'] ?? 0),
            // 所定労働時間
            'total_normal_work_mins' => (int) ($snapshot['prescribed_work_minutes'] ?? 0),
            // 給与計算に用いる法定内残業時間
            'total_excess_statutory_work_mins' => (int) ($snapshot['statutory_within_overtime_minutes'] ?? 0),
            // 実労働時間ベースの法定内残業時間(flow-officeは給与計算用と実労働ベースを区別しないため同値を使う)
            'total_actual_excess_statutory_work_mins' => (int) ($snapshot['statutory_within_overtime_minutes'] ?? 0),
            // 時間外労働時間(法定外残業)
            'total_overtime_work_mins' => (int) ($snapshot['statutory_excess_overtime_minutes'] ?? 0),
            // 法定休日労働時間
            'total_holiday_work_mins' => (int) ($snapshot['legal_holiday_work_minutes'] ?? 0),
            // 所定休日労働時間
            'total_prescribed_holiday_work_mins' => (int) ($snapshot['prescribed_holiday_work_minutes'] ?? 0),
            // 深夜労働時間
            'total_latenight_work_mins' => (int) ($snapshot['late_night_work_minutes'] ?? 0),
            // 欠勤日数
            'num_absences' => (float) ($snapshot['absence_days'] ?? 0),
            // 控除対象欠勤日数(flow-officeは欠勤控除の区分を分けていないため欠勤日数と同値を使う)
            'num_absences_for_deduction' => (float) ($snapshot['absence_days'] ?? 0),
            // 有給取得日数
            'num_paid_holidays' => (float) ($snapshot['paid_leave_days'] ?? 0),
        ];
    }
}
