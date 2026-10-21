import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PaidLeaveGrantRulePreview } from './PaidLeaveGrantRulePreview'

describe('PaidLeaveGrantRulePreview', () => {
  it('renders the anniversary sentence built from the current field values', () => {
    render(
      <PaidLeaveGrantRulePreview
        grantCycleType="anniversary"
        fiscalYearStart={{ month: 4, day: 1 }}
        firstGrantAfterMonths={6}
        grantCycleMonths={12}
        minAttendanceRate={80}
        steps={[{ grant_category: 'normal', continuous_service_months: 6, grant_days: 10 }]}
      />,
    )

    expect(
      screen.getByText('入社日から6か月後に最初の付与。以後12か月ごとに、付与テーブルに沿って日数が増えていきます。出勤率が80%未満の月は付与されません。'),
    ).toBeInTheDocument()
  })

  it('renders the year-start sentence with the default calendar date for mass grant', () => {
    render(
      <PaidLeaveGrantRulePreview
        grantCycleType="mass_grant_month"
        fiscalYearStart={{ month: 10, day: 1 }}
        firstGrantAfterMonths={6}
        grantCycleMonths={12}
        minAttendanceRate={80}
        steps={[{ grant_category: 'normal', continuous_service_months: 6, grant_days: 10 }]}
      />,
    )

    expect(
      screen.getByText(
        '入社後最初の年度開始日(10月1日)と、入社から6か月の早い方に初回を付与。初回が前倒しになるときは、次の段もまとめて付与。以後は毎年の年度開始日。出勤率が80%未満の月は付与されません。',
      ),
    ).toBeInTheDocument()
  })

  it('renders the steps as a horizontal table per category, marking the first-grant column', () => {
    render(
      <PaidLeaveGrantRulePreview
        grantCycleType="anniversary"
        fiscalYearStart={{ month: 4, day: 1 }}
        firstGrantAfterMonths={6}
        grantCycleMonths={12}
        minAttendanceRate={80}
        steps={[
          { grant_category: 'normal', continuous_service_months: 6, grant_days: 10 },
          { grant_category: 'normal', continuous_service_months: 18, grant_days: 11 },
        ]}
      />,
    )

    expect(screen.getByText('6か月')).toBeInTheDocument()
    expect(screen.getByText('1年6か月')).toBeInTheDocument()
    expect(screen.getByText('初回付与はこの列')).toBeInTheDocument()
    expect(screen.getByText('10日')).toBeInTheDocument()
    expect(screen.getByText('11日')).toBeInTheDocument()
  })

  it('shows a labelled table for each proportional category that has steps', () => {
    render(
      <PaidLeaveGrantRulePreview
        grantCycleType="anniversary"
        fiscalYearStart={{ month: 4, day: 1 }}
        firstGrantAfterMonths={6}
        grantCycleMonths={12}
        minAttendanceRate={80}
        steps={[
          { grant_category: 'normal', continuous_service_months: 6, grant_days: 10 },
          { grant_category: 'proportional_3', continuous_service_months: 6, grant_days: 5 },
        ]}
      />,
    )

    expect(screen.getByText('週3日')).toBeInTheDocument()
    expect(screen.getAllByRole('table')).toHaveLength(2)
    expect(screen.getByText('5日')).toBeInTheDocument()
  })

  it('renders no table when there are no steps', () => {
    render(
      <PaidLeaveGrantRulePreview
        grantCycleType="anniversary"
        fiscalYearStart={{ month: 4, day: 1 }}
        firstGrantAfterMonths={6}
        grantCycleMonths={12}
        minAttendanceRate={80}
        steps={[]}
      />,
    )
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(within(document.body).queryByText('通常')).not.toBeInTheDocument()
  })
})
