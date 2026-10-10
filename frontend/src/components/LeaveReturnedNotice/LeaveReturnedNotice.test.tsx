import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { LeaveReturnedNotice } from './LeaveReturnedNotice'

function renderNotice(workflowRequestId: string | null) {
  return render(
    <MemoryRouter>
      <LeaveReturnedNotice workflowRequestId={workflowRequestId} />
    </MemoryRouter>,
  )
}

describe('LeaveReturnedNotice', () => {
  it('explains that the request was returned and can be resubmitted from the request detail', () => {
    renderNotice('workflow-request-1')

    expect(screen.getByText('差し戻されています。申請詳細の「提出する」で再提出できます。')).toBeInTheDocument()
  })

  it('links directly to the request detail of the workflow request', () => {
    renderNotice('workflow-request-1')

    expect(screen.getByRole('link', { name: '申請詳細を開く' })).toHaveAttribute('href', '/requests/workflow-request-1')
  })

  it('does not show the link when there is no workflow request', () => {
    renderNotice(null)

    expect(screen.getByText('差し戻されています。申請詳細の「提出する」で再提出できます。')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: '申請詳細を開く' })).not.toBeInTheDocument()
  })
})
