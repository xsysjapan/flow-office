import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { pickYearMonth } from '../../test-support/pickerInteractions'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import * as exportsApi from '../../api/exports'
import * as usersApi from '../../api/users'
import { AttendanceExportPage } from './AttendanceExportPage'

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/admin/attendance-export']}>
        <AttendanceExportPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function addYearMonth(user: ReturnType<typeof userEvent.setup>, yearMonth: string) {
  await pickYearMonth(user, '対象月(複数可)', yearMonth)
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
    await user.click(screen.getByRole('radio', { name: /^Excel/ }))
    await user.click(screen.getByRole('button', { name: 'Excelダウンロード' }))

    await waitFor(() =>
      expect(exportsApi.downloadAttendanceExcel).toHaveBeenCalledWith({
        year_month: ['2026-06', '2026-07'],
        user_id: undefined,
      }),
    )
  })

  describe('422の項目エラー', () => {
    const SERVER_MESSAGE = 'サーバーの最初のエラー文'
    const HEADING = '入力内容を確認してください。'

    function unprocessable(keys: string[]) {
      return new ApiError(422, SERVER_MESSAGE, Object.fromEntries([...keys, 'unknown_key'].map((key) => [key, [`msg:${key}`]])))
    }

    it('shows each error once under its field when downloading the CSV, leaving unknown keys in the summary', async () => {
      const keys = ['year_month', 'year_month.0', 'user_id', 'user_id.0', 'format']
      vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockRejectedValue(unprocessable(keys))
      const user = userEvent.setup()
      renderPage()

      await addYearMonth(user, '2026-06')
      await user.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

      await screen.findByText(HEADING)
      for (const key of [...keys, 'unknown_key']) expect(screen.getAllByText(`msg:${key}`)).toHaveLength(1)
      expect(screen.queryByText(SERVER_MESSAGE)).not.toBeInTheDocument()
    })

    it('keeps the format error in the summary for the Excel output, which has no format field', async () => {
      const keys = ['year_month', 'year_month.0', 'user_id', 'user_id.0']
      vi.spyOn(exportsApi, 'downloadAttendanceExcel').mockRejectedValue(unprocessable([...keys, 'format']))
      const user = userEvent.setup()
      renderPage()

      await addYearMonth(user, '2026-06')
      await user.click(screen.getByRole('radio', { name: /Excel/ }))
      await user.click(screen.getByRole('button', { name: 'Excelダウンロード' }))

      await screen.findByText(HEADING)
      for (const key of [...keys, 'format', 'unknown_key']) expect(screen.getAllByText(`msg:${key}`)).toHaveLength(1)
      expect(screen.queryByText(SERVER_MESSAGE)).not.toBeInTheDocument()
    })

    it('shows the field error from a real 422 response of the download API under the field', async () => {
      vi.restoreAllMocks()
      vi.stubGlobal(
        'fetch',
        vi.fn().mockImplementation(
          async () =>
            new Response(
              JSON.stringify({ message: SERVER_MESSAGE, errors: { year_month: ['msg:year_month'], other: ['msg:other'] } }),
              { status: 422, headers: { 'content-type': 'application/json' } },
            ),
        ),
      )
      const user = userEvent.setup()
      renderPage()

      await addYearMonth(user, '2026-06')
      await user.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

      await screen.findByText(HEADING)
      expect(screen.getAllByText('msg:year_month')).toHaveLength(1)
      expect(screen.getAllByText('msg:other')).toHaveLength(1)
      expect(screen.queryByText(SERVER_MESSAGE)).not.toBeInTheDocument()
      vi.unstubAllGlobals()
    })
  })
})
