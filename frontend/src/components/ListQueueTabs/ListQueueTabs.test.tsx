import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ListQueueTabs } from './ListQueueTabs'

const tabs = [
  { value: 'submitted', label: '承認待ち', count: 12 },
  { value: 'approved', label: '承認済み', count: 30 },
]

describe('ListQueueTabs', () => {
  it('ラベルと件数を表示する', () => {
    render(<ListQueueTabs tabs={tabs} value="submitted" onChange={vi.fn()} aria-label="承認状態" />)
    expect(screen.getByRole('tablist', { name: '承認状態' })).toBeInTheDocument()
    expect(screen.getByText('12')).toBeInTheDocument()
    expect(screen.getByText('30')).toBeInTheDocument()
  })

  it('aria-labelに件数を含める', () => {
    render(<ListQueueTabs tabs={tabs} value="submitted" onChange={vi.fn()} aria-label="承認状態" />)
    expect(screen.getByRole('tab', { name: '承認待ち 12件' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tab', { name: '承認済み 30件' })).toBeInTheDocument()
  })

  it('件数が無ければラベルだけで表示する', () => {
    render(
      <ListQueueTabs tabs={[{ value: 'all', label: 'すべて' }]} value="all" onChange={vi.fn()} aria-label="状態" />,
    )
    expect(screen.getByRole('tab', { name: 'すべて' })).toBeInTheDocument()
  })

  it('クリックでonChangeする', async () => {
    const onChange = vi.fn()
    render(<ListQueueTabs tabs={tabs} value="submitted" onChange={onChange} aria-label="承認状態" />)
    await userEvent.click(screen.getByRole('tab', { name: '承認済み 30件' }))
    expect(onChange).toHaveBeenCalledWith('approved')
  })
})
