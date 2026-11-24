import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { PageHeader } from './PageHeader'

function renderAt(path: string, ui: React.ReactElement) {
  return render(<MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>)
}

describe('PageHeader', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('names the region by the heading and shows the description', () => {
    renderAt('/admin/users', <PageHeader title="ユーザー" description="説明です。" />)
    expect(screen.getByRole('region', { name: 'ユーザー' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: 'ユーザー' })).toBeInTheDocument()
    expect(screen.getByText('説明です。')).toBeInTheDocument()
  })

  it('resolves the title from pageHierarchy when omitted', () => {
    renderAt('/admin/group-types', <PageHeader description="説明です。" />)
    expect(screen.getByRole('heading', { level: 1, name: 'グループ種別' })).toBeInTheDocument()
  })

  it('resolves a dynamic title', () => {
    renderAt(
      '/admin/work-calendars/1/years/2026/days',
      <PageHeader description="説明です。" />,
    )
    expect(screen.getByRole('heading', { level: 1, name: '2026年度の日別設定' })).toBeInTheDocument()
  })

  it('omits the heading and warns when the route is unknown', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    renderAt('/unknown/path', <PageHeader description="説明です。" />)
    expect(screen.queryByRole('heading')).not.toBeInTheDocument()
    expect(screen.getByText('説明です。')).toBeInTheDocument()
    expect(warn).toHaveBeenCalled()
  })

  it('renders actions and meta', () => {
    renderAt(
      '/admin/users',
      <PageHeader
        title="ユーザー"
        description="説明です。"
        meta={<span>デフォルト</span>}
        actions={<button type="button">新規作成</button>}
      />,
    )
    expect(screen.getByText('デフォルト')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: '新規作成' })).toBeInTheDocument()
  })

  it('does not render the actions area when omitted', () => {
    renderAt('/admin/users', <PageHeader title="ユーザー" description="説明です。" />)
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })
})
