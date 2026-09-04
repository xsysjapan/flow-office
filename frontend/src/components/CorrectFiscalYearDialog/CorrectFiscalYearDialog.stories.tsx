import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState } from 'react'
import type { Meta, StoryObj } from '@storybook/react-vite'
import type { WorkCalendarYear } from '../../api/types'
import { Button } from '../Button/Button'
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

function Demo() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const [target, setTarget] = useState<WorkCalendarYear | null>(null)

  return (
    <QueryClientProvider client={queryClient}>
      <Button variant="secondary" onClick={() => setTarget(year)}>
        年度を訂正
      </Button>
      <CorrectFiscalYearDialog
        year={target}
        companyCalendarId="calendar-1"
        onOpenChange={(open) => !open && setTarget(null)}
        onCorrected={() => setTarget(null)}
      />
    </QueryClientProvider>
  )
}

const meta = {
  title: 'Components/CorrectFiscalYearDialog',
  component: CorrectFiscalYearDialog,
} satisfies Meta<typeof CorrectFiscalYearDialog>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  args: { year: null, companyCalendarId: 'calendar-1', onOpenChange: () => {} },
  render: () => <Demo />,
}
