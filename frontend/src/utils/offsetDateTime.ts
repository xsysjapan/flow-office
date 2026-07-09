/**
 * `<input type="datetime-local">` の値("YYYY-MM-DDTHH:mm", タイムゾーン情報なし)は、
 * ブラウザの現地時刻として入力される。APIの日時型は必ずオフセット付きISO8601を
 * 要求するため、送信前にブラウザのタイムゾーンオフセットを付与する。
 */
export function datetimeLocalToIso8601(value: string): string | null {
  if (!value) return null

  const date = new Date(value)
  const offsetMinutes = -date.getTimezoneOffset()
  const sign = offsetMinutes >= 0 ? '+' : '-'
  const abs = Math.abs(offsetMinutes)
  const hh = String(Math.floor(abs / 60)).padStart(2, '0')
  const mm = String(abs % 60).padStart(2, '0')

  return `${value}:00${sign}${hh}:${mm}`
}
