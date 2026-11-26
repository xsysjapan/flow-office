/**
 * カレンダーの日別編集の「変更の有無」(離脱警告 `ui-interaction-patterns` §2.9)。
 * 比べる元(読み込んだ日別データ、または保存で送った値)の各日と、今の編集内容を比べる。
 */
export function isWorkCalendarDaysDirty<T>(base: ReadonlyMap<string, T>, current: ReadonlyMap<string, T>): boolean {
  for (const [date, baseState] of base) {
    if (JSON.stringify(current.get(date)) !== JSON.stringify(baseState)) return true
  }
  return false
}
