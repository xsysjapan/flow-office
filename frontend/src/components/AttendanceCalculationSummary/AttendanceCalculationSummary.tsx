import type { ReactNode } from 'react'
import { useState } from 'react'
import { Button } from '../Button/Button'
import { Duration } from '../Duration/Duration'

/** 特別休暇の種類ごとの内訳(月次はバックエンド算出、週次はフロントエンドでの
 *  クライアントサイド集計)。両者で同じ形にして本コンポーネントに渡す。 */
export interface AttendanceSpecialLeaveBreakdownItem {
  special_leave_type_id: string
  special_leave_type_name: string
  days: number
  minutes: number
}

export interface AttendanceCalculationSummaryData {
  /** 実労働時間(実際に働いた時間の合計)。給与計算上の労働時間(payroll_work_minutes)は
   *  裁量労働制・みなし労働時間制以外では通常この値と一致するため、別行では表示しない
   *  (実際に異なる場合のみ本コンポーネント側でpayrollWorkMinutesを併記する)。 */
  work_minutes?: number
  prescribed_work_minutes: number
  prescribed_statutory_within_work_minutes?: number
  non_prescribed_statutory_within_work_minutes?: number
  prescribed_statutory_excess_work_minutes?: number
  non_prescribed_statutory_excess_work_minutes?: number
  statutory_within_overtime_minutes: number
  statutory_excess_overtime_minutes: number
  late_night_prescribed_work_minutes: number
  late_night_statutory_within_overtime_minutes: number
  late_night_statutory_excess_overtime_minutes: number
  late_night_prescribed_statutory_within_work_minutes?: number
  late_night_non_prescribed_statutory_within_work_minutes?: number
  late_night_prescribed_statutory_excess_work_minutes?: number
  late_night_non_prescribed_statutory_excess_work_minutes?: number
  legal_holiday_work_minutes: number
  late_night_legal_holiday_work_minutes: number
  prescribed_holiday_work_minutes: number
  late_night_prescribed_holiday_work_minutes: number
  absence_minutes?: number
  paid_leave_days?: number
  paid_leave_minutes?: number
  special_leave_days?: number
  special_leave_minutes?: number
}

export interface AttendanceCalculationSummaryProps {
  title: string
  totals: AttendanceCalculationSummaryData
  statutoryExcessOver60hMinutes?: number
  /** 週40時間(労基法32条)超残業。週次では対象週、月次では月内全週の合計を指定する。 */
  weeklyStatutoryExcessOvertimeMinutes?: number
  absenceDays?: number
  showAllLeaveTotals?: boolean
  /** 特別休暇の種類ごとの内訳。未指定の場合は従来通り合計(special_leave_days/minutes)のみ表示する。 */
  specialLeaveBreakdown?: AttendanceSpecialLeaveBreakdownItem[]
  /** 給与計算上の労働時間(裁量労働制等でwork_minutesと異なる場合のみ)。work_minutesと
   *  一致する場合は表示しない(表示の重複を避けるため呼び出し側で判定せず、本コンポーネントに
   *  常に渡してよい)。 */
  payrollWorkMinutes?: number
}

/** 通常はdt+ddの1組が2列(ラベル・値)を占める。`fullWidth`を指定すると、後続の項目が
 *  ペア(合計値とその深夜内訳)としてずれずに並ぶよう、sm以上でも1行を単独で占有する
 *  (実労働時間・給与計算上の労働時間のように、対応する深夜内訳を持たない項目に使う)。 */
function SummaryItem({ label, children, fullWidth = false }: { label: string; children: ReactNode; fullWidth?: boolean }) {
  return (
    <>
      <dt className="whitespace-nowrap font-medium text-muted-foreground">{label}</dt>
      <dd
        className={`justify-self-end whitespace-nowrap text-foreground sm:justify-self-auto ${fullWidth ? 'sm:col-span-3' : ''}`}
      >
        {children}
      </dd>
    </>
  )
}

/** 日次・週次・月次で共通の勤怠集計。モバイルではラベルと値を1組ずつ表示する。 */
export function AttendanceCalculationSummary({
  title,
  totals,
  statutoryExcessOver60hMinutes,
  weeklyStatutoryExcessOvertimeMinutes,
  absenceDays,
  showAllLeaveTotals = false,
  specialLeaveBreakdown,
  payrollWorkMinutes,
}: AttendanceCalculationSummaryProps) {
  // デフォルトは主要4項目のみ表示し、五分類等の内訳は「詳しく表示」を押した場合のみ見せる
  // (五分類化で表示項目が増え分かりにくいというフィードバックへの対応)。画面遷移でリセットされて
  // よい表示上の切り替えのため、URL/localStorageには永続化せずコンポーネント内stateで持つ
  // (ui-interaction-patterns スキル参照)。
  const [showDetails, setShowDetails] = useState(false)

  // 「残業時間」は法定内残業(statutory_within_overtime_minutes)と法定外残業
  // (statutory_excess_overtime_minutes)の合計。五分類のうち「所定内法定内」を除いた
  // 所定外労働(法定内・法定外の両方)を指し、prescribed_statutory_excess_work_minutes等の
  // 所定/非所定内訳が渡された場合もこの2つのトップレベル値がその合計と一致するため
  // (内訳フィールドはこの合計を所定/所定外にさらに分解したものであり、二重計上にはならない)。
  const overtimeMinutes = totals.statutory_within_overtime_minutes + totals.statutory_excess_overtime_minutes
  // 「うち深夜作業時間」は深夜時間帯(22:00-05:00)の労働をカテゴリ横断で合計したもの。
  // late_night_prescribed_work_minutes + late_night_statutory_within_overtime_minutes +
  // late_night_statutory_excess_overtime_minutes の3つで所定労働日の深夜時間の総量に一致し
  // (docs/07-usecases-attendance.md「深夜時間帯の内訳」)、法定休日労働・所定休日労働の深夜分は
  // 別枠のlate_night_legal_holiday_work_minutes/late_night_prescribed_holiday_work_minutesに
  // 計上されるため、これらをすべて合算する。
  const lateNightMinutes = totals.late_night_prescribed_work_minutes
    + totals.late_night_statutory_within_overtime_minutes
    + totals.late_night_statutory_excess_overtime_minutes
    + totals.late_night_legal_holiday_work_minutes
    + totals.late_night_prescribed_holiday_work_minutes

  const hasLeaveTotals = showAllLeaveTotals
    || !!totals.absence_minutes
    || !!totals.paid_leave_days
    || !!totals.paid_leave_minutes
    || !!totals.special_leave_days
    || !!totals.special_leave_minutes
    || absenceDays !== undefined

  // 特別休暇は種類がわかりにくいという声があったため、渡されていれば1種類のみでも
  // 内訳行(種類名付き)を表示する(合計行は「特別休暇」としか出ないため、種類名は
  // この内訳行でしか分からない)。
  const specialLeaveTypeBreakdown = (specialLeaveBreakdown ?? []).filter((item) => item.days !== 0 || item.minutes !== 0)
  const showSpecialLeaveTypeBreakdown = specialLeaveTypeBreakdown.length > 0

  // 給与計算上の労働時間(payroll_work_minutes)は裁量労働制・みなし労働時間制以外では
  // work_minutesと一致するため、異なる場合のみ別行で表示する(常に両方出すと大半のケースで
  // 同じ数字が並んで見づらいため)。
  const showPayrollWorkMinutes = payrollWorkMinutes !== undefined && payrollWorkMinutes !== totals.work_minutes
  const prescribedWithin = totals.prescribed_statutory_within_work_minutes ?? totals.prescribed_work_minutes
  const nonPrescribedWithin = totals.non_prescribed_statutory_within_work_minutes ?? totals.statutory_within_overtime_minutes
  const prescribedExcess = totals.prescribed_statutory_excess_work_minutes ?? 0
  const nonPrescribedExcess = totals.non_prescribed_statutory_excess_work_minutes ?? totals.statutory_excess_overtime_minutes

  return (
    <section aria-labelledby={`${title}-summary`}>
      <div className="mb-3 flex items-center justify-between gap-2">
        <h3 id={`${title}-summary`} className="text-sm font-medium text-foreground">{title}</h3>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          aria-expanded={showDetails}
          onClick={() => setShowDetails((prev) => !prev)}
        >
          {showDetails ? '簡易表示に戻す' : '詳しく表示'}
        </Button>
      </div>

      {showDetails ? (
        <dl className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 text-sm sm:grid-cols-[auto_1fr_auto_1fr]">
          {totals.work_minutes !== undefined && (
            <SummaryItem label="実労働時間" fullWidth><Duration minutes={totals.work_minutes} /></SummaryItem>
          )}
          {showPayrollWorkMinutes && (
            <SummaryItem label="給与計算上の労働時間" fullWidth><Duration minutes={payrollWorkMinutes} /></SummaryItem>
          )}
          <SummaryItem label="所定内法定内労働時間"><Duration minutes={prescribedWithin} /></SummaryItem>
          <SummaryItem label="うち深夜所定内法定内労働時間"><Duration minutes={totals.late_night_prescribed_statutory_within_work_minutes ?? totals.late_night_prescribed_work_minutes} /></SummaryItem>
          <SummaryItem label="所定外法定内労働時間"><Duration minutes={nonPrescribedWithin} /></SummaryItem>
          <SummaryItem label="うち深夜所定外法定内労働時間"><Duration minutes={totals.late_night_non_prescribed_statutory_within_work_minutes ?? totals.late_night_statutory_within_overtime_minutes} /></SummaryItem>
          <SummaryItem label="所定内法定外労働時間"><Duration minutes={prescribedExcess} /></SummaryItem>
          <SummaryItem label="うち深夜所定内法定外労働時間"><Duration minutes={totals.late_night_prescribed_statutory_excess_work_minutes ?? 0} /></SummaryItem>
          <SummaryItem label="所定外法定外労働時間"><Duration minutes={nonPrescribedExcess} /></SummaryItem>
          <SummaryItem label="うち深夜所定外法定外労働時間"><Duration minutes={totals.late_night_non_prescribed_statutory_excess_work_minutes ?? totals.late_night_statutory_excess_overtime_minutes} /></SummaryItem>
          {statutoryExcessOver60hMinutes !== undefined && (
            <SummaryItem label="うち月60時間超"><Duration minutes={statutoryExcessOver60hMinutes} /></SummaryItem>
          )}
          {weeklyStatutoryExcessOvertimeMinutes !== undefined && (
            <SummaryItem label="うち週40時間超" fullWidth={statutoryExcessOver60hMinutes === undefined}>
              <Duration minutes={weeklyStatutoryExcessOvertimeMinutes} />
            </SummaryItem>
          )}
          <SummaryItem label="法定休日労働時間"><Duration minutes={totals.legal_holiday_work_minutes} /></SummaryItem>
          <SummaryItem label="うち深夜法定休日労働時間"><Duration minutes={totals.late_night_legal_holiday_work_minutes} /></SummaryItem>
        </dl>
      ) : (
        <dl className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 text-sm sm:grid-cols-[auto_1fr_auto_1fr]">
          <SummaryItem label="所定労働時間"><Duration minutes={totals.prescribed_work_minutes} /></SummaryItem>
          <SummaryItem label="残業時間"><Duration minutes={overtimeMinutes} /></SummaryItem>
          <SummaryItem label="法定休日労働時間"><Duration minutes={totals.legal_holiday_work_minutes} /></SummaryItem>
          <SummaryItem label="うち深夜作業時間"><Duration minutes={lateNightMinutes} /></SummaryItem>
        </dl>
      )}

      {showDetails && hasLeaveTotals && (
        <dl className="mt-3 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 border-t border-border pt-3 text-sm sm:grid-cols-[auto_1fr_auto_1fr]">
          {absenceDays !== undefined && <SummaryItem label="欠勤日数">{absenceDays}日</SummaryItem>}
          {(showAllLeaveTotals || !!totals.absence_minutes) && <SummaryItem label="欠勤時間"><Duration minutes={totals.absence_minutes ?? 0} /></SummaryItem>}
          {(showAllLeaveTotals || !!totals.paid_leave_days) && <SummaryItem label="有給日数">{totals.paid_leave_days ?? 0}日</SummaryItem>}
          {(showAllLeaveTotals || !!totals.paid_leave_minutes) && <SummaryItem label="有給時間(時間単位)"><Duration minutes={totals.paid_leave_minutes ?? 0} /></SummaryItem>}
          {(showAllLeaveTotals || !!totals.special_leave_days) && <SummaryItem label="特別休暇日数">{totals.special_leave_days ?? 0}日</SummaryItem>}
          {(showAllLeaveTotals || !!totals.special_leave_minutes) && <SummaryItem label="特別休暇時間"><Duration minutes={totals.special_leave_minutes ?? 0} /></SummaryItem>}
          {showSpecialLeaveTypeBreakdown && specialLeaveTypeBreakdown.map((item) => (
            <SummaryItem key={item.special_leave_type_id} label={`うち${item.special_leave_type_name}`}>
              {item.days > 0 && `${item.days}日`}
              {item.days > 0 && item.minutes > 0 && ' '}
              {item.minutes > 0 && <Duration minutes={item.minutes} />}
            </SummaryItem>
          ))}
        </dl>
      )}
    </section>
  )
}
