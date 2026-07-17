import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
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

    await userEvent.type(screen.getByLabelText('対象月'), '2026-06')
    await userEvent.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    await waitFor(() =>
      expect(exportsApi.downloadAttendanceCsv).toHaveBeenCalledWith({ year_month: '2026-06', user_id: undefined }),
    )
  })

  it('shows an error message when the download fails', async () => {
    vi.spyOn(exportsApi, 'downloadAttendanceCsv').mockRejectedValue(new Error('取得に失敗しました'))
    renderPage()

    await userEvent.type(screen.getByLabelText('対象月'), '2026-06')
    await userEvent.click(screen.getByRole('button', { name: 'CSVダウンロード' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('取得に失敗しました')
  })
})
