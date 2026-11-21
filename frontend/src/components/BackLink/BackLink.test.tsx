import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { BackLink, type BackLinkProps } from './BackLink'

function renderAt(entry: string | { pathname: string; state?: unknown }, props: BackLinkProps = {}) {
  return render(
    <MemoryRouter initialEntries={[entry]}>
      <BackLink {...props} />
    </MemoryRouter>,
  )
}

describe('BackLink', () => {
  it('links to the hierarchy parent with the 「{label}に戻る」 text when there is no origin', () => {
    renderAt('/paid-leave/history')

    expect(screen.getByRole('link', { name: '有給に戻る' })).toHaveAttribute('href', '/paid-leave')
  })

  it('links to the recorded origin including its query', () => {
    renderAt({
      pathname: '/requests/abc',
      state: { backTo: { pathname: '/requests', search: '?status=pending&page=2', hash: '' } },
    })

    expect(screen.getByRole('link', { name: '申請一覧に戻る' })).toHaveAttribute(
      'href',
      '/requests?status=pending&page=2',
    )
  })

  it('uses the label and to overrides', () => {
    renderAt('/admin/work-calendars/c1/years/2026/days', { label: '標準カレンダー' })
    expect(screen.getByRole('link', { name: '標準カレンダーに戻る' })).toHaveAttribute('href', '/admin/work-calendars/c1')
  })

  it('uses the to override', () => {
    renderAt('/paid-leave/history', { to: '/approvals' })

    expect(screen.getByRole('link', { name: '承認待ちに戻る' })).toHaveAttribute('href', '/approvals')
  })

  it('renders nothing for a first-level page without an origin', () => {
    const { container } = renderAt('/paid-leave')

    expect(container).toBeEmptyDOMElement()
  })

  it('renders nothing for an unregistered page without an origin', () => {
    const { container } = renderAt('/unknown')

    expect(container).toBeEmptyDOMElement()
  })
})
