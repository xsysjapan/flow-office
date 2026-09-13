import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PaidLeaveGrantRulePreview } from './PaidLeaveGrantRulePreview'

describe('PaidLeaveGrantRulePreview', () => {
  it('renders the sentence preview built from the current field values', () => {
    render(
      <PaidLeaveGrantRulePreview
        firstGrantAfterMonths={6}
        grantCycleMonths={12}
        minAttendanceRate={80}
        steps={[{ continuous_service_months: 6, grant_days: 10 }]}
      />,
    )

    expect(
      screen.getByText('入社日から6か月後に最初の付与。以後12か月ごとに、付与テーブルに沿って日数が増えていきます。出勤率が80%未満の月は付与されません。'),
    ).toBeInTheDocument()
  })

  it('renders the steps as a horizontal table keyed by elapsed time, marking the first-grant column', () => {
    render(
      <PaidLeaveGrantRulePreview
        firstGrantAfterMonths={6}
        grantCycleMonths={12}
        minAttendanceRate={80}
        steps={[
          { continuous_service_months: 6, grant_days: 10 },
          { continuous_service_months: 18, grant_days: 11 },
        ]}
      />,
    )

    expect(screen.getByText('6か月')).toBeInTheDocument()
    expect(screen.getByText('1年6か月')).toBeInTheDocument()
    expect(screen.getByText('初回付与はこの列')).toBeInTheDocument()
    expect(screen.getByText('10日')).toBeInTheDocument()
    expect(screen.getByText('11日')).toBeInTheDocument()
  })

  it('renders nothing extra when there are no steps', () => {
    render(<PaidLeaveGrantRulePreview firstGrantAfterMonths={6} grantCycleMonths={12} minAttendanceRate={80} steps={[]} />)
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})
