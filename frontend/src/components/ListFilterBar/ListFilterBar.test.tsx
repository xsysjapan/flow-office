import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ListFilterBar } from './ListFilterBar'

describe('ListFilterBar', () => {
  it('isFiltered=falseではクリアが出ない', () => {
    render(
      <ListFilterBar isFiltered={false} onClear={vi.fn()}>
        <span>項目</span>
      </ListFilterBar>,
    )
    expect(screen.queryByRole('button', { name: '検索条件をクリア' })).not.toBeInTheDocument()
  })

  it('isFiltered=trueでクリアが出て、押すとonClearされる', async () => {
    const onClear = vi.fn()
    render(
      <ListFilterBar isFiltered onClear={onClear}>
        <span>項目</span>
      </ListFilterBar>,
    )
    await userEvent.click(screen.getByRole('button', { name: '検索条件をクリア' }))
    expect(onClear).toHaveBeenCalledTimes(1)
  })

  it('advancedが無ければ詳細条件ボタンが出ない', () => {
    render(
      <ListFilterBar isFiltered={false} onClear={vi.fn()}>
        <span>項目</span>
      </ListFilterBar>,
    )
    expect(screen.queryByRole('button', { name: /詳細条件/ })).not.toBeInTheDocument()
  })

  it('advancedActiveCount=2で「詳細条件(2)」かつ初期展開', () => {
    render(
      <ListFilterBar isFiltered onClear={vi.fn()} advanced={<span>詳細項目</span>} advancedActiveCount={2}>
        <span>項目</span>
      </ListFilterBar>,
    )
    const button = screen.getByRole('button', { name: '詳細条件(2)' })
    expect(button).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByText('詳細項目')).toBeInTheDocument()
  })

  it('advancedActiveCount=0では初期は閉で、押すと展開する', async () => {
    render(
      <ListFilterBar isFiltered={false} onClear={vi.fn()} advanced={<span>詳細項目</span>} advancedActiveCount={0}>
        <span>項目</span>
      </ListFilterBar>,
    )
    const button = screen.getByRole('button', { name: '詳細条件' })
    expect(button).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByText('詳細項目')).not.toBeInTheDocument()
    await userEvent.click(button)
    expect(button).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByText('詳細項目')).toBeInTheDocument()
  })
})
