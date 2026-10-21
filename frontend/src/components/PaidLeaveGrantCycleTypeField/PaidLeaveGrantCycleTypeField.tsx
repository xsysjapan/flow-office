import type { PaidLeaveGrantCycleType } from '../../api/types'
import { formatMonthDay, type FiscalYearStart } from '../../lib/paidLeaveGrantRule'

export interface PaidLeaveGrantCycleTypeFieldProps {
  value: PaidLeaveGrantCycleType
  onChange: (value: PaidLeaveGrantCycleType) => void
  /**
   * 既定の会社カレンダーの年度開始日。undefinedは読み込み中、nullは既定のカレンダーが無い
   * (その場合は「4月1日(既定)」と表示する)。
   */
  defaultFiscalYearStart: FiscalYearStart | null | undefined
}

/** 付与方式(年度はじめに一斉付与 / 入社日を基準に付与)のラジオボタン。 */
export function PaidLeaveGrantCycleTypeField({ value, onChange, defaultFiscalYearStart }: PaidLeaveGrantCycleTypeFieldProps) {
  const fiscalYearStartText =
    defaultFiscalYearStart === undefined
      ? '読み込み中'
      : defaultFiscalYearStart === null
        ? '4月1日(既定)'
        : formatMonthDay(defaultFiscalYearStart)

  return (
    <fieldset className="mb-4 flex flex-col gap-2">
      <legend className="mb-1 text-sm font-medium text-foreground">付与方式</legend>

      <label className="flex items-center gap-2 text-sm text-foreground">
        <input
          type="radio"
          name="paid-leave-grant-cycle-type"
          value="mass_grant_month"
          checked={value === 'mass_grant_month'}
          onChange={() => onChange('mass_grant_month')}
        />
        年度はじめに一斉付与
      </label>
      {value === 'mass_grant_month' && (
        <p className="pl-6 text-xs text-muted-foreground">既定の会社カレンダーの年度開始日: {fiscalYearStartText}</p>
      )}

      <label className="flex items-center gap-2 text-sm text-foreground">
        <input
          type="radio"
          name="paid-leave-grant-cycle-type"
          value="anniversary"
          checked={value === 'anniversary'}
          onChange={() => onChange('anniversary')}
        />
        入社日を基準に付与
      </label>
    </fieldset>
  )
}
