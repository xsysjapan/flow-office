import { render, renderHook, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { useUnsavedChangesGuard } from './useUnsavedChangesGuard'

function Page() {
  const guard = useUnsavedChangesGuard(true)
  return <button onClick={() => guard.navigateAfterSave('/next')}>go</button>
}

describe('useUnsavedChangesGuard without a Provider (MemoryRouter)', () => {
  it('does not throw; beforeunload still works; confirmDiscard is true; navigateAfterSave navigates', async () => {
    const { result } = renderHook(() => useUnsavedChangesGuard(true), { wrapper: MemoryRouter })
    const event = new Event('beforeunload', { cancelable: true }) as BeforeUnloadEvent
    window.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(true)
    await expect(result.current.confirmDiscard()).resolves.toBe(true)

    render(
      <MemoryRouter initialEntries={['/']}>
        <Routes>
          <Route path="/" element={<Page />} />
          <Route path="/next" element={<p>次</p>} />
        </Routes>
      </MemoryRouter>,
    )
    await userEvent.setup().click(screen.getByRole('button', { name: 'go' }))
    expect(screen.getByText('次')).toBeInTheDocument()
  })
})
