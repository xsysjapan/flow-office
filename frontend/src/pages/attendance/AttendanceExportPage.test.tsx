import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { pickYearMonth } from '../../test-support/pickerInteractions'
import { describe, expect, it, vi } from 'vitest'
import * as exportsApi from '../../api/exports'
import { AttendanceExportPage } from './AttendanceExportPage'

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <AttendanceExportPage />
    </QueryClientProvider>,
  )
}

describe('AttendanceExportPage', () => {
  it('disables the download button until a target month is entered', () => {
    renderPage()

    expect(screen.getByRole('button', { name: 'CSVダウンロード' })).toBeDisabled()
  })

  it('downloads the CSV for the entered year_month when clicked', async () => {
    vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockResolvedValue(undefined)
    renderPage()

    await pickYearMonth(userEvent.setup(), '対象月', '2026-06')
    await userEvent.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    await waitFor(() =>
      expect(exportsApi.downloadAttendanceCsv).toHaveBeenCalledWith({
        year_month: '2026-06',
        user_id: undefined,
        format: 'generic',
      }),
    )
  })

  it('shows an error message when the download fails', async () => {
    vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockRejectedValue(new Error('取得に失敗しました'))
    renderPage()

    await pickYearMonth(userEvent.setup(), '対象月', '2026-06')
    await userEvent.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('取得に失敗しました')
  })

  it('downloads the CSV using the selected format', async () => {
    vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockResolvedValue(undefined)
    renderPage()

    await pickYearMonth(userEvent.setup(), '対象月', '2026-06')
    await userEvent.selectOptions(screen.getByLabelText('CSV出力フォーマット'), 'moneyforward')
    await userEvent.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    await waitFor(() =>
      expect(exportsApi.downloadAttendanceCsv).toHaveBeenCalledWith({
        year_month: '2026-06',
        user_id: undefined,
        format: 'moneyforward',
      }),
    )
  })

  it('shows the format description and documentation link for the selected format', async () => {
    renderPage()

    expect(screen.getByText(/どの給与計算ソフトでも列マッピング機能があれば取り込めます/)).toBeInTheDocument()

    await userEvent.selectOptions(screen.getByLabelText('CSV出力フォーマット'), 'freee')

    expect(screen.getByText(/freee人事労務の勤怠データインポート/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'freee人事労務 勤怠データインポート' })).toHaveAttribute(
      'href',
      'https://support.freee.co.jp/hc/ja/articles/204922194',
    )
  })

  it('downloads the Excel file for the entered year_month when clicked', async () => {
    vi.spyOn(exportsApi, 'downloadAttendanceExcel').mockResolvedValue(undefined)
    renderPage()

    await pickYearMonth(userEvent.setup(), '対象月', '2026-06')
    await userEvent.click(screen.getByRole('button', { name: 'Excelダウンロード' }))

    await waitFor(() =>
      expect(exportsApi.downloadAttendanceExcel).toHaveBeenCalledWith({ year_month: '2026-06', user_id: undefined }),
    )
  })
})
