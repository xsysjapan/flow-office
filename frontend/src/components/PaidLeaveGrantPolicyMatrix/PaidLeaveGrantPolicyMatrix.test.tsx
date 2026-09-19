import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { PaidLeaveGrantPolicies } from '../../api/types'
import { PaidLeaveGrantPolicyMatrix } from './PaidLeaveGrantPolicyMatrix'

const policies: PaidLeaveGrantPolicies = {
  version: 'v1',
  normal: [
    { continuous_service_months: 6, grant_days: 10 },
    { continuous_service_months: 18, grant_days: 11 },
  ],
  proportional_version: 'v1',
  proportional: [
    { weekly_scheduled_days_category: '4', continuous_service_months: 6, grant_days: 7 },
    { weekly_scheduled_days_category: '3', continuous_service_months: 6, grant_days: 5 },
  ],
}

describe('PaidLeaveGrantPolicyMatrix', () => {
  it('renders the normal and proportional grant tables without a toggle', () => {
    render(<PaidLeaveGrantPolicyMatrix policies={policies} />)

    expect(screen.getByText('通常付与(週所定労働日数5日以上、週所定労働時間30時間以上、または年間所定労働日数217日以上)')).toBeInTheDocument()
    expect(screen.getByText('比例付与(週所定労働日数4日以下かつ週所定労働時間30時間未満)')).toBeInTheDocument()
    expect(screen.getByText('週4日')).toBeInTheDocument()
    expect(screen.getByText('週3日')).toBeInTheDocument()
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
    expect(screen.queryByRole('switch')).not.toBeInTheDocument()
  })
})
