import type { AttendanceDayLeave } from '../api/types'

/**
 * 勤怠日の休暇(休暇ビュー`leaves`)が全休に当たるかを判定する。
 *
 * 全休の日は出勤不可・打刻を取り込まない・打刻漏れ警告を出さない(バックエンドの休暇ビューと同じ基準)。
 * 次のどちらかを全休とする:
 * - 取得単位が全休(full)の休暇がある
 * - 午前半休(am_half)と午後半休(pm_half)が種類を問わずそろう(仕様確定事項I)
 * 時間休だけの日は全休ではない。
 */
export function isFullDayLeave(leaves: AttendanceDayLeave[] | undefined): boolean {
  const list = leaves ?? []
  if (list.some((leave) => leave.unit === 'full')) return true
  return list.some((leave) => leave.unit === 'am_half') && list.some((leave) => leave.unit === 'pm_half')
}
