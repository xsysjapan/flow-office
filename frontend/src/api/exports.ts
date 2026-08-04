import { getToken } from './client'
import type { AttendanceExportFilters } from './types'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'

/**
 * `Content-Disposition`ヘッダーからファイル名を読み取る。`filename*=UTF-8''...`
 * (RFC 5987, エンコードされたファイル名)を優先し、無ければ`filename="..."`を使う。
 * どちらも無ければnullを返す(呼び出し側でフォールバックする)。
 */
function filenameFromContentDisposition(header: string | null): string | null {
  if (!header) return null

  const encodedMatch = /filename\*=UTF-8''([^;]+)/i.exec(header)
  if (encodedMatch) {
    try {
      return decodeURIComponent(encodedMatch[1].trim())
    } catch {
      // フォールバックへ
    }
  }

  const quotedMatch = /filename="?([^";]+)"?/i.exec(header)
  if (quotedMatch) return quotedMatch[1].trim()

  return null
}

function triggerDownload(blob: Blob, filename: string): void {
  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = filename
  link.click()
  URL.revokeObjectURL(objectUrl)
}

/**
 * UC-E001: 勤怠CSVを出力する。締め後(UC-A011)の月次勤怠のみが対象。
 * CSVはブラウザのダウンロードとして扱うため、fetchでBlobを取得してから
 * クリックイベントを合成する(apiFetchのJSON前提の処理には乗せない)。
 * `format`ごとにファイル名が変わるため、`Content-Disposition`ヘッダーから読み取り、
 * 無ければ`attendance_{year_month}.csv`にフォールバックする。
 */
export async function downloadAttendanceCsv(filters: AttendanceExportFilters): Promise<void> {
  const url = new URL('exports/attendance', `${API_BASE_URL.replace(/\/?$/, '/')}`)
  url.searchParams.set('year_month', filters.year_month)
  if (filters.user_id !== undefined) url.searchParams.set('user_id[]', String(filters.user_id))
  if (filters.format !== undefined) url.searchParams.set('format', filters.format)

  const token = getToken()
  const response = await fetch(url.toString(), {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })

  if (!response.ok) {
    throw new Error('勤怠CSVの取得に失敗しました。')
  }

  const blob = await response.blob()
  const filename =
    filenameFromContentDisposition(response.headers.get('Content-Disposition')) ??
    `attendance_${filters.year_month}.csv`
  triggerDownload(blob, filename)
}

/**
 * UC-E001: 勤怠Excel(単一社員・単一月次なら.xlsx、複数対象ならZIP)を出力する。
 * `Content-Type`で拡張子を判定し、ファイル名は`Content-Disposition`があればそれを
 * 優先、無ければ`Content-Type`から判定した拡張子で`attendance_{year_month}.xlsx`/`.zip`に
 * フォールバックする。
 */
export async function downloadAttendanceExcel(
  filters: Pick<AttendanceExportFilters, 'year_month' | 'user_id'>,
): Promise<void> {
  const url = new URL('exports/attendance.xlsx', `${API_BASE_URL.replace(/\/?$/, '/')}`)
  url.searchParams.set('year_month', filters.year_month)
  if (filters.user_id !== undefined) url.searchParams.set('user_id[]', String(filters.user_id))

  const token = getToken()
  const response = await fetch(url.toString(), {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })

  if (!response.ok) {
    throw new Error('勤怠Excelの取得に失敗しました。')
  }

  const contentType = response.headers.get('Content-Type') ?? ''
  const extension = contentType.includes('application/zip') ? 'zip' : 'xlsx'

  const blob = await response.blob()
  const filename =
    filenameFromContentDisposition(response.headers.get('Content-Disposition')) ??
    `attendance_${filters.year_month}.${extension}`
  triggerDownload(blob, filename)
}
