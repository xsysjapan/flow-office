import { getToken } from './client'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'

export interface AttendanceExportFilters {
  year_month: string
  user_id?: number
}

/**
 * UC-E001: 勤怠CSVを出力する。締め後(UC-A011)の月次勤怠のみが対象。
 * CSVはブラウザのダウンロードとして扱うため、fetchでBlobを取得してから
 * クリックイベントを合成する(apiFetchのJSON前提の処理には乗せない)。
 */
export async function downloadAttendanceCsv(filters: AttendanceExportFilters): Promise<void> {
  const url = new URL('exports/attendance', `${API_BASE_URL.replace(/\/?$/, '/')}`)
  url.searchParams.set('year_month', filters.year_month)
  if (filters.user_id !== undefined) url.searchParams.set('user_id[]', String(filters.user_id))

  const token = getToken()
  const response = await fetch(url.toString(), {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })

  if (!response.ok) {
    throw new Error('勤怠CSVの取得に失敗しました。')
  }

  const blob = await response.blob()
  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = `attendance_${filters.year_month}.csv`
  link.click()
  URL.revokeObjectURL(objectUrl)
}
