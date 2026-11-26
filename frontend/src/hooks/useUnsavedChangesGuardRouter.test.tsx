import { act, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { Link, Route, Routes, useLocation } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { renderWithGuard } from '../test/renderWithGuard'
import {
  useUnsavedChangesGuard,
  type UnsavedChangesGuard,
  type UnsavedChangesGuardOptions,
} from './useUnsavedChangesGuard'

let guardRef: UnsavedChangesGuard | null = null

function Form({ options, initialDirty = false }: { options?: UnsavedChangesGuardOptions; initialDirty?: boolean }) {
  const [value, setValue] = useState(initialDirty ? 'x' : '')
  const guard = useUnsavedChangesGuard(value !== '', options)
  guardRef = guard
  return (
    <div>
      <label>
        入力
        <input value={value} onChange={(e) => setValue(e.target.value)} />
      </label>
      <button
        onClick={() => {
          setValue('')
          guard.navigateAfterSave('/other')
        }}
      >
        保存して移る
      </button>
      <Link to="/other">別ページ</Link>
      <Link to="/form?tab=2">クエリだけ</Link>
      <Link to="/form?roleId=2">ロール2</Link>
    </div>
  )
}

function Location() {
  const l = useLocation()
  return <p data-testid="loc">{l.pathname + l.search}</p>
}

function renderApp(options?: UnsavedChangesGuardOptions) {
  return renderWithGuard(
    <>
      <Location />
      <Routes>
        <Route path="/form" element={<Form options={options} />} />
        <Route path="/other" element={<p>移動先</p>} />
      </Routes>
    </>,
    { initialEntries: ['/form'] },
  )
}

function dispatchBeforeUnload() {
  const event = new Event('beforeunload', { cancelable: true }) as BeforeUnloadEvent
  window.dispatchEvent(event)
  return event
}

describe('UnsavedChangesProvider + useUnsavedChangesGuard', () => {
  it('blocks a Link when dirty and shows the dialog; leaving proceeds', async () => {
    const user = userEvent.setup()
    renderApp()
    await user.type(screen.getByLabelText('入力'), 'abc')
    await user.click(screen.getByRole('link', { name: '別ページ' }))

    expect(await screen.findByText('保存していない変更があります')).toBeInTheDocument()
    expect(screen.getByText('このページを離れると、入力した内容は失われます。')).toBeInTheDocument()
    expect(screen.getByTestId('loc')).toHaveTextContent('/form')

    await user.click(screen.getByRole('button', { name: 'ページを離れる' }))
    expect(await screen.findByText('移動先')).toBeInTheDocument()
  })

  it('stays and keeps the input when 編集を続ける is chosen', async () => {
    const user = userEvent.setup()
    renderApp()
    await user.type(screen.getByLabelText('入力'), 'abc')
    await user.click(screen.getByRole('link', { name: '別ページ' }))
    await user.click(await screen.findByRole('button', { name: '編集を続ける' }))

    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
    expect(screen.getByTestId('loc')).toHaveTextContent('/form')
    expect(screen.getByLabelText('入力')).toHaveValue('abc')
  })

  it('does not block when there are no changes', async () => {
    const user = userEvent.setup()
    renderApp()
    await user.click(screen.getByRole('link', { name: '別ページ' }))

    expect(await screen.findByText('移動先')).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })

  it('navigateAfterSave is not blocked even when called right after setState while still active', async () => {
    const user = userEvent.setup()
    renderApp()
    await user.type(screen.getByLabelText('入力'), 'abc')
    await user.click(screen.getByRole('button', { name: '保存して移る' }))

    expect(await screen.findByText('移動先')).toBeInTheDocument()
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })

  it('does not leave the allow flag set when navigate throws inside navigateAfterSave', async () => {
    const user = userEvent.setup()
    renderApp()
    await user.type(screen.getByLabelText('入力'), 'abc')

    expect(() =>
      act(() => {
        guardRef!.navigateAfterSave({
          get pathname(): string {
            throw new Error('boom')
          },
        })
      }),
    ).toThrow()
    // 例外のあとでも次のLinkは止まる(フラグが残っていない)
    await user.click(screen.getByRole('link', { name: '別ページ' }))
    expect(await screen.findByText('保存していない変更があります')).toBeInTheDocument()
  })

  it('by default does not block query-only navigation', async () => {
    const user = userEvent.setup()
    renderApp()
    await user.type(screen.getByLabelText('入力'), 'abc')
    await user.click(screen.getByRole('link', { name: 'クエリだけ' }))

    expect(screen.getByTestId('loc')).toHaveTextContent('/form?tab=2')
    expect(screen.queryByText('保存していない変更があります')).not.toBeInTheDocument()
  })

  it("blocks query-only navigation with blockOn 'location'", async () => {
    const user = userEvent.setup()
    renderApp({ blockOn: 'location' })
    await user.type(screen.getByLabelText('入力'), 'abc')
    await user.click(screen.getByRole('link', { name: 'クエリだけ' }))

    expect(await screen.findByText('保存していない変更があります')).toBeInTheDocument()
    expect(screen.getByTestId('loc')).toHaveTextContent('/form')
  })

  it('blockOn function decides (roleId changes only)', async () => {
    const user = userEvent.setup()
    renderApp({
      blockOn: (c, n) => new URLSearchParams(c.search).get('roleId') !== new URLSearchParams(n.search).get('roleId'),
    })
    await user.type(screen.getByLabelText('入力'), 'abc')
    await user.click(screen.getByRole('link', { name: 'クエリだけ' }))
    expect(screen.getByTestId('loc')).toHaveTextContent('/form?tab=2')

    await user.click(screen.getByRole('link', { name: 'ロール2' }))
    expect(await screen.findByText('保存していない変更があります')).toBeInTheDocument()
  })

  it('confirmDiscard: confirm resolves true, cancel resolves false, no changes resolves true immediately', async () => {
    const user = userEvent.setup()
    renderApp()
    // 変更なし
    await expect(guardRef!.confirmDiscard()).resolves.toBe(true)

    await user.type(screen.getByLabelText('入力'), 'abc')
    let result: Promise<boolean> = Promise.resolve(false)
    act(() => {
      result = guardRef!.confirmDiscard()
    })
    expect(await screen.findByText('編集をやめると、入力した内容は失われます。')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: '編集をやめる' }))
    await expect(result).resolves.toBe(true)

    act(() => {
      result = guardRef!.confirmDiscard()
    })
    await user.click(await screen.findByRole('button', { name: '編集を続ける' }))
    await expect(result).resolves.toBe(false)
  })

  it('beforeunload does nothing while leaveWithoutGuard runs, and prevents otherwise', async () => {
    const user = userEvent.setup()
    renderApp()
    await user.type(screen.getByLabelText('入力'), 'abc')
    expect(dispatchBeforeUnload().defaultPrevented).toBe(true)

    const fn = vi.fn(() => {
      expect(dispatchBeforeUnload().defaultPrevented).toBe(false)
    })
    act(() => guardRef!.leaveWithoutGuard(fn))
    expect(fn).toHaveBeenCalled()
  })

  it('resets the flag when leaveWithoutGuard fn throws', async () => {
    const user = userEvent.setup()
    renderApp()
    await user.type(screen.getByLabelText('入力'), 'abc')
    expect(() =>
      act(() =>
        guardRef!.leaveWithoutGuard(() => {
          throw new Error('x')
        }),
      ),
    ).toThrow('x')
    expect(dispatchBeforeUnload().defaultPrevented).toBe(true)
  })
})
