import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { LeaveReturnedNotice } from './LeaveReturnedNotice'

function renderNotice(subjectType: Parameters<typeof LeaveReturnedNotice>[0]['subjectType']) {
  return render(
    <MemoryRouter>
      <LeaveReturnedNotice subjectType={subjectType} />
    </MemoryRouter>,
  )
}

describe('LeaveReturnedNotice', () => {
  it('explains that the request was returned and can be resubmitted from the request detail', () => {
    renderNotice('paid_leave_request')

    expect(screen.getByText('差し戻されています。申請詳細の「提出する」で再提出できます。')).toBeInTheDocument()
  })

  it('links to the returned requests of the given leave type', () => {
    renderNotice('special_leave_request')

    expect(screen.getByRole('link', { name: '差戻し中の申請を開く' })).toHaveAttribute(
      'href',
      '/requests?status=returned&subjectType=special_leave_request',
    )
  })

  it('links the compensatory leave type to its own subject type', () => {
    renderNotice('compensatory_leave_request')

    expect(screen.getByRole('link', { name: '差戻し中の申請を開く' })).toHaveAttribute(
      'href',
      '/requests?status=returned&subjectType=compensatory_leave_request',
    )
  })
})
