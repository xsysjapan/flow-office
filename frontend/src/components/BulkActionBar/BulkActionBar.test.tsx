import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { BulkActionBar, type BulkAction } from './BulkActionBar'

function actions(count: number, overrides: Partial<BulkAction> = {}): BulkAction[] {
  return Array.from({ length: count }, (_, i) => ({
    key: `a${i + 1}`,
    label: `操作${i + 1}`,
    onClick: vi.fn(),
    ...overrides,
  }))
}

describe('BulkActionBar', () => {
  it('renders nothing when nothing is selected', () => {
    const { container } = render(<BulkActionBar selectedCount={0} onClear={vi.fn()} actions={actions(2)} />)

    expect(container).toBeEmptyDOMElement()
  })

  it('shows the selected count, the actions and the clear button', async () => {
    const user = userEvent.setup()
    const onClear = vi.fn()
    const list = actions(2)
    render(<BulkActionBar selectedCount={3} onClear={onClear} actions={list} />)

    expect(screen.getByText('3件を選択中')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: '操作2' }))
    expect(list[1].onClick).toHaveBeenCalledTimes(1)
    await user.click(screen.getByRole('button', { name: '選択を解除' }))
    expect(onClear).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('button', { name: 'その他' })).not.toBeInTheDocument()
  })

  it('shows disabled actions with their reason next to the buttons', () => {
    render(
      <BulkActionBar
        selectedCount={1}
        onClear={vi.fn()}
        actions={[
          { key: 'done', label: '完了にする', onClick: vi.fn(), disabled: true, disabledReason: '自分のタスクのみ完了にできます' },
        ]}
      />,
    )

    expect(screen.getByRole('button', { name: '完了にする' })).toBeDisabled()
    expect(screen.getByText('自分のタスクのみ完了にできます')).toBeInTheDocument()
  })

  it('does not show the reason of an enabled action', () => {
    render(
      <BulkActionBar
        selectedCount={1}
        onClear={vi.fn()}
        actions={[{ key: 'done', label: '完了にする', onClick: vi.fn(), disabledReason: '理由' }]}
      />,
    )

    expect(screen.queryByText('理由')).not.toBeInTheDocument()
  })

  it('keeps four actions as buttons without a menu', () => {
    render(<BulkActionBar selectedCount={1} onClear={vi.fn()} actions={actions(4)} />)

    expect(screen.getByRole('button', { name: '操作4' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'その他' })).not.toBeInTheDocument()
  })

  it('moves the fourth and later actions into the "その他" menu when there are five or more', async () => {
    const user = userEvent.setup()
    const list = actions(5)
    render(<BulkActionBar selectedCount={1} onClear={vi.fn()} actions={list} />)

    expect(screen.getByRole('button', { name: '操作3' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '操作4' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'その他' }))
    await user.click(await screen.findByRole('menuitem', { name: '操作5' }))

    expect(list[4].onClick).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('button', { name: '操作3' })).toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: '操作4' })).not.toBeInTheDocument()
  })
})
