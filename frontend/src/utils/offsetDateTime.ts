/**
 * 勤怠の勤務時刻(actual_start_at等)は、社員本人の既定タイムゾーンではなく、
 * その勤務日自身が保持するUTCオフセット(utc_offset_minutes)で表示・編集する。
 * 海外出張などで勤務日ごとに現地時刻が変わるため(docs/03-architecture.md 3.4)。
 */

/**
 * オフセット付きISO8601から、タイムゾーン変換をせず「記録された通りの現地日時」を
 * "YYYY-MM-DDTHH:mm" 形式で取り出す。`<input type="datetime-local">` の初期値や、
 * 勤務時刻の表示に使う(ブラウザのローカルタイムゾーンには変換しない)。
 */
export function isoToLocalDatetimeLiteral(iso: string | null | undefined): string {
  if (!iso) return ''
  const match = iso.match(/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})/)
  return match ? match[1] : ''
}

/** オフセット付きISO8601から "HH:mm" だけを取り出す(表示専用)。 */
export function isoToTimeLiteral(iso: string | null | undefined): string {
  const literal = isoToLocalDatetimeLiteral(iso)
  return literal ? literal.slice(11) : ''
}

/** オフセット付きISO8601から "+09:00" 形式のオフセット部分だけを取り出す。 */
export function isoToOffsetString(iso: string | null | undefined): string {
  const match = iso?.match(/(Z|[+-]\d{2}:\d{2})$/)
  if (!match) return browserOffsetString()
  return match[1] === 'Z' ? '+00:00' : match[1]
}

/** UTCオフセット(分)を "+09:00" 形式の文字列に変換する。 */
export function offsetMinutesToString(minutes: number): string {
  const sign = minutes >= 0 ? '+' : '-'
  const abs = Math.abs(minutes)
  const hh = String(Math.floor(abs / 60)).padStart(2, '0')
  const mm = String(abs % 60).padStart(2, '0')
  return `${sign}${hh}:${mm}`
}

/** "+09:00" 形式のオフセット文字列をUTCオフセット(分)に変換する。 */
export function offsetStringToMinutes(offset: string): number {
  const sign = offset.startsWith('-') ? -1 : 1
  const [hours, minutes] = offset.slice(1).split(':').map(Number)
  return sign * (hours * 60 + minutes)
}

/** ブラウザの現在のタイムゾーンオフセットを "+09:00" 形式で返す。 */
export function browserOffsetString(): string {
  return offsetMinutesToString(-new Date().getTimezoneOffset())
}

/**
 * `<input type="datetime-local">` の値("YYYY-MM-DDTHH:mm", タイムゾーン情報なし)と、
 * 明示的なUTCオフセット文字列("+09:00"等)を組み合わせて、オフセット付きISO8601を
 * 組み立てる。APIの日時型は必ずオフセット付きISO8601を要求するため、送信前にこの関数で
 * 付与する(ブラウザの現在地のオフセットではなく、その勤務日が実際に記録されたオフセットを
 * 明示的に渡す)。
 */
export function combineDatetimeLocalWithOffset(value: string, offset: string): string | null {
  if (!value) return null
  return `${value}:00${offset}`
}
