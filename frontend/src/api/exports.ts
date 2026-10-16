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

/** 複数月をソートし、単一なら"2026-06"、複数なら"2026-06_2026-07"の形式のラベルにする。 */
function yearMonthLabel(yearMonths: string[]): string {
  const sorted = [...yearMonths].sort()
  return sorted.length <= 1 ? sorted[0] : `${sorted[0]}_${sorted[sorted.length - 1]}`
}

/**
 * 勤怠CSVのフィルター。`include_submitted`はtrueのとき提出済み(承認待ち)の月次も対象にする
 * (月次勤怠の画面から1か月単位で出力する場合のみ使う。勤怠出力画面の一括出力は付けない)。
 */
export type AttendanceCsvExportFilters = Pick<AttendanceExportFilters, 'year_month' | 'user_id' | 'format'> & {
  include_submitted?: boolean
}

/**
 * UC-E001: 勤怠CSVを出力する。既定では承認済み・締め済みの月次勤怠が対象で、`include_submitted`
 * をtrueにすると提出済み(承認待ち)も含む。複数月・複数社員をまとめて指定できる。CSVはブラウザの
 * ダウンロードとして扱うため、fetchでBlobを取得してからクリックイベントを合成する(apiFetchの
 * JSON前提の処理には乗せない)。
 * `format`ごとにファイル名が変わるため、`Content-Disposition`ヘッダーから読み取り、
 * 無ければ`attendance_{year_month(s)}.csv`にフォールバックする。
 */
export async function downloadAttendanceCsv(filters: AttendanceCsvExportFilters): Promise<void> {
  const url = new URL('exports/attendance', `${API_BASE_URL.replace(/\/?$/, '/')}`)
  filters.year_month.forEach((yearMonth) => url.searchParams.append('year_month[]', yearMonth))
  filters.user_id?.forEach((userId) => url.searchParams.append('user_id[]', userId))
  if (filters.format !== undefined) url.searchParams.set('format', filters.format)
  if (filters.include_submitted) url.searchParams.set('include_submitted', '1')

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
    `attendance_${yearMonthLabel(filters.year_month)}.csv`
  triggerDownload(blob, filename)
}

/**
 * 勤怠Excel出力のフィルター。`include_submitted`はtrueのとき提出済み(承認待ち)の月次も対象に
 * する(月次勤怠の画面から1か月単位で出力する場合のみ使う。一括出力は付けない)。
 */
export type AttendanceExcelExportFilters = Pick<AttendanceExportFilters, 'year_month' | 'user_id'> & {
  include_submitted?: boolean
}

/**
 * UC-E001: 勤怠Excel(単一社員・単一月次なら.xlsx、複数対象ならZIP)を出力する。複数月・
 * 複数社員をまとめて指定できる。`Content-Type`で拡張子を判定し、ファイル名は
 * `Content-Disposition`があればそれを優先、無ければ`Content-Type`から判定した拡張子で
 * `attendance_{year_month(s)}.xlsx`/`.zip`にフォールバックする。
 */
export async function downloadAttendanceExcel(filters: AttendanceExcelExportFilters): Promise<void> {
  const url = new URL('exports/attendance.xlsx', `${API_BASE_URL.replace(/\/?$/, '/')}`)
  filters.year_month.forEach((yearMonth) => url.searchParams.append('year_month[]', yearMonth))
  filters.user_id?.forEach((userId) => url.searchParams.append('user_id[]', userId))
  if (filters.include_submitted) url.searchParams.set('include_submitted', '1')

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
    `attendance_${yearMonthLabel(filters.year_month)}.${extension}`
  triggerDownload(blob, filename)
}
