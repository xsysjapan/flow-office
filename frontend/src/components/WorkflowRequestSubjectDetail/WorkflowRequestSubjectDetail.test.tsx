import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { WorkflowRequest } from '../../api/types'
import { WorkflowRequestSubjectDetail } from './WorkflowRequestSubjectDetail'

const applicant = {
  id: 'applicant-1',
  name: '申請者太郎',
  email: 'taro@example.com',
  department: null,
  job_title: null,
  employment_status: 'active',
  last_login_at: null,
}

const baseRequest: WorkflowRequest = {
  id: 'workflow-request-1',
  title: '有給休暇申請',
  status: 'submitted',
  form_data: {},
  applicant,
  submitted_at: '2026-07-01T00:00:00+09:00',
  approved_at: null,
  returned_at: null,
  cancelled_at: null,
  created_at: '2026-07-01T00:00:00+09:00',
}

describe('WorkflowRequestSubjectDetail', () => {
  it('renders the paid leave subject detail (target date, days, reason)', () => {
    render(
      <WorkflowRequestSubjectDetail
        request={{
          ...baseRequest,
          subject_type: 'paid_leave_request',
          subject: {
            type: 'paid_leave_request',
            id: 'paid-leave-1',
            user_id: 'applicant-1',
            status: 'submitted',
            target_date: '2026-08-10',
            leave_type: 'full',
            leave_type_label: '全休',
            hours: null,
            requested_days: 1,
            reason: '私用のため',
            submitted_at: '2026-08-01T00:00:00+09:00',
            approved_at: null,
            returned_at: null,
            cancelled_at: null,
            request_group_dates: null,
            used_days_last_year: 3,
            pending_days_last_year: 1,
            approved_days_last_year: 2,
          },
        }}
      />,
    )

    expect(screen.getByText('2026-08-10')).toBeInTheDocument()
    expect(screen.getByText('全休')).toBeInTheDocument()
    expect(screen.getByText('1日')).toBeInTheDocument()
    expect(screen.getByText('私用のため')).toBeInTheDocument()
  })

  it('renders the expense claim subject detail (total amount, line items)', () => {
    render(
      <WorkflowRequestSubjectDetail
        request={{
          ...baseRequest,
          subject_type: 'expense_claim',
          subject: {
            type: 'expense_claim',
            id: 'expense-claim-1',
            employee_id: 'applicant-1',
            title: '7月分の立替経費',
            status: 'in_review',
            total_amount: 3000,
            period_from: '2026-07-01',
            period_to: '2026-07-31',
            submitted_at: '2026-08-01T00:00:00+09:00',
            approved_at: null,
            items: [
              {
                id: 'item-1',
                category_id: 1,
                category_name: 'タクシー代',
                usage_date: '2026-07-10',
                description: '来客対応',
                amount: 1000,
                commuting_deduction_amount: null,
                reimbursement_amount: 1000,
                payment_bearer: 'employee',
              },
            ],
          },
        }}
      />,
    )

    expect(screen.getByText('3,000円')).toBeInTheDocument()
    expect(screen.getByText('来客対応')).toBeInTheDocument()
  })

  it('renders nothing when the request has no subject_type', () => {
    const { container } = render(<WorkflowRequestSubjectDetail request={{ ...baseRequest, subject_type: null }} />)

    expect(container).toBeEmptyDOMElement()
  })
})
