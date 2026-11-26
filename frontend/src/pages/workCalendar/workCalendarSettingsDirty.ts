/** カレンダーの基本設定フォーム(詳細ページ)の「変更の有無」(離脱警告 `ui-interaction-patterns` §2.9)。 */
export interface WorkCalendarSettingsValues {
  name: string
  weekStartsOn: string
  fiscalYearStartMonth: string
  fiscalYearStartDay: string
  allowDailyHolidayOverride: boolean
  weekdayPattern: Record<string, string> | null
}

/** 比べる元(読み込んだ値、または保存で送った値)と今の入力を比べる。休日iCalendarソースの部分は含めない。 */
export function isWorkCalendarSettingsDirty(base: WorkCalendarSettingsValues, current: WorkCalendarSettingsValues): boolean {
  return (
    base.name !== current.name ||
    base.weekStartsOn !== current.weekStartsOn ||
    base.fiscalYearStartMonth !== current.fiscalYearStartMonth ||
    base.fiscalYearStartDay !== current.fiscalYearStartDay ||
    base.allowDailyHolidayOverride !== current.allowDailyHolidayOverride ||
    JSON.stringify(base.weekdayPattern) !== JSON.stringify(current.weekdayPattern)
  )
}
