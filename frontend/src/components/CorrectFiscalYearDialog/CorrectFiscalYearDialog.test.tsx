import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import * as workCalendarsApi from '../../api/workCalendars'
import { ApiError } from '../../api/client'
import type { WorkCalendarYear } from '../../api/types'
import { CorrectFiscalYearDialog } from './CorrectFiscalYearDialog'

const year: WorkCalendarYear = {
  id: 'year-1',
  company_calendar_id: 'calendar-1',
  fiscal_year: 2026,
  starts_on: '2026-04-01',
  ends_on: '2027-03-31',
  status: 'published',
  generated_from: 'manual',
  published_at: '2026-01-01T00:00:00+09:00',
  published_by_user_id: 'user-1',
}

function renderDialog(target: WorkCalendarYear | null, overrides: { onOpenChange?: () => void; onCorrected?: () => void } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const onOpenChange = overrides.onOpenChange ?? vi.fn()
  const onCorrected = overrides.onCorrected ?? vi.fn()

  render(
    <QueryClientProvider client={queryClient}>
      <CorrectFiscalYearDialog year={target} companyCalendarId="calendar-1" onOpenChange={onOpenChange} onCorrected={onCorrected} />
    </QueryClientProvider>,
  )

  return { onOpenChange, onCorrected }
}

describe('CorrectFiscalYearDialog', () => {
  it('is closed when year is null', () => {
    renderDialog(null)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('prefills the current fiscal year and dates', () => {
    renderDialog(year)
    expect(screen.getByLabelText('年度番号')).toHaveValue(2026)
    expect(screen.getByLabelText('開始日')).toHaveValue('2026-04-01')
    expect(screen.getByLabelText('終了日')).toHaveValue('2027-03-31')
  })

  it('submits the corrected fiscal year and reports completion', async () => {
    vi.spyOn(workCalendarsApi, 'correctWorkCalendarYearFiscalYear').mockResolvedValue({
      ...year,
      fiscal_year: 2027,
    })
    const { onCorrected } = renderDialog(year)

    await userEvent.clear(screen.getByLabelText('年度番号'))
    await userEvent.type(screen.getByLabelText('年度番号'), '2027')
    await userEvent.type(screen.getByLabelText('訂正理由(任意)'), '公開時の入力ミス')

    await userEvent.click(screen.getByRole('button', { name: '訂正する' }))

    await waitFor(() =>
      expect(workCalendarsApi.correctWorkCalendarYearFiscalYear).toHaveBeenCalledWith('year-1', {
        fiscal_year: 2027,
        starts_on: '2026-04-01',
        ends_on: '2027-03-31',
        reason: '公開時の入力ミス',
      }),
    )
    await waitFor(() => expect(onCorrected).toHaveBeenCalled())
  })

  it('shows a field error for a duplicate fiscal year without closing the dialog', async () => {
    vi.spyOn(workCalendarsApi, 'correctWorkCalendarYearFiscalYear').mockRejectedValue(
      new ApiError(422, '入力内容に誤りがあります。', { fiscal_year: ['この年度番号は既に使用されています。'] }),
    )
    renderDialog(year)

    await userEvent.click(screen.getByRole('button', { name: '訂正する' }))

    expect(await screen.findByText('この年度番号は既に使用されています。')).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('calls onOpenChange(false) when キャンセル is clicked', async () => {
    const { onOpenChange } = renderDialog(year)

    await userEvent.click(screen.getByRole('button', { name: 'キャンセル' }))

    expect(onOpenChange).toHaveBeenCalledWith(false)
  })
})
