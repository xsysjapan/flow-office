/** 働き方の一般設定(初回オンボーディングの編集フォーム)の「変更の有無」(離脱警告 `ui-interaction-patterns` §2.9)。 */
export interface WorkStyleOnboardingValues {
  name: string
  startTime: string
  endTime: string
  breakMinutes: string
}

export function isWorkStyleOnboardingDirty(base: WorkStyleOnboardingValues, current: WorkStyleOnboardingValues): boolean {
  return (
    base.name !== current.name ||
    base.startTime !== current.startTime ||
    base.endTime !== current.endTime ||
    base.breakMinutes !== current.breakMinutes
  )
}
