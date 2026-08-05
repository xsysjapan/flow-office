import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { pickYearMonth } from '../../test-support/pickerInteractions'
import { describe, expect, it, vi } from 'vitest'
import * as exportsApi from '../../api/exports'
import * as usersApi from '../../api/users'
import { AttendanceExportPage } from './AttendanceExportPage'

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <AttendanceExportPage />
    </QueryClientProvider>,
  )
}

async function addYearMonth(user: ReturnType<typeof userEvent.setup>, yearMonth: string) {
  await pickYearMonth(user, '対象月(複数可)', yearMonth)
  await user.click(screen.getByRole('button', { name: '追加' }))
}

describe('AttendanceExportPage', () => {
  it('disables the download button until a target month is added', () => {
    renderPage()

    expect(screen.getByRole('button', { name: 'CSVダウンロード' })).toBeDisabled()
  })

  it('downloads the CSV for the added year_month when clicked', async () => {
    vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockResolvedValue(undefined)
    const user = userEvent.setup()
    renderPage()

    await addYearMonth(user, '2026-06')
    await user.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    await waitFor(() =>
      expect(exportsApi.downloadAttendanceCsv).toHaveBeenCalledWith({
        year_month: ['2026-06'],
        user_id: undefined,
        format: 'generic',
      }),
    )
  })

  it('accumulates multiple target months as removable chips', async () => {
    const user = userEvent.setup()
    renderPage()

    await addYearMonth(user, '2026-06')
    await addYearMonth(user, '2026-07')

    expect(screen.getByText('2026-06')).toBeInTheDocument()
    expect(screen.getByText('2026-07')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: '2026-06を対象から外す' }))
    expect(screen.queryByText('2026-06')).not.toBeInTheDocument()
    expect(screen.getByText('2026-07')).toBeInTheDocument()
  })

  it('sends multiple selected users alongside multiple months', async () => {
    vi.spyOn(usersApi, 'searchUsers').mockResolvedValue({
      data: [
        { id: 'user-1', name: '社員A', email: 'a@example.com' } as never,
        { id: 'user-2', name: '社員B', email: 'b@example.com' } as never,
      ],
      meta: { current_page: 1, last_page: 1, total: 2 },
      links: { next: null, prev: null },
    })
    vi.spyOn(usersApi, 'fetchUser').mockImplementation((id: string) =>
      Promise.resolve({ id, name: id === 'user-1' ? '社員A' : '社員B', email: `${id}@example.com` } as never),
    )
    vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockResolvedValue(undefined)
    const user = userEvent.setup()
    renderPage()

    await addYearMonth(user, '2026-06')

    await user.click(screen.getByLabelText('対象社員(任意・複数可)'))
    await user.click(await screen.findByText('社員A(a@example.com)'))
    await user.click(screen.getByLabelText('対象社員(任意・複数可)'))
    await user.click(await screen.findByText('社員B(b@example.com)'))

    await user.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    await waitFor(() =>
      expect(exportsApi.downloadAttendanceCsv).toHaveBeenCalledWith({
        year_month: ['2026-06'],
        user_id: ['user-1', 'user-2'],
        format: 'generic',
      }),
    )
  })

  it('shows an error message when the download fails', async () => {
    vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockRejectedValue(new Error('取得に失敗しました'))
    const user = userEvent.setup()
    renderPage()

    await addYearMonth(user, '2026-06')
    await user.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('取得に失敗しました')
  })

  it('downloads the CSV using the selected format', async () => {
    vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockResolvedValue(undefined)
    const user = userEvent.setup()
    renderPage()

    await addYearMonth(user, '2026-06')
    await user.selectOptions(screen.getByLabelText('CSV出力フォーマット'), 'moneyforward')
    await user.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    await waitFor(() =>
      expect(exportsApi.downloadAttendanceCsv).toHaveBeenCalledWith({
        year_month: ['2026-06'],
        user_id: undefined,
        format: 'moneyforward',
      }),
    )
  })

  it('shows the format description and documentation link for the selected format', async () => {
    const user = userEvent.setup()
    renderPage()

    expect(screen.getByText(/どの給与計算ソフトでも列マッピング機能があれば取り込めます/)).toBeInTheDocument()

    await user.selectOptions(screen.getByLabelText('CSV出力フォーマット'), 'freee')

    expect(screen.getByText(/freee人事労務の勤怠データインポート/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'freee人事労務 勤怠データインポート' })).toHaveAttribute(
      'href',
      'https://support.freee.co.jp/hc/ja/articles/204922194',
    )
  })

  it('downloads the Excel file for the added year_months when clicked', async () => {
    vi.spyOn(exportsApi, 'downloadAttendanceExcel').mockResolvedValue(undefined)
    const user = userEvent.setup()
    renderPage()

    await addYearMonth(user, '2026-06')
    await addYearMonth(user, '2026-07')
    await user.click(screen.getByRole('button', { name: 'Excelダウンロード' }))

    await waitFor(() =>
      expect(exportsApi.downloadAttendanceExcel).toHaveBeenCalledWith({
        year_month: ['2026-06', '2026-07'],
        user_id: undefined,
      }),
    )
  })
})
