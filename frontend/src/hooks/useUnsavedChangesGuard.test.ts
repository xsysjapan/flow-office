import { renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { useUnsavedChangesGuard } from './useUnsavedChangesGuard'

function dispatchBeforeUnload() {
  const event = new Event('beforeunload', { cancelable: true }) as BeforeUnloadEvent
  window.dispatchEvent(event)
  return event
}

describe('useUnsavedChangesGuard', () => {
  it('does not prevent the default beforeunload behavior while inactive', () => {
    renderHook(() => useUnsavedChangesGuard(false))

    const event = dispatchBeforeUnload()

    expect(event.defaultPrevented).toBe(false)
  })

  it('prevents the default beforeunload behavior while active', () => {
    renderHook(() => useUnsavedChangesGuard(true))

    const event = dispatchBeforeUnload()

    expect(event.defaultPrevented).toBe(true)
  })

  it('stops guarding once it becomes inactive again', () => {
    const { rerender } = renderHook(({ active }) => useUnsavedChangesGuard(active), {
      initialProps: { active: true },
    })

    rerender({ active: false })

    const event = dispatchBeforeUnload()

    expect(event.defaultPrevented).toBe(false)
  })

  it('removes its listener on unmount', () => {
    const removeSpy = vi.spyOn(window, 'removeEventListener')
    const { unmount } = renderHook(() => useUnsavedChangesGuard(true))

    unmount()

    expect(removeSpy).toHaveBeenCalledWith('beforeunload', expect.any(Function))
    removeSpy.mockRestore()
  })
})
