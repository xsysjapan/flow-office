import type {
  PaidLeaveGrantCategory,
  PaidLeaveGrantCycleType,
  PaidLeaveGrantPolicies,
  PaidLeaveGrantRuleStep,
  WorkCalendar,
} from '../api/types'

/** 付与日数の表の区分(タブの並び順)。 */
export const GRANT_CATEGORIES: PaidLeaveGrantCategory[] = [
  'normal',
  'proportional_4',
  'proportional_3',
  'proportional_2',
  'proportional_1',
]

export const GRANT_CATEGORY_LABELS: Record<PaidLeaveGrantCategory, string> = {
  normal: '通常',
  proportional_4: '週4日',
  proportional_3: '週3日',
  proportional_2: '週2日',
  proportional_1: '週1日',
}

export const GRANT_CYCLE_TYPE_LABELS: Record<PaidLeaveGrantCycleType, string> = {
  mass_grant_month: '年度はじめに一斉付与',
  anniversary: '入社日を基準に付与',
}

/** 会社カレンダーが無いときの年度開始日(既定値)。 */
export const DEFAULT_FISCAL_YEAR_START: FiscalYearStart = { month: 4, day: 1 }

export interface FiscalYearStart {
  month: number
  day: number
}

/** 既定の会社カレンダーの年度開始日。カレンダーが無ければnull。 */
export function pickDefaultFiscalYearStart(calendars: WorkCalendar[] | undefined): FiscalYearStart | null {
  const defaultCalendar = calendars?.find((calendar) => calendar.is_default)
  if (!defaultCalendar) return null
  return { month: defaultCalendar.fiscal_year_start_month, day: defaultCalendar.fiscal_year_start_day }
}

/** 「4月1日」の形の表記。 */
export function formatMonthDay(value: FiscalYearStart): string {
  return `${value.month}月${value.day}日`
}

/** 比例の週所定日数(法定表のキー)から区分へ変換する。 */
function proportionalCategory(weeklyScheduledDaysCategory: '4' | '3' | '2' | '1'): PaidLeaveGrantCategory {
  return `proportional_${weeklyScheduledDaysCategory}` as PaidLeaveGrantCategory
}

/** 法定の通常表・比例表を、区分付きの行に写す(新規作成の初期値)。 */
export function statutoryGrantSteps(policies: PaidLeaveGrantPolicies): PaidLeaveGrantRuleStep[] {
  const normal: PaidLeaveGrantRuleStep[] = policies.normal.map((step) => ({
    grant_category: 'normal',
    continuous_service_months: step.continuous_service_months,
    grant_days: step.grant_days,
  }))
  const proportional: PaidLeaveGrantRuleStep[] = policies.proportional.map((step) => ({
    grant_category: proportionalCategory(step.weekly_scheduled_days_category),
    continuous_service_months: step.continuous_service_months,
    grant_days: step.grant_days,
  }))
  return [...normal, ...proportional]
}

/** 区分と継続勤務月数に対する法定最低日数(その月数以下で最も大きい段)。法定表に無ければundefined。 */
export function statutoryMinimumDays(
  policies: PaidLeaveGrantPolicies,
  category: PaidLeaveGrantCategory,
  continuousServiceMonths: number,
): number | undefined {
  const rows = statutoryGrantSteps(policies).filter((step) => step.grant_category === category)
  return rows
    .filter((step) => step.continuous_service_months <= continuousServiceMonths)
    .sort((a, b) => b.continuous_service_months - a.continuous_service_months)[0]?.grant_days
}
