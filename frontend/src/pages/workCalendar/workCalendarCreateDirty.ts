/** 勤務カレンダーの作成フォームの「変更の有無」(離脱警告 `ui-interaction-patterns` §2.9)。 */
export interface WorkCalendarCreateFormValues {
  name: string
  weekStartsOn: string
  fiscalYearStartMonth: string
  fiscalYearStartDay: string
  weekdayPattern: Record<string, string>
  allowDailyHolidayOverride: boolean
  holidaySourceId: string
}

/** 比べる元(初期値)と今の入力を比べる。祝日iCalendarソースの登録(即時に保存される操作)は含めない。 */
export function isWorkCalendarCreateDirty(base: WorkCalendarCreateFormValues, current: WorkCalendarCreateFormValues): boolean {
  return (
    base.name !== current.name ||
    base.weekStartsOn !== current.weekStartsOn ||
    base.fiscalYearStartMonth !== current.fiscalYearStartMonth ||
    base.fiscalYearStartDay !== current.fiscalYearStartDay ||
    base.allowDailyHolidayOverride !== current.allowDailyHolidayOverride ||
    base.holidaySourceId !== current.holidaySourceId ||
    Object.keys({ ...base.weekdayPattern, ...current.weekdayPattern }).some((key) => base.weekdayPattern[key] !== current.weekdayPattern[key])
  )
}
